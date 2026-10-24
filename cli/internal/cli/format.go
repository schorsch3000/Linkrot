package cli

import (
	"fmt"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
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
