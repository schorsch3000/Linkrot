// Package color wraps text in ANSI styles when the output is a terminal.
package color

import (
	"io"
	"os"
)

// Style is an ANSI SGR code.
type Style string

// Styles in use.
const (
	Bold   Style = "1"
	Dim    Style = "2"
	Red    Style = "31"
	Green  Style = "32"
	Yellow Style = "33"
	Cyan   Style = "36"
)

// Painter applies styles; the zero value and nil leave text untouched.
type Painter struct{ on bool }

// For returns a painter for w. Colors are on only for a terminal, unless NO_COLOR is set
// or TERM is dumb. CLICOLOR_FORCE=1 turns them on regardless of the terminal.
func For(w io.Writer) *Painter {
	return &Painter{on: enabled(isTerminal(w), os.Getenv)}
}

func enabled(terminal bool, getenv func(string) string) bool {
	if getenv("NO_COLOR") != "" {
		return false
	}
	if v := getenv("CLICOLOR_FORCE"); v != "" && v != "0" {
		return true
	}
	return terminal && getenv("TERM") != "dumb"
}

func isTerminal(w io.Writer) bool {
	f, ok := w.(*os.File)
	if !ok {
		return false
	}
	info, err := f.Stat()
	return err == nil && info.Mode()&os.ModeCharDevice != 0
}

// On reports whether styles are applied.
func (p *Painter) On() bool { return p != nil && p.on }

// Paint wraps s in style.
func (p *Painter) Paint(style Style, s string) string {
	if !p.On() || s == "" {
		return s
	}
	return "\x1b[" + string(style) + "m" + s + "\x1b[0m"
}

// Force returns a painter that always applies styles; for tests.
func Force() *Painter { return &Painter{on: true} }
