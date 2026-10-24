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
