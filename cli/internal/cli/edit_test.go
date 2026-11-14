package cli

import (
	"errors"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/schorsch3000/Linkrot/internal/config"
)

func TestSplitCommand(t *testing.T) {
	for in, want := range map[string][]string{
		"vim":                            {"vim"},
		"code --wait":                    {"code", "--wait"},
		`  emacsclient   -t  `:           {"emacsclient", "-t"},
		`my\ editor -f`:                  {"my editor", "-f"},
		`"/opt/my editor/bin" --x "a b"`: {"/opt/my editor/bin", "--x", "a b"},
		`sh -c 'vim "$1"'`:               {"sh", "-c", `vim "$1"`},
		`a "" b`:                         {"a", "", "b"},
		"":                               nil,
	} {
		got, err := splitCommand(in)
		if err != nil || !reflect.DeepEqual(got, want) {
			t.Errorf("splitCommand(%q) = %q, %v; want %q", in, got, err, want)
		}
	}
	for _, bad := range []string{`vim "unclosed`, `vim 'unclosed`} {
		if _, err := splitCommand(bad); err == nil {
			t.Errorf("splitCommand(%q) accepted", bad)
		}
	}
}

func TestResolveEditorOrder(t *testing.T) {
	env := func(m map[string]string) func(string) string { return func(k string) string { return m[k] } }
	installed := func(names ...string) func(string) (string, error) {
		return func(n string) (string, error) {
			for _, have := range names {
				if have == n {
					return "/usr/bin/" + n, nil
				}
			}
			return "", errors.New("not found")
		}
	}
	for name, tc := range map[string]struct {
		given []string
		env   map[string]string
		have  []string
		goos  string
		want  []string
	}{
		"given wins over everything":     {[]string{"fancy", "-f"}, map[string]string{"VISUAL": "v", "EDITOR": "e"}, []string{"vim"}, "linux", []string{"fancy", "-f"}},
		"VISUAL before EDITOR":           {nil, map[string]string{"VISUAL": "v", "EDITOR": "e"}, []string{"vim"}, "linux", []string{"v"}},
		"EDITOR when there is no VISUAL": {nil, map[string]string{"EDITOR": "code --wait"}, nil, "linux", []string{"code", "--wait"}},
		"blank VISUAL is not set":        {nil, map[string]string{"VISUAL": "  ", "EDITOR": "e"}, nil, "linux", []string{"e"}},
		"vim first":                      {nil, nil, []string{"pico", "nano", "vi", "vim"}, "linux", []string{"vim"}},
		"then vi":                        {nil, nil, []string{"pico", "nano", "vi"}, "linux", []string{"vi"}},
		"then nano":                      {nil, nil, []string{"pico", "nano"}, "linux", []string{"nano"}},
		"then pico":                      {nil, nil, []string{"pico"}, "linux", []string{"pico"}},
		"notepad on windows":             {nil, nil, []string{"notepad", "vim"}, "windows", []string{"notepad"}},
	} {
		got, err := resolveEditor(tc.given, env(tc.env), installed(tc.have...), tc.goos)
		if err != nil || !reflect.DeepEqual(got, tc.want) {
			t.Errorf("%s: %q, %v; want %q", name, got, err, tc.want)
		}
	}
	if _, err := resolveEditor(nil, env(nil), installed(), "linux"); err == nil || !strings.Contains(err.Error(), "no editor found") {
		t.Errorf("without any editor: %v", err)
	}
	if _, err := resolveEditor(nil, env(map[string]string{"VISUAL": `vim "x`}), installed(), "linux"); err == nil || !strings.Contains(err.Error(), "$VISUAL") {
		t.Errorf("bad $VISUAL: %v", err)
	}
}

// lockedBuffer is a bytes.Buffer that the session and the test may use at the same time.
type lockedBuffer struct {
	mu sync.Mutex
	b  strings.Builder
}

func (l *lockedBuffer) Write(p []byte) (int, error) {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.b.Write(p)
}

func (l *lockedBuffer) String() string {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.b.String()
}

// box is a value that the editor (a goroutine of the session) sets and the test reads.
type box[T any] struct {
	mu sync.Mutex
	v  T
}

func (b *box[T]) set(v T) { b.mu.Lock(); b.v = v; b.mu.Unlock() }
func (b *box[T]) get() T  { b.mu.Lock(); defer b.mu.Unlock(); return b.v }

// session runs an edit command in the background with an editor that is a function of the test.
type session struct {
	t      *testing.T
	e      *env
	errb   *lockedBuffer
	sigs   chan os.Signal
	enter  *io.PipeWriter
	code   chan int
	editor func(call int, argv []string) error
	calls  int
	mu     sync.Mutex
}

func (e *env) startEdit(editor func(call int, argv []string) error, args ...string) *session {
	e.t.Helper()
	pr, pw := io.Pipe()
	s := &session{t: e.t, e: e, errb: &lockedBuffer{}, sigs: make(chan os.Signal, 4), enter: pw, code: make(chan int, 1), editor: editor}
	app := &App{
		Name: "lr", Out: &lockedBuffer{}, Err: s.errb, In: pr, ConfigPath: config.Path,
		Now:         func() time.Time { return time.Unix(2000, 0) },
		Getenv:      func(string) string { return "" },
		EditPoll:    5 * time.Millisecond,
		EditSignals: s.sigs,
		RunEditor: func(argv []string, _ io.Reader, _, _ io.Writer) error {
			s.mu.Lock()
			s.calls++
			n := s.calls
			s.mu.Unlock()
			return s.editor(n, argv)
		},
	}
	go func() { s.code <- app.Run(args) }()
	e.t.Cleanup(func() { _ = pw.Close() })
	return s
}

func (s *session) stop() int {
	s.t.Helper()
	s.sigs <- os.Interrupt
	select {
	case code := <-s.code:
		return code
	case <-time.After(5 * time.Second):
		s.t.Fatalf("the session did not end; output:\n%s", s.errb.String())
		return -1
	}
}

func (s *session) waitFor(what string, ok func() bool) {
	s.t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !ok() {
		if time.Now().After(deadline) {
			s.t.Fatalf("timed out waiting for %s; output:\n%s", what, s.errb.String())
		}
		time.Sleep(2 * time.Millisecond)
	}
}

func (s *session) pressEnter() { _, _ = s.enter.Write([]byte("\n")) }

func overwrite(t *testing.T, argv []string, content string) {
	t.Helper()
	if err := os.WriteFile(argv[len(argv)-1], []byte(content), 0o600); err != nil {
		t.Error(err)
	}
}

func TestEditPageUploadsWhenSavedAndStopsOnCtrlC(t *testing.T) {
	e := newEnv(t)
	e.server.Pages = map[string]string{"imprint": "old\n"}
	var pathBox box[string]
	s := e.startEdit(func(_ int, argv []string) error {
		path := argv[len(argv)-1]
		pathBox.set(path)
		if got, _ := os.ReadFile(path); string(got) != "old\n" {
			t.Errorf("the editor got %q", got)
		}
		overwrite(t, argv, "---\ntitle: Imprint\n---\nnew\n")
		return nil
	}, "page", "edit", "imprint", "myeditor", "--fancy", "-f")

	s.waitFor("the upload", func() bool { got, _ := e.server.PageText("imprint"); return got == "---\ntitle: Imprint\n---\nnew\n" })
	s.waitFor("the message", func() bool { return strings.Contains(s.errb.String(), "uploaded: imprint is published") })
	if code := s.stop(); code != 0 {
		t.Fatalf("code %d:\n%s", code, s.errb.String())
	}
	out := s.errb.String()
	for _, want := range []string{"editing page imprint in myeditor --fancy -f", "Press Enter to open the editor again, Ctrl+C to stop", "stopped, nothing left to upload", "/page/imprint"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	path := pathBox.get()
	if filepath.Base(path) != "imprint.md" {
		t.Errorf("temp file %q", path)
	}
	if _, err := os.Stat(filepath.Dir(path)); !os.IsNotExist(err) {
		t.Errorf("the temp dir is still there: %v", err)
	}
}

func TestEditorIsCalledWithItsArgumentsAndTheFileLast(t *testing.T) {
	e := newEnv(t)
	var gotBox box[[]string]
	s := e.startEdit(func(_ int, argv []string) error { gotBox.set(argv); return nil }, "custom", "edit", "css", "/my/editor", "--fancy", "-f")
	s.waitFor("the editor", func() bool { return gotBox.get() != nil })
	s.stop()
	got := gotBox.get()
	if len(got) != 4 || !reflect.DeepEqual(got[:3], []string{"/my/editor", "--fancy", "-f"}) || filepath.Base(got[3]) != "user.css" {
		t.Fatalf("argv %q", got)
	}
}

func TestEditEnvironmentEditorIsUsedWithoutArguments(t *testing.T) {
	e := newEnv(t)
	var gotBox box[[]string]
	pr, _ := io.Pipe()
	errb := &lockedBuffer{}
	sigs := make(chan os.Signal, 1)
	app := &App{
		Name: "lr", Out: &lockedBuffer{}, Err: errb, In: pr, ConfigPath: config.Path, EditPoll: 5 * time.Millisecond, EditSignals: sigs,
		Getenv:    func(k string) string { return map[string]string{"VISUAL": "code --wait", "EDITOR": "nano"}[k] },
		RunEditor: func(argv []string, _ io.Reader, _, _ io.Writer) error { gotBox.set(argv); return nil },
	}
	done := make(chan int, 1)
	go func() { done <- app.Run([]string{"custom", "edit", "taglines"}) }()
	for i := 0; gotBox.get() == nil && i < 1000; i++ {
		time.Sleep(2 * time.Millisecond)
	}
	sigs <- os.Interrupt
	<-done
	_ = e
	got := gotBox.get()
	if len(got) != 3 || got[0] != "code" || got[1] != "--wait" || filepath.Base(got[2]) != "taglines.txt" {
		t.Fatalf("argv %q", got)
	}
}

func TestEditNewPageStartsFromTheTemplateAndUploadsNothingUntilSaved(t *testing.T) {
	e := newEnv(t)
	var seenBox box[string]
	s := e.startEdit(func(_ int, argv []string) error {
		b, _ := os.ReadFile(argv[len(argv)-1])
		seenBox.set(string(b))
		return nil
	}, "page", "edit", "fresh", "ed")
	s.waitFor("the editor", func() bool { return seenBox.get() != "" })
	if code := s.stop(); code != 0 {
		t.Fatalf("code %d", code)
	}
	seen := seenBox.get()
	if !strings.Contains(seen, "published: false") {
		t.Errorf("not the template: %q", seen)
	}
	if _, ok := e.server.PageText("fresh"); ok {
		t.Error("the unchanged template was uploaded")
	}
	if !strings.Contains(s.errb.String(), "starting from the template") {
		t.Errorf("no note:\n%s", s.errb.String())
	}
}

func TestEditCustomFileStartsFromTheDefaultAndKeepsTheServerUntouchedUntilSaved(t *testing.T) {
	e := newEnv(t)
	var seenBox box[string]
	s := e.startEdit(func(_ int, argv []string) error {
		b, _ := os.ReadFile(argv[len(argv)-1])
		seenBox.set(string(b))
		return nil
	}, "custom", "edit", "taglines", "ed")
	s.waitFor("the editor", func() bool { return seenBox.get() != "" })
	s.stop()
	seen := seenBox.get()
	if seen != "built-in 1\nbuilt-in 2\n" {
		t.Errorf("started with %q", seen)
	}
	if _, ok := e.server.CustomText("taglines"); ok {
		t.Error("the unchanged default was uploaded")
	}
	if !strings.Contains(s.errb.String(), "nothing changes until you save") {
		t.Errorf("no note:\n%s", s.errb.String())
	}
}

func TestEditSavingTheSameContentAgainUploadsNothing(t *testing.T) {
	e := newEnv(t)
	e.server.Custom = map[string]string{"css": "a{}\n"}
	var pathBox box[string]
	s := e.startEdit(func(_ int, argv []string) error { pathBox.set(argv[len(argv)-1]); return nil }, "custom", "edit", "css", "ed")
	s.waitFor("the editor", func() bool { return pathBox.get() != "" })
	path := pathBox.get()
	b, _ := os.ReadFile(path)
	_ = os.WriteFile(path, b, 0o600) // a save without a change
	time.Sleep(60 * time.Millisecond)
	e.server.Custom["css"] = "changed elsewhere\n"
	time.Sleep(60 * time.Millisecond)
	s.stop()
	if got, _ := e.server.CustomText("css"); got != "changed elsewhere\n" {
		t.Errorf("an unchanged save was uploaded: %q", got)
	}
	if strings.Contains(s.errb.String(), "uploaded:") {
		t.Errorf("output:\n%s", s.errb.String())
	}
}

func TestEditHoldsMessagesWhileTheEditorIsOpenAndReleasesThemWhenItExits(t *testing.T) {
	e := newEnv(t)
	var s *session
	var duringBox box[string]
	started := make(chan struct{})
	s = e.startEdit(func(_ int, argv []string) error {
		<-started
		overwrite(t, argv, "mine\n")
		s.waitFor("the upload while the editor is open", func() bool { got, _ := e.server.CustomText("taglines"); return got == "mine\n" })
		duringBox.set(s.errb.String())
		return nil
	}, "custom", "edit", "taglines", "ed")
	close(started)
	s.waitFor("the message", func() bool { return strings.Contains(s.errb.String(), "uploaded:") })
	s.stop()
	if duringEditor := duringBox.get(); strings.Contains(duringEditor, "uploaded:") {
		t.Errorf("printed while the editor owned the terminal:\n%s", duringBox.get())
	}
}

func TestEditRefusedUploadIsExplainedAndEnterReopensTheEditor(t *testing.T) {
	e := newEnv(t)
	e.server.Pages = map[string]string{"imprint": "fine\n"}
	s := e.startEdit(func(call int, argv []string) error {
		if call == 1 {
			overwrite(t, argv, "<script>alert(1)</script>\n")
		} else {
			overwrite(t, argv, "fixed\n")
		}
		return nil
	}, "page", "edit", "imprint", "ed")

	s.waitFor("the explanation", func() bool { return strings.Contains(s.errb.String(), "raw HTML is not allowed") })
	if got, _ := e.server.PageText("imprint"); got != "fine\n" {
		t.Fatalf("the refused text was stored: %q", got)
	}
	if !strings.Contains(s.errb.String(), "still being watched") {
		t.Errorf("no hint:\n%s", s.errb.String())
	}
	s.pressEnter()
	s.waitFor("the fixed upload", func() bool { got, _ := e.server.PageText("imprint"); return got == "fixed\n" })
	if code := s.stop(); code != 0 {
		t.Fatalf("code %d:\n%s", code, s.errb.String())
	}
	s.mu.Lock()
	calls := s.calls
	s.mu.Unlock()
	if calls != 2 {
		t.Errorf("the editor was started %d times", calls)
	}
}

func TestEditCtrlCUploadsTheLastSaveFirst(t *testing.T) {
	e := newEnv(t)
	e.server.Custom = map[string]string{"css": "old\n"}
	var wrote atomic.Bool
	// the poll is slower than the test: only the final sync can have seen the save
	pr, _ := io.Pipe()
	errb := &lockedBuffer{}
	sigs := make(chan os.Signal, 1)
	app := &App{
		Name: "lr", Out: &lockedBuffer{}, Err: errb, In: pr, ConfigPath: config.Path, EditPoll: time.Hour, EditSignals: sigs,
		Getenv: func(string) string { return "" },
		RunEditor: func(argv []string, _ io.Reader, _, _ io.Writer) error {
			overwrite(t, argv, "last save\n")
			wrote.Store(true)
			return nil
		},
	}
	done := make(chan int, 1)
	go func() { done <- app.Run([]string{"custom", "edit", "css", "ed"}) }()
	for i := 0; !wrote.Load() && i < 1000; i++ {
		time.Sleep(2 * time.Millisecond)
	}
	time.Sleep(20 * time.Millisecond)
	sigs <- os.Interrupt
	if code := <-done; code != 0 {
		t.Fatalf("code %d:\n%s", code, errb.String())
	}
	if got, _ := e.server.CustomText("css"); got != "last save\n" {
		t.Fatalf("server has %q", got)
	}
}

func TestEditKeepsTheFileWhenTheLastVersionCannotBeUploaded(t *testing.T) {
	e := newEnv(t)
	var pathBox box[string]
	s := e.startEdit(func(_ int, argv []string) error {
		pathBox.set(argv[len(argv)-1])
		overwrite(t, argv, "<script>x</script>\n")
		return nil
	}, "page", "edit", "imprint", "ed")
	s.waitFor("the explanation", func() bool { return strings.Contains(s.errb.String(), "raw HTML is not allowed") })
	if code := s.stop(); code == 0 {
		t.Fatal("exit code 0 although the last version is not on the server")
	}
	path := pathBox.get()
	if b, err := os.ReadFile(path); err != nil || string(b) != "<script>x</script>\n" {
		t.Fatalf("the file is gone: %q, %v", b, err)
	}
	if !strings.Contains(s.errb.String(), "your file is kept at "+path) {
		t.Errorf("no path printed:\n%s", s.errb.String())
	}
	_ = os.RemoveAll(filepath.Dir(path))
}

func TestEditOnceUploadsWhenTheEditorExitsAndEnds(t *testing.T) {
	e := newEnv(t)
	s := e.startEdit(func(_ int, argv []string) error { overwrite(t, argv, "one\ntwo\n"); return nil }, "custom", "edit", "--once", "headerlines", "ed")
	select {
	case code := <-s.code:
		if code != 0 {
			t.Fatalf("code %d:\n%s", code, s.errb.String())
		}
	case <-time.After(5 * time.Second):
		t.Fatalf("--once did not end:\n%s", s.errb.String())
	}
	if got, _ := e.server.CustomText("headerlines"); got != "one\ntwo\n" {
		t.Fatalf("server has %q", got)
	}
}

func TestEditOnceWithoutChangesUploadsNothing(t *testing.T) {
	e := newEnv(t)
	s := e.startEdit(func(int, []string) error { return nil }, "custom", "edit", "--once", "css", "ed")
	if code := <-s.code; code != 0 {
		t.Fatalf("code %d", code)
	}
	if _, ok := e.server.CustomText("css"); ok {
		t.Error("something was uploaded")
	}
	if !strings.Contains(s.errb.String(), "no changes") {
		t.Errorf("output:\n%s", s.errb.String())
	}
}

func TestEditOnceRefusedThenFixedAfterEnter(t *testing.T) {
	e := newEnv(t)
	s := e.startEdit(func(call int, argv []string) error {
		if call == 1 {
			overwrite(t, argv, "<script>x</script>")
		} else {
			overwrite(t, argv, "ok\n")
		}
		return nil
	}, "page", "edit", "--once", "imprint", "ed")
	s.waitFor("the explanation", func() bool { return strings.Contains(s.errb.String(), "press Enter to open the editor again") })
	s.pressEnter()
	if code := <-s.code; code != 0 {
		t.Fatalf("code %d:\n%s", code, s.errb.String())
	}
	if got, _ := e.server.PageText("imprint"); got != "ok\n" {
		t.Fatalf("server has %q", got)
	}
}

func TestEditOnceGivingUpKeepsTheFileAndFails(t *testing.T) {
	e := newEnv(t)
	var pathBox box[string]
	s := e.startEdit(func(_ int, argv []string) error {
		pathBox.set(argv[len(argv)-1])
		overwrite(t, argv, "<script>x</script>")
		return nil
	}, "page", "edit", "--once", "imprint", "ed")
	s.waitFor("the explanation", func() bool { return strings.Contains(s.errb.String(), "Ctrl+C to give up") })
	if code := s.stop(); code == 0 {
		t.Fatal("exit code 0 after giving up")
	}
	path := pathBox.get()
	if _, err := os.Stat(path); err != nil {
		t.Errorf("the file is gone: %v", err)
	}
	_ = os.RemoveAll(filepath.Dir(path))
}

func TestEditRefusesBadNamesBeforeTouchingTheServer(t *testing.T) {
	e := newEnv(t)
	for _, args := range [][]string{
		{"page", "edit"},
		{"page", "edit", "Not-Valid"},
		{"page", "edit", "../x"},
		{"custom", "edit", "passwd"},
		{"custom", "edit"},
	} {
		if code, _, _ := e.run(args...); code != ExitUsage {
			t.Errorf("%v: code %d, want %d", args, code, ExitUsage)
		}
	}
	if e.server.HealthCalls != 0 {
		t.Errorf("the server was asked %d times", e.server.HealthCalls)
	}
}

func TestEditWithoutAnyEditorFails(t *testing.T) {
	e := newEnv(t)
	pr, _ := io.Pipe()
	var errb lockedBuffer
	app := &App{
		Name: "lr", Out: &lockedBuffer{}, Err: &errb, In: pr, ConfigPath: config.Path,
		Getenv:   func(string) string { return "" },
		LookPath: func(string) (string, error) { return "", errors.New("not found") },
	}
	if code := app.Run([]string{"custom", "edit", "css"}); code != 1 || !strings.Contains(errb.String(), "no editor found") {
		t.Fatalf("code %d: %s", code, errb.String())
	}
	if e.server.HealthCalls != 0 {
		t.Error("the server was asked")
	}
}
