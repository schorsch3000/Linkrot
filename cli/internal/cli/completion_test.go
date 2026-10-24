package cli

import (
	"strings"
	"testing"
)

// completions runs the hidden __complete command, which is what the generated shell scripts call.
func (e *env) completions(args ...string) []string {
	e.t.Helper()
	code, out, errs := e.run(append([]string{"__complete"}, args...)...)
	if code != 0 {
		e.t.Fatalf("code %d: %s", code, errs)
	}
	lines := strings.Split(strings.TrimRight(out, "\n"), "\n")
	return lines[:len(lines)-1] // the last line is the directive (:4)
}

func TestCompleteIDsWithNamesAsDescription(t *testing.T) {
	e := newEnv(t)
	a := e.server.Add("a.txt", []byte("a"))
	b := e.server.Add("b.txt", []byte("b"))

	for _, cmd := range []string{"rm", "info", "set", "down"} {
		got := e.completions(cmd, "")
		if len(got) != 2 || !strings.Contains(strings.Join(got, "|"), a+"\ta.txt") || !strings.Contains(strings.Join(got, "|"), b+"\tb.txt") {
			t.Errorf("%s: %q", cmd, got)
		}
	}
	if got := e.completions("info", b[:4]); len(got) != 1 || !strings.HasPrefix(got[0], b) {
		t.Errorf("prefix: %q", got)
	}
}

func TestCompleteIDsStopsWhereArgumentsChange(t *testing.T) {
	e := newEnv(t)
	a := e.server.Add("a.txt", []byte("a"))
	b := e.server.Add("b.txt", []byte("b"))

	if got := e.completions("down", a, ""); len(got) != 0 {
		t.Errorf("down target is a path, got %q", got)
	}
	if got := e.completions("rm", a, ""); len(got) != 1 || !strings.HasPrefix(got[0], b) {
		t.Errorf("rm offers the ids not yet given: %q", got)
	}
}

func TestCompleteIDsWithoutConfigOrServerGivesNothing(t *testing.T) {
	e := newEnv(t)
	e.server.Close()
	if got := e.completions("rm", ""); len(got) != 0 {
		t.Errorf("%q", got)
	}
}

func TestCompleteFlagValues(t *testing.T) {
	e := newEnv(t)
	if got := e.completions("up", "--copy="); len(got) != 3 {
		t.Errorf("copy: %q", got)
	}
	if got := e.completions("set", "x", "--rm-after", ""); len(got) != 5 || got[0] != "1h" {
		t.Errorf("rm-after: %q", got)
	}
}

func flagNamesOf(completions []string) []string {
	var names []string
	for _, c := range completions {
		names = append(names, strings.SplitN(c, "\t", 2)[0])
	}
	return names
}

func TestConfigCompletesItsFlags(t *testing.T) {
	e := newEnv(t)

	got := flagNamesOf(e.completions("config", ""))
	if strings.Join(got, " ") != "--server --token" {
		t.Fatalf("config: %v", got)
	}
	if got := flagNamesOf(e.completions("config", "--server", "https://x", "")); strings.Join(got, " ") != "--token" {
		t.Fatalf("flags that are already given are not offered again: %v", got)
	}
	if got := flagNamesOf(e.completions("config", "--t")); strings.Join(got, " ") != "--token" {
		t.Fatalf("prefix: %v", got)
	}
}

func TestFlagsAreOfferedWhereNoArgumentsAreLeft(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("a.txt", []byte("a"))

	cases := map[string][]string{
		"ls":   {"ls", ""},
		"set":  {"set", id, ""},
		"info": {"info", id, ""},
		"up":   {"up", "file.bin", ""},
	}
	for name, args := range cases {
		if got := e.completions(args...); len(got) == 0 {
			t.Errorf("%s: no flags offered", name)
		}
	}
	if got := strings.Join(flagNamesOf(e.completions("set", id, "")), " "); got != "--mv --rm-after --uses --json" && got != "--json --mv --rm-after --uses" {
		t.Errorf("set flags: %q", got)
	}
	// the file to upload and the download target stay with the shell's file completion
	if got := e.completions("up", ""); len(got) != 0 {
		t.Errorf("up file: %v", got)
	}
	if got := e.completions("down", id, ""); len(got) != 0 {
		t.Errorf("down target: %v", got)
	}
}
