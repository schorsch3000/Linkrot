package clip

import (
	"bytes"
	"encoding/base64"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

type call struct {
	name string
	args []string
	in   string
}

func newTestCopier(env map[string]string, installed ...string) (*Copier, *bytes.Buffer, *[]call) {
	var term bytes.Buffer
	var calls []call
	return &Copier{
		Terminal: &term,
		Getenv:   func(k string) string { return env[k] },
		LookPath: func(n string) (string, error) {
			if slices.Contains(installed, n) {
				return "/usr/bin/" + n, nil
			}
			return "", errors.New("not found")
		},
		Run: func(name string, args []string, in string) error {
			calls = append(calls, call{name, args, in})
			return nil
		},
	}, &term, &calls
}

func TestOSC52IsTheDefault(t *testing.T) {
	c, term, calls := newTestCopier(nil)
	if err := c.Copy("", "https://x/y"); err != nil {
		t.Fatal(err)
	}
	want := "\x1b]52;c;" + base64.StdEncoding.EncodeToString([]byte("https://x/y")) + "\a"
	if term.String() != want {
		t.Fatalf("got %q want %q", term.String(), want)
	}
	if len(*calls) != 0 {
		t.Fatal("no tool must run for OSC 52")
	}
}

func TestOSC52InTmuxIsWrapped(t *testing.T) {
	c, term, _ := newTestCopier(map[string]string{"TMUX": "/tmp/tmux-1/default,1,0"})
	if err := c.Copy(OSC52, "u"); err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(term.String(), "\x1bPtmux;\x1b\x1b]52;c;") || !strings.HasSuffix(term.String(), "\a\x1b\\") {
		t.Fatalf("got %q", term.String())
	}
}

func TestSelectionsPickFirstInstalledTool(t *testing.T) {
	cases := []struct {
		selection string
		installed []string
		wantName  string
		wantArgs  []string
	}{
		{Clipboard, []string{"xclip", "xsel"}, "xclip", []string{"-selection", "clipboard", "-i"}},
		{Primary, []string{"xsel", "wl-copy"}, "wl-copy", []string{"--primary"}},
		{Primary, []string{"xsel"}, "xsel", []string{"--primary", "--input"}},
		{Clipboard, []string{"pbcopy"}, "pbcopy", []string{}},
	}
	for _, tc := range cases {
		c, _, calls := newTestCopier(nil, tc.installed...)
		if err := c.Copy(tc.selection, "text"); err != nil {
			t.Fatalf("%v: %v", tc, err)
		}
		got := (*calls)[0]
		if got.name != tc.wantName || !slices.Equal(got.args, tc.wantArgs) || got.in != "text" {
			t.Errorf("%v: got %+v", tc, got)
		}
	}
}

func TestPrimaryNotSupportedByPbcopyAndMissingTools(t *testing.T) {
	c, _, _ := newTestCopier(nil, "pbcopy")
	if err := c.Copy(Primary, "t"); !errors.Is(err, ErrNoTool) {
		t.Fatalf("got %v", err)
	}
	c, _, _ = newTestCopier(nil)
	if err := c.Copy(Clipboard, "t"); !errors.Is(err, ErrNoTool) {
		t.Fatalf("got %v", err)
	}
}

func TestUnknownSelectionAndValid(t *testing.T) {
	c, _, _ := newTestCopier(nil)
	if err := c.Copy("bogus", "t"); err == nil {
		t.Fatal("expected an error")
	}
	if !Valid(OSC52) || !Valid(Clipboard) || !Valid(Primary) || Valid("secondary") {
		t.Fatal("Valid")
	}
}

func TestNewUsesTheRealEnvironmentAndTheGivenTerminal(t *testing.T) {
	var term bytes.Buffer
	t.Setenv("TMUX", "")

	if err := New(&term).Copy(OSC52, "hello"); err != nil {
		t.Fatal(err)
	}
	if want := "\x1b]52;c;" + base64.StdEncoding.EncodeToString([]byte("hello")) + "\a"; term.String() != want {
		t.Fatalf("got %q want %q", term.String(), want)
	}
}

func TestRunFeedsStdinToARealProgram(t *testing.T) {
	out := filepath.Join(t.TempDir(), "copied")

	if err := run("sh", []string{"-c", `cat > "$0"`, out}, "https://x/y"); err != nil {
		t.Fatal(err)
	}
	got, err := os.ReadFile(out) //nolint:gosec // a temp file of this test
	if err != nil || string(got) != "https://x/y" {
		t.Fatalf("got %q, %v", got, err)
	}
}

func TestRunReportsTheErrorOutputOfAFailingProgram(t *testing.T) {
	err := run("sh", []string{"-c", "echo no display >&2; exit 3"}, "")

	if err == nil || !strings.Contains(err.Error(), "sh:") || !strings.Contains(err.Error(), "no display") {
		t.Fatalf("got %v", err)
	}
}

func TestRunReportsAProgramThatCannotStart(t *testing.T) {
	if err := run("/nonexistent/linkrot-clip-tool", nil, ""); err == nil {
		t.Fatal("a missing program must be an error")
	}
}

func TestToolFailureIsReturned(t *testing.T) {
	c, _, _ := newTestCopier(nil, "xclip")
	c.Run = func(string, []string, string) error { return errors.New("xclip: cannot open display") }

	if err := c.Copy(Clipboard, "u"); err == nil || !strings.Contains(err.Error(), "cannot open display") {
		t.Fatalf("got %v", err)
	}
}

func TestPbcopyHasNoPrimarySelection(t *testing.T) {
	c, _, calls := newTestCopier(nil, "pbcopy")

	if err := c.Copy(Primary, "u"); !errors.Is(err, ErrNoTool) {
		t.Fatalf("got %v", err)
	}
	if err := c.Copy(Clipboard, "u"); err != nil || len(*calls) != 1 || (*calls)[0].name != "pbcopy" {
		t.Fatalf("clipboard: %v, %+v", err, *calls)
	}
}

func TestValid(t *testing.T) {
	for _, ok := range []string{OSC52, Clipboard, Primary} {
		if !Valid(ok) {
			t.Errorf("%s must be valid", ok)
		}
	}
	for _, bad := range []string{"", "secondary", "OSC52"} {
		if Valid(bad) {
			t.Errorf("%q must not be valid", bad)
		}
	}
}
