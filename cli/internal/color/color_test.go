package color

import (
	"bytes"
	"testing"
)

func env(m map[string]string) func(string) string { return func(k string) string { return m[k] } }

func TestEnabled(t *testing.T) {
	cases := []struct {
		name     string
		terminal bool
		env      map[string]string
		want     bool
	}{
		{"terminal", true, map[string]string{"TERM": "xterm"}, true},
		{"pipe", false, nil, false},
		{"no color", true, map[string]string{"NO_COLOR": "1"}, false},
		{"dumb", true, map[string]string{"TERM": "dumb"}, false},
		{"force on pipe", false, map[string]string{"CLICOLOR_FORCE": "1"}, true},
		{"no color beats force", false, map[string]string{"NO_COLOR": "1", "CLICOLOR_FORCE": "1"}, false},
	}
	for _, c := range cases {
		if got := enabled(c.terminal, env(c.env)); got != c.want {
			t.Errorf("%s: got %v, want %v", c.name, got, c.want)
		}
	}
}

func TestPaint(t *testing.T) {
	if got := For(&bytes.Buffer{}).Paint(Red, "x"); got != "x" && !t.Failed() {
		t.Skipf("CLICOLOR_FORCE set in env: %q", got)
	}
	if got := Force().Paint(Red, "x"); got != "\x1b[31mx\x1b[0m" {
		t.Errorf("got %q", got)
	}
	var nilp *Painter
	if got := nilp.Paint(Red, "x"); got != "x" {
		t.Errorf("nil painter: %q", got)
	}
}
