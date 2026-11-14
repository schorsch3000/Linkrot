package cli

import (
	"bufio"
	"context"
	"crypto/sha256"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/spf13/cobra"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/progress"
)

const (
	defaultEditPoll = 300 * time.Millisecond
	// stableTicks is how many polls in a row the file must look the same before it is read: editors that save in several
	// steps (write a backup, truncate, write, rename) are not uploaded half way.
	stableTicks      = 2
	finalSyncTimeout = time.Minute
)

// splitCommand splits a command line such as `code --wait "my file"` into words: spaces separate, single and double
// quotes group, a backslash escapes the next character (not inside single quotes; on Windows backslashes are paths).
func splitCommand(s string) ([]string, error) {
	var words []string
	var cur strings.Builder
	inWord := false
	var quote rune
	escape := runtime.GOOS != "windows"
	runes := []rune(s)
	for i := 0; i < len(runes); i++ {
		r := runes[i]
		switch {
		case quote == '\'':
			if r == '\'' {
				quote = 0
			} else {
				cur.WriteRune(r)
			}
		case quote == '"':
			switch {
			case r == '"':
				quote = 0
			case r == '\\' && escape && i+1 < len(runes) && (runes[i+1] == '"' || runes[i+1] == '\\'):
				i++
				cur.WriteRune(runes[i])
			default:
				cur.WriteRune(r)
			}
		case r == '\'' || r == '"':
			quote, inWord = r, true
		case r == '\\' && escape && i+1 < len(runes):
			i++
			cur.WriteRune(runes[i])
			inWord = true
		case r == ' ' || r == '\t':
			if inWord {
				words = append(words, cur.String())
				cur.Reset()
				inWord = false
			}
		default:
			cur.WriteRune(r)
			inWord = true
		}
	}
	if quote != 0 {
		return nil, fmt.Errorf("unclosed %c in %q", quote, s)
	}
	if inWord {
		words = append(words, cur.String())
	}
	return words, nil
}

// resolveEditor decides which editor to run, without the file: the one named on the command line (the program and all its
// arguments), else $VISUAL, else $EDITOR (both may carry arguments), else the first of vim, vi, nano and pico that is
// installed (notepad on Windows).
func resolveEditor(given []string, getenv func(string) string, lookPath func(string) (string, error), goos string) ([]string, error) {
	if len(given) > 0 {
		return given, nil
	}
	for _, name := range []string{"VISUAL", "EDITOR"} {
		if v := strings.TrimSpace(getenv(name)); v != "" {
			words, err := splitCommand(v)
			if err != nil {
				return nil, fmt.Errorf("$%s: %w", name, err)
			}
			if len(words) > 0 {
				return words, nil
			}
		}
	}
	fallbacks := []string{"vim", "vi", "nano", "pico"}
	if goos == "windows" {
		fallbacks = []string{"notepad"}
	}
	for _, name := range fallbacks {
		if _, err := lookPath(name); err == nil {
			return []string{name}, nil
		}
	}
	return nil, fmt.Errorf("no editor found: name one as the last arguments, set $VISUAL or $EDITOR, or install one of %s", strings.Join(fallbacks, ", "))
}

// execEditor runs the editor on the terminal of this process and waits for it.
func execEditor(argv []string, in io.Reader, out, errw io.Writer) error {
	c := exec.CommandContext(context.Background(), argv[0], argv[1:]...) //nolint:gosec // the editor is what the user asked for
	c.Stdin, c.Stdout, c.Stderr = in, out, errw
	return c.Run()
}

// console prints the messages of an edit session. While a full-screen editor owns the terminal anything printed would
// garble it, so messages are held until the editor is gone.
type console struct {
	mu      sync.Mutex
	w       io.Writer
	holding bool
	held    []string
}

func (c *console) printf(format string, args ...any) {
	line := fmt.Sprintf(format, args...)
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.holding {
		c.held = append(c.held, line)
		return
	}
	_, _ = fmt.Fprintln(c.w, line)
}

func (c *console) hold() {
	c.mu.Lock()
	c.holding = true
	c.mu.Unlock()
}

func (c *console) release() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.holding = false
	for _, line := range c.held {
		_, _ = fmt.Fprintln(c.w, line)
	}
	c.held = nil
}

// editSpec is what differs between the things that can be edited.
type editSpec struct {
	label    string // for messages: "page imprint", "css"
	fileName string // basename of the temp file, so that the editor knows the syntax
	// fetch gives the text to start with and a note to print (may be empty).
	fetch func(ctx context.Context, c *api.Client) (content []byte, note string, err error)
	// upload sends the text and describes the result in one line.
	upload func(ctx context.Context, c *api.Client, content []byte) (string, error)
}

func hashOf(b []byte) [sha256.Size]byte { return sha256.Sum256(b) }

type fileState struct {
	size int64
	mod  time.Time
}

func stat(path string) (fileState, bool) {
	info, err := os.Stat(path)
	if err != nil {
		return fileState{}, false
	}
	return fileState{info.Size(), info.ModTime()}, true
}

// uploader sends the content of the file if it differs from what the server has, and remembers what it sent.
type uploader struct {
	a    *App
	c    *api.Client
	spec editSpec
	path string
	out  *console

	mu   sync.Mutex
	sent [sha256.Size]byte
}

// sync reads the file and uploads it if it changed. It reports whether the server is up to date with the file.
func (u *uploader) sync(ctx context.Context) bool {
	content, err := os.ReadFile(u.path) //nolint:gosec // our own temp file
	if err != nil {
		u.out.printf("%s: cannot read %s: %v", u.a.Name, u.path, err)
		return false
	}
	u.mu.Lock()
	defer u.mu.Unlock()
	if hashOf(content) == u.sent {
		return true
	}
	result, err := u.spec.upload(ctx, u.c, content)
	if err != nil {
		u.out.printf("%s %v", u.a.Name+": not uploaded:", err)
		u.out.printf("  fix the file and save again; it is still being watched")
		return false
	}
	u.sent = hashOf(content)
	u.out.printf("%s uploaded: %s", u.a.now().Format("15:04:05"), result)
	return true
}

// watch polls the file and uploads it after it was saved and then stayed the same for a moment, until ctx is done.
func (u *uploader) watch(ctx context.Context, interval time.Duration, last fileState) {
	changed, stable := false, 0
	t := time.NewTicker(interval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
		st, ok := stat(u.path)
		if !ok { // an editor that saves by renaming is between two files
			continue
		}
		switch {
		case st != last:
			last, changed, stable = st, true, 0
		case changed:
			if stable++; stable >= stableTicks {
				changed = false
				u.sync(ctx)
			}
		}
	}
}

func (a *App) editorEnv() (getenv func(string) string, lookPath func(string) (string, error), run func([]string, io.Reader, io.Writer, io.Writer) error) {
	getenv, lookPath, run = a.Getenv, a.LookPath, a.RunEditor
	if getenv == nil {
		getenv = os.Getenv
	}
	if lookPath == nil {
		lookPath = exec.LookPath
	}
	if run == nil {
		run = execEditor
	}
	return getenv, lookPath, run
}

// edit downloads a file to a temp file, opens it in an editor and uploads it whenever it is saved, until Ctrl+C. With
// once it uploads when the editor exits instead (the editor must wait, `code --wait`).
func (a *App) edit(cmd *cobra.Command, spec editSpec, editorArgs []string, once bool) error {
	getenv, lookPath, runEditor := a.editorEnv()
	editor, err := resolveEditor(editorArgs, getenv, lookPath, runtime.GOOS)
	if err != nil {
		return err
	}
	if len(editorArgs) == 0 && a.In == nil && !progress.IsTerminal(os.Stdin) {
		return usageErrorf("there is no terminal for the editor: name an editor as the last arguments")
	}

	c, err := a.client()
	if err != nil {
		return err
	}
	// Ctrl+C reaches this process and the editor alike; it must not cancel the requests of the session
	ctx := context.WithoutCancel(cmd.Context())
	if err := a.preflight(ctx, c); err != nil {
		return err
	}
	content, note, err := spec.fetch(ctx, c)
	if err != nil {
		return err
	}

	dir, err := os.MkdirTemp("", "linkrot-edit-*")
	if err != nil {
		return err
	}
	keep := false
	defer func() {
		if !keep {
			_ = os.RemoveAll(dir)
		}
	}()
	path := filepath.Join(dir, spec.fileName)
	if err := os.WriteFile(path, content, 0o600); err != nil {
		return err
	}

	out := &console{w: a.Err}
	if note != "" {
		out.printf("%s: %s", a.Name, note)
	}
	u := &uploader{a: a, c: c, spec: spec, path: path, out: out, sent: hashOf(content)}

	sigs := a.EditSignals
	if sigs == nil {
		ch := make(chan os.Signal, 4)
		signal.Notify(ch, os.Interrupt, syscall.SIGTERM)
		defer signal.Stop(ch)
		sigs = ch
	}
	interval := a.EditPoll
	if interval <= 0 {
		interval = defaultEditPoll
	}

	var watchDone chan struct{}
	stopWatching := func() {}
	if !once {
		wctx, cancel := context.WithCancel(ctx)
		watchDone = make(chan struct{})
		// the state is taken here, before the editor can save: a save that is done before the goroutine runs counts
		initial, _ := stat(path)
		go func() {
			defer close(watchDone)
			u.watch(wctx, interval, initial)
		}()
		stopWatching = func() {
			cancel()
			<-watchDone
		}
	}
	defer stopWatching()

	argv := append(append([]string{}, editor...), path)
	for {
		out.printf("%s: editing %s in %s (%s)", a.Name, spec.label, strings.Join(editor, " "), path)
		out.hold()
		runErr := runEditor(argv, a.stdin(), a.Out, a.Err)
		out.release()
		drain(sigs)
		var exitErr *exec.ExitError
		switch {
		case errors.As(runErr, &exitErr):
			out.printf("%s: the editor exited with %v", a.Name, exitErr)
		case runErr != nil:
			return runErr
		}

		if once {
			content, _ := os.ReadFile(path) //nolint:gosec // our own temp file
			if hashOf(content) == u.sent {
				out.printf("%s: no changes", a.Name)
				return nil
			}
			if u.sync(ctx) {
				return nil
			}
			out.printf("%s: press Enter to open the editor again, Ctrl+C to give up (the file is kept)", a.Name)
		} else {
			out.printf("%s: watching %s; every save is uploaded. Press Enter to open the editor again, Ctrl+C to stop.", a.Name, spec.label)
		}

		switch waitForEnterOrStop(a.stdin(), sigs) {
		case waitEnter:
			continue
		default:
			stopWatching()
			out.printf("") // the terminal shows ^C on the line
			if once {
				keep = true
				out.printf("%s: gave up; your file is kept at %s", a.Name, path)
				return errors.New("not uploaded")
			}
			sctx, cancel := context.WithTimeout(ctx, finalSyncTimeout)
			ok := u.sync(sctx)
			cancel()
			if !ok {
				keep = true
				out.printf("%s: stopped, but the last version was not uploaded; your file is kept at %s", a.Name, path)
				return errors.New("the last version was not uploaded")
			}
			out.printf("%s: stopped, nothing left to upload", a.Name)
			return nil
		}
	}
}

func drain(sigs <-chan os.Signal) {
	for {
		select {
		case <-sigs:
		default:
			return
		}
	}
}

type waitResult int

const (
	waitEnter waitResult = iota
	waitStop
)

// waitForEnterOrStop returns when a line was typed or a signal arrived. The reader only lives as long as this wait, so it
// never competes with an editor for the keyboard; at the end of the input only a signal ends the wait.
func waitForEnterOrStop(in io.Reader, sigs <-chan os.Signal) waitResult {
	lines := make(chan struct{}, 1)
	go func() {
		if _, err := bufio.NewReader(in).ReadString('\n'); err == nil {
			lines <- struct{}{}
		}
	}()
	select {
	case <-lines:
		return waitEnter
	case <-sigs:
		return waitStop
	}
}

// pageSpec edits a page: a new page starts from the template and stays a draft until its head says otherwise.
func pageSpec(slug string) editSpec {
	return editSpec{
		label:    "page " + slug,
		fileName: slug + ".md",
		fetch: func(ctx context.Context, c *api.Client) ([]byte, string, error) {
			content, err := c.GetPage(ctx, slug, false)
			var ae *api.Error
			if errors.As(err, &ae) && ae.Status == http.StatusNotFound {
				tmpl, terr := c.PageTemplate(ctx)
				return tmpl, "there is no page " + slug + " yet: starting from the template (a draft until it says published: true)", terr
			}
			return content, "", err
		},
		upload: func(ctx context.Context, c *api.Client, content []byte) (string, error) {
			p, err := c.PutPage(ctx, slug, content)
			if err != nil {
				return "", err
			}
			state := "published"
			if !p.Published {
				state = "not published (draft)"
			}
			return fmt.Sprintf("%s is %s, link %q, order %d, %s", p.Slug, state, p.Link, p.Order, p.URL), nil
		},
	}
}

var customFileNames = map[string]string{"taglines": "taglines.txt", "headerlines": "headerlines.txt", "css": "user.css"}

// customSpec edits one of the customizable files; until the first save the server keeps its built-in version.
func customSpec(name string) editSpec {
	return editSpec{
		label:    name,
		fileName: customFileNames[name],
		fetch: func(ctx context.Context, c *api.Client) ([]byte, string, error) {
			content, customized, err := c.GetCustom(ctx, name)
			note := ""
			if !customized {
				note = "starting from the built-in " + name + "; nothing changes until you save"
			}
			return content, note, err
		},
		upload: func(ctx context.Context, c *api.Client, content []byte) (string, error) {
			res, err := c.PutCustom(ctx, name, content)
			if err != nil {
				return "", err
			}
			if res.URL != "" {
				return fmt.Sprintf("%s in use as %s", name, res.URL), nil
			}
			return fmt.Sprintf("%s: %d lines in use", name, res.Lines), nil
		},
	}
}

// editCommand builds `edit` for page and custom. Everything after the name goes to the editor, so the flags of the editor
// are not mistaken for ours: our own flags come before the name.
func (a *App) editCommand(use, short string, spec func(name string) (editSpec, error), complete func(*cobra.Command, []string, string) ([]string, cobra.ShellCompDirective)) *cobra.Command {
	var once bool
	cmd := &cobra.Command{
		Use:   use + " [editor [editor arguments...]]",
		Short: short,
		Long: short + `.

The file is downloaded to a temporary file and opened in the editor named after the name, which is started with all
the words given plus the file as its last argument, for example:

  ` + a.Name + ` ... edit <name> /my/fancy/editor --fancy -f

Without an editor $VISUAL is used, then $EDITOR, then the first of vim, vi, nano and pico that is installed. The program
keeps running and watches the file: when it is saved and stays unchanged for a moment, it is uploaded (only if the content
changed; a refused upload is explained and tried again at the next save). Editors that return at once, such as a graphical
editor started from a launcher, work too. Stop with Ctrl+C: a last save is uploaded first.

While a full-screen editor is open nothing is printed, to not garble its screen: the messages appear when you quit it.
Then Enter opens the editor again.

--once uploads when the editor exits instead (the editor has to wait: code --wait). Our own flags have to come before the
name.`,
		Args: usageArgs(cobra.MinimumNArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			s, err := spec(args[0])
			if err != nil {
				return err
			}
			return a.edit(cmd, s, args[1:], once)
		},
	}
	cmd.Flags().SetInterspersed(false)
	cmd.Flags().BoolVar(&once, "once", false, "upload when the editor exits instead of watching the file (the editor must wait)")
	cmd.ValidArgsFunction = func(cmd *cobra.Command, args []string, toComplete string) ([]string, cobra.ShellCompDirective) {
		if len(args) == 0 {
			return complete(cmd, args, toComplete)
		}
		return nil, cobra.ShellCompDirectiveDefault // the editor and its arguments
	}
	return cmd
}
