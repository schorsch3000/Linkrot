package cli

import (
	"bytes"
	"strings"
	"testing"

	"github.com/schorsch3000/Linkrot/internal/color"
)

func TestWriteTableAlignsIgnoringColor(t *testing.T) {
	rows := [][]cell{
		{{"ID", color.Bold}, {"URL", color.Bold}},
		{{"abcdef", color.Yellow}, {"x", color.Cyan}},
	}
	var plain, colored bytes.Buffer
	if err := writeTable(&plain, nil, rows); err != nil {
		t.Fatal(err)
	}
	if err := writeTable(&colored, color.Force(), rows); err != nil {
		t.Fatal(err)
	}
	if want := "ID      URL\nabcdef  x\n"; plain.String() != want {
		t.Errorf("plain: got %q, want %q", plain.String(), want)
	}
	if !strings.Contains(colored.String(), "\x1b[33mabcdef\x1b[0m  ") {
		t.Errorf("colored: %q", colored.String())
	}
}
