package cli

import (
	"fmt"
	"io"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/color"
	"github.com/schorsch3000/Linkrot/internal/progress"
)

// remaining formats the time until a unix timestamp, e.g. "6d 23h", "5h", "<1h", "expired".
func remaining(at int64, now time.Time) string {
	d := time.Unix(at, 0).Sub(now)
	switch {
	case d <= 0:
		return "expired"
	case d < time.Hour:
		return "<1h"
	case d < 24*time.Hour:
		return fmt.Sprintf("%dh", int(d/time.Hour))
	}
	days, hours := int(d/(24*time.Hour)), int(d%(24*time.Hour)/time.Hour)
	if hours == 0 {
		return fmt.Sprintf("%dd", days)
	}
	return fmt.Sprintf("%dd %dh", days, hours)
}

func expiryText(f api.File, now time.Time) string {
	if f.ExpiresAt == nil {
		return "-"
	}
	return remaining(*f.ExpiresAt, now)
}

func usesText(f api.File) string {
	if f.UsesLeft == nil {
		return "-"
	}
	return fmt.Sprint(*f.UsesLeft)
}

func statusText(f api.File) string {
	if f.Status == api.StatusUploading && f.Size > 0 {
		return fmt.Sprintf("uploading %d%%", f.Offset*100/f.Size)
	}
	return f.Status
}

func sizeText(n int64) string { return progress.FormatBytes(n) }

// cell is one table cell; style is applied after padding so alignment ignores escape codes.
type cell struct {
	text  string
	style color.Style
}

// writeTable prints rows with columns padded to the widest plain text, separated by two spaces.
// The last column is not padded.
func writeTable(w io.Writer, p *color.Painter, rows [][]cell) error {
	var widths []int
	for _, r := range rows {
		for i, c := range r {
			if n := utf8.RuneCountInString(c.text); i >= len(widths) {
				widths = append(widths, n)
			} else if n > widths[i] {
				widths[i] = n
			}
		}
	}
	var b strings.Builder
	for _, r := range rows {
		for i, c := range r {
			if c.style == "" {
				b.WriteString(c.text)
			} else {
				b.WriteString(p.Paint(c.style, c.text))
			}
			if i < len(r)-1 {
				b.WriteString(strings.Repeat(" ", widths[i]-utf8.RuneCountInString(c.text)+2))
			}
		}
		b.WriteByte('\n')
	}
	_, err := io.WriteString(w, b.String())
	return err
}

func statusStyle(f api.File) color.Style {
	switch f.Status {
	case api.StatusReady:
		return color.Green
	case api.StatusUploading:
		return color.Yellow
	}
	return color.Red
}

func expiryStyle(f api.File, now time.Time) color.Style {
	if f.ExpiresAt != nil && time.Unix(*f.ExpiresAt, 0).Sub(now) <= 0 {
		return color.Red
	}
	return ""
}
