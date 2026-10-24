// Package clip copies text to the clipboard: via OSC 52 (works over SSH) or a clipboard tool.
package clip

import (
	"bytes"
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"strings"
)

// Selections accepted by Copy. OSC52 is the default if none is named.
const (
	OSC52     = "osc52"
	Clipboard = "clipboard"
	Primary   = "primary"
)

// ErrNoTool means no clipboard program is installed for the requested selection.
var ErrNoTool = errors.New("no clipboard tool found (tried wl-copy, xclip, xsel, pbcopy)")

// Copier copies text. Fields are replaceable for tests.
type Copier struct {
	Terminal io.Writer                                         // receives the OSC 52 sequence
	Getenv   func(string) string                               // environment lookup
	LookPath func(string) (string, error)                      // finds programs
	Run      func(name string, args []string, in string) error // runs a program with stdin
}

// New returns a Copier using the real environment. terminal is where the OSC 52 sequence goes;
// if it is nil the controlling terminal /dev/tty is tried.
func New(terminal io.Writer) *Copier {
	return &Copier{Terminal: terminal, Getenv: os.Getenv, LookPath: exec.LookPath, Run: run}
}

// Valid reports whether selection is a known value.
func Valid(selection string) bool {
	return selection == OSC52 || selection == Clipboard || selection == Primary
}

// Copy puts text into the given selection.
func (c *Copier) Copy(selection, text string) error {
	switch selection {
	case "", OSC52:
		return c.osc52(text)
	case Clipboard, Primary:
		return c.tool(selection, text)
	default:
		return fmt.Errorf("unknown selection %q (use osc52, clipboard or primary)", selection)
	}
}

func (c *Copier) osc52(text string) error {
	seq := "\x1b]52;c;" + base64.StdEncoding.EncodeToString([]byte(text)) + "\a"
	if c.Getenv("TMUX") != "" {
		// tmux only forwards the sequence wrapped for passthrough (needs `allow-passthrough on`).
		seq = "\x1bPtmux;" + strings.ReplaceAll(seq, "\x1b", "\x1b\x1b") + "\x1b\\"
	}
	w := c.Terminal
	if w == nil {
		tty, err := os.OpenFile("/dev/tty", os.O_WRONLY, 0)
		if err != nil {
			return fmt.Errorf("no terminal for OSC 52: %w", err)
		}
		defer func() { _ = tty.Close() }()
		w = tty
	}
	_, err := io.WriteString(w, seq)
	return err
}

type candidate struct {
	name string
	args map[string][]string // per selection; missing = unsupported
}

var candidates = []candidate{
	{"wl-copy", map[string][]string{Clipboard: {}, Primary: {"--primary"}}},
	{"xclip", map[string][]string{Clipboard: {"-selection", "clipboard", "-i"}, Primary: {"-selection", "primary", "-i"}}},
	{"xsel", map[string][]string{Clipboard: {"--clipboard", "--input"}, Primary: {"--primary", "--input"}}},
	{"pbcopy", map[string][]string{Clipboard: {}}},
}

func (c *Copier) tool(selection, text string) error {
	for _, cand := range candidates {
		args, ok := cand.args[selection]
		if !ok {
			continue
		}
		if _, err := c.LookPath(cand.name); err != nil {
			continue
		}
		return c.Run(cand.name, args, text)
	}
	return ErrNoTool
}

func run(name string, args []string, in string) error {
	cmd := exec.CommandContext(context.Background(), name, args...) //nolint:gosec // name comes from a fixed list
	cmd.Stdin = strings.NewReader(in)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	// xclip and wl-copy stay in the background to serve the selection; do not wait on inherited pipes.
	if err := cmd.Start(); err != nil {
		return err
	}
	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()
	if err := <-done; err != nil {
		return fmt.Errorf("%s: %w: %s", name, err, strings.TrimSpace(stderr.String()))
	}
	return nil
}
