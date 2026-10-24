package clip

import (
	"bytes"
	"encoding/base64"
	"errors"
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
