package cli

import (
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"github.com/schorsch3000/Linkrot/internal/api"
)

func TestQuietUpPrintsOnlyTheURL(t *testing.T) {
	e := newEnv(t)

	code, out, errs := e.run("up", "-q", e.file("report.txt", content))

	if code != 0 || errs != "" {
		t.Fatalf("code %d, stderr %q", code, errs)
	}
	if !strings.HasPrefix(out, e.server.URL+"/file/") || strings.Count(out, "\n") != 1 {
		t.Fatalf("stdout %q", out)
	}
}

func TestQuietFlagWorksBeforeTheCommandToo(t *testing.T) {
	e := newEnv(t)
	code, out, errs := e.run("--quiet", "up", e.file("report.txt", content))
	if code != 0 || errs != "" || !strings.HasPrefix(out, e.server.URL+"/file/") {
		t.Fatalf("code %d: %q %q", code, out, errs)
	}
}

func TestQuietUpWithCopyPrintsNothingAndCopies(t *testing.T) {
	e := newEnv(t)

	code, out, errs := e.run("up", "-q", "--copy=clipboard", e.file("report.txt", content))

	if code != 0 || out != "" || errs != "" {
		t.Fatalf("code %d: stdout %q stderr %q", code, out, errs)
	}
	if len(e.copied) != 1 || !strings.HasPrefix(e.copied[0], "clipboard:"+e.server.URL+"/file/") {
		t.Fatalf("copied %v", e.copied)
	}
}

func TestQuietUpWithJSONStillPrintsTheJSONBecauseItWasAskedFor(t *testing.T) {
	e := newEnv(t)
	code, out, _ := e.run("up", "-q", "--json", "--copy=clipboard", e.file("report.txt", content))
	if code != 0 || !strings.Contains(out, `"url"`) {
		t.Fatalf("code %d: %q", code, out)
	}
}

func TestQuietUpDoesNotAnnounceRetries(t *testing.T) {
	e := newEnv(t)
	fail := true
	e.server.Intercept = func(r *http.Request) (int, http.Header, string) {
		if fail && r.Method == http.MethodPatch && r.Header.Get("Upload-Offset") == "10" {
			fail = false
			return 503, nil, "busy"
		}
		return 0, nil, ""
	}

	code, out, errs := e.run("up", "-q", e.file("a.txt", content))

	if code != 0 || out == "" || errs != "" {
		t.Fatalf("code %d: stdout %q stderr %q", code, out, errs)
	}
}

func TestQuietUpDuplicateKeepsTheURLTheExitCodeAndTheErrorMessage(t *testing.T) {
	e := newEnv(t)
	f := e.file("a.txt", content)
	e.run("up", f)

	code, out, errs := e.run("up", "-q", f)

	if code != ExitDuplicate || !strings.HasPrefix(out, e.server.URL+"/file/") {
		t.Fatalf("code %d: %q", code, out)
	}
	if !strings.Contains(errs, "already exists") {
		t.Fatalf("the error message is gone: %q", errs)
	}
}

func TestQuietKeepsErrors(t *testing.T) {
	e := newEnv(t)
	code, out, errs := e.run("up", "-q", filepath.Join(e.dir, "missing.txt"))
	if code == 0 || out != "" || errs == "" {
		t.Fatalf("code %d: %q %q", code, out, errs)
	}
	code, _, errs = e.run("-q", "info", "zzzz")
	if code == 0 || errs == "" {
		t.Fatalf("code %d: %q", code, errs)
	}
}

func TestQuietHidesHealthWarningsButNotHealthErrors(t *testing.T) {
	e := newEnv(t)
	e.server.Health = &api.Health{Status: api.LevelWarn, Checks: []api.HealthCheck{{Name: "disk", Level: api.LevelWarn, Message: "low disk space"}}}
	if _, _, errs := e.run("ls"); !strings.Contains(errs, "low disk space") {
		t.Fatalf("without -q the warning is printed: %q", errs)
	}
	if code, _, errs := e.run("-q", "ls"); code != 0 || errs != "" {
		t.Fatalf("code %d: warning printed with -q: %q", code, errs)
	}

	e.server.Health = &api.Health{Status: api.LevelError, Checks: []api.HealthCheck{{Name: "storage", Level: api.LevelError, Message: "not writable"}}}
	code, _, errs := e.run("-q", "ls")
	if code == 0 || !strings.Contains(errs, "not writable") {
		t.Fatalf("code %d: the error is gone: %q", code, errs)
	}
}

func TestQuietCommandsThatConfirmPrintNothing(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("a.txt", []byte("aaaa"))
	page := e.file("imprint.md", "---\ntitle: Imprint\n---\nHi\n")
	css := e.file("user.css", "a { color: red }")
	for _, args := range [][]string{
		{"set", id, "--uses", "2"},
		{"set", id, "--mv", "b.txt"},
		{"cleanup"},
		{"config", "set", "taglines", "off"},
		{"custom", "put", "css", css},
		{"custom", "put", "taglines", e.file("t.txt", "mine\n")},
		{"custom", "reset", "css"},
		{"page", "put", page},
		{"page", "rm", "imprint"},
	} {
		code, out, errs := e.run(append([]string{"-q"}, args...)...)
		if code != 0 || out != "" || errs != "" {
			t.Errorf("%v: code %d, stdout %q, stderr %q", args, code, out, errs)
		}
	}
}

func TestQuietConfirmationsAreStillDone(t *testing.T) {
	e := newEnv(t)
	e.run("-q", "config", "set", "taglines", "off")
	e.run("-q", "page", "put", e.file("imprint.md", "x\n"))
	e.run("-q", "custom", "put", "css", e.file("user.css", "a{}"))
	if e.server.Settings["show_taglines"] {
		t.Error("the setting was not changed")
	}
	if _, ok := e.server.PageText("imprint"); !ok {
		t.Error("the page was not uploaded")
	}
	if _, ok := e.server.CustomText("css"); !ok {
		t.Error("the css was not uploaded")
	}
}

func TestQuietKeepsTheResultOfCommandsThatExistToShowIt(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("a.txt", []byte("aaaa"))
	e.server.Pages = map[string]string{"imprint": "---\ntitle: Imprint\n---\nbody\n"}
	e.server.Quota = &api.Quota{Used: 4}
	for _, tc := range []struct {
		args []string
		want string
	}{
		{[]string{"ls"}, id},
		{[]string{"info", id}, "a.txt"},
		{[]string{"config", "get"}, "show_taglines"},
		{[]string{"custom", "get", "taglines"}, "built-in 1"},
		{[]string{"page", "ls"}, "imprint"},
		{[]string{"page", "get", "imprint"}, "body"},
		{[]string{"page", "template"}, "published: false"},
	} {
		code, out, errs := e.run(append([]string{"-q"}, tc.args...)...)
		if code != 0 || !strings.Contains(out, tc.want) || errs != "" {
			t.Errorf("%v: code %d, stdout %q, stderr %q", tc.args, code, out, errs)
		}
	}
}

func TestQuietLsHasNoQuotaLine(t *testing.T) {
	e := newEnv(t)
	e.server.Add("a.txt", []byte("aaaa"))
	e.server.Quota = &api.Quota{Used: 4}

	if _, out, _ := e.run("ls"); !strings.Contains(out, "Quota") {
		t.Fatalf("without -q: %q", out)
	}
	if _, out, _ := e.run("-q", "ls"); strings.Contains(out, "Quota") {
		t.Fatalf("with -q: %q", out)
	}
}

func TestQuietGetToAFileWritesItAndSaysNothing(t *testing.T) {
	e := newEnv(t)
	target := filepath.Join(e.dir, "t.txt")
	code, out, errs := e.run("-q", "custom", "get", "taglines", "-o", target)
	if code != 0 || out != "" || errs != "" {
		t.Fatalf("code %d: %q %q", code, out, errs)
	}
	if b, err := os.ReadFile(target); err != nil || string(b) != "built-in 1\nbuilt-in 2\n" {
		t.Fatalf("file %q, %v", b, err)
	}
}

func TestQuietDownPrintsOnlyThePath(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("a.txt", []byte("aaaa"))
	t.Chdir(e.dir)

	code, out, errs := e.run("-q", "down", id)

	if code != 0 || errs != "" || strings.TrimSpace(out) != "a.txt" && !strings.HasSuffix(strings.TrimSpace(out), "a.txt") {
		t.Fatalf("code %d: %q %q", code, out, errs)
	}
}

func TestQuietEditTellsOnlyWhatWentWrong(t *testing.T) {
	e := newEnv(t)
	e.server.Pages = map[string]string{"imprint": "fine\n"}
	s := e.startEdit(func(_ int, argv []string) error {
		overwrite(t, argv, "<script>x</script>\n")
		return nil
	}, "-q", "page", "edit", "imprint", "ed")

	s.waitFor("the explanation", func() bool { return strings.Contains(s.errb.String(), "raw HTML is not allowed") })
	s.sigs <- os.Interrupt
	<-s.code
	out := s.errb.String()
	for _, chatter := range []string{"editing page", "watching", "Press Enter", "stopped, nothing"} {
		if strings.Contains(out, chatter) {
			t.Errorf("-q printed %q:\n%s", chatter, out)
		}
	}
	if regexp.MustCompile(`\d\d:\d\d:\d\d uploaded:`).MatchString(out) {
		t.Errorf("-q announced an upload:\n%s", out)
	}
	if !strings.Contains(out, "your file is kept at") {
		t.Errorf("the failure note is gone:\n%s", out)
	}
}

func TestQuietEditSaysNothingWhenItWorks(t *testing.T) {
	e := newEnv(t)
	s := e.startEdit(func(_ int, argv []string) error { overwrite(t, argv, "mine\n"); return nil }, "-q", "custom", "edit", "taglines", "ed")
	s.waitFor("the upload", func() bool { got, _ := e.server.CustomText("taglines"); return got == "mine\n" })
	if code := s.stop(); code != 0 {
		t.Fatalf("code %d", code)
	}
	if out := s.errb.String(); out != "" {
		t.Fatalf("-q printed:\n%s", out)
	}
}

func TestQuietIsInTheHelpOfEveryCommand(t *testing.T) {
	e := newEnv(t)
	for _, args := range [][]string{{"up"}, {"ls"}, {"down"}, {"config"}, {"custom", "get"}, {"page", "put"}, {"page", "edit"}} {
		_, out, _ := e.run(append(args, "--help")...)
		if !strings.Contains(out, "-q, --quiet") {
			t.Errorf("%v --help lacks -q:\n%s", args, out)
		}
	}
}
