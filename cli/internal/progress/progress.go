// Package progress draws a progress bar with throughput and ETA on a terminal.
package progress

import (
	"fmt"
	"io"
	"os"
	"strings"
	"time"

	"github.com/schorsch3000/Linkrot/internal/color"
)

const barWidth = 24

// Bar renders to a writer, normally stderr. It draws nothing unless enabled (a terminal).
type Bar struct {
	out     io.Writer
	enabled bool
	paint   *color.Painter
	label   string
	total   int64
	now     func() time.Time

	start    time.Time
	lastDraw time.Time
	lastTime time.Time
	lastDone int64
	rate     float64 // bytes per second, smoothed
	drawn    bool
}

// New creates a bar for total bytes. It only draws if out is a terminal.
func New(out io.Writer, label string, total int64) *Bar {
	return &Bar{out: out, enabled: IsTerminal(out), paint: color.For(out), label: label, total: total, now: time.Now}
}

// IsTerminal reports whether w is a character device (a terminal).
func IsTerminal(w io.Writer) bool {
	f, ok := w.(*os.File)
	if !ok {
		return false
	}
	info, err := f.Stat()
	return err == nil && info.Mode()&os.ModeCharDevice != 0
}

// Set reports the number of bytes done so far.
func (b *Bar) Set(done int64) {
	now := b.now()
	if b.start.IsZero() {
		b.start, b.lastTime, b.lastDone = now, now, done
	}
	if dt := now.Sub(b.lastTime).Seconds(); dt >= 0.2 {
		inst := float64(done-b.lastDone) / dt
		if inst < 0 {
			inst = 0
		}
		if b.rate == 0 {
			b.rate = inst
		} else {
			b.rate = 0.7*b.rate + 0.3*inst
		}
		b.lastTime, b.lastDone = now, done
	}
	if !b.enabled || (now.Sub(b.lastDraw) < 100*time.Millisecond && done < b.total) {
		return
	}
	b.lastDraw = now
	b.drawn = true
	_, _ = fmt.Fprintf(b.out, "\r\x1b[K%s", b.paint.Paint(color.Cyan, Render(b.label, done, b.total, b.rate)))
}

// Finish clears the bar.
func (b *Bar) Finish() {
	if b.enabled && b.drawn {
		_, _ = fmt.Fprint(b.out, "\r\x1b[K")
	}
}

// Render formats one line, e.g. `uploading [=====>    ] 45%  12.3 MiB/s  ETA 00:12`.
func Render(label string, done, total int64, rate float64) string {
	frac := 1.0
	if total > 0 {
		frac = float64(done) / float64(total)
	}
	frac = min(max(frac, 0), 1)
	filled := int(frac * barWidth)
	bar := strings.Repeat("=", filled)
	if filled < barWidth {
		bar += ">" + strings.Repeat(" ", barWidth-filled-1)
	}
	eta := "--:--"
	if rate > 0 && total > done {
		eta = FormatDuration(time.Duration(float64(total-done) / rate * float64(time.Second)))
	} else if total <= done {
		eta = "00:00"
	}
	return fmt.Sprintf("%s [%s] %3d%%  %s/%s  %s/s  ETA %s", label, bar, int(frac*100),
		FormatBytes(done), FormatBytes(total), FormatBytes(int64(rate)), eta)
}

// FormatBytes formats a size with binary units.
func FormatBytes(n int64) string {
	const unit = 1024
	if n < unit {
		return fmt.Sprintf("%d B", n)
	}
	value, exp := float64(n), 0
	for value >= unit && exp < 4 {
		value /= unit
		exp++
	}
	return fmt.Sprintf("%.1f %ciB", value, "KMGT"[exp-1])
}

// FormatDuration formats mm:ss or h:mm:ss.
func FormatDuration(d time.Duration) string {
	secs := int64(d.Round(time.Second).Seconds())
	if secs >= 3600 {
		return fmt.Sprintf("%d:%02d:%02d", secs/3600, secs%3600/60, secs%60)
	}
	return fmt.Sprintf("%02d:%02d", secs/60, secs%60)
}
