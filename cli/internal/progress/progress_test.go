package progress

import (
	"bytes"
	"strings"
	"testing"
	"time"
)

func TestFormatBytes(t *testing.T) {
	cases := map[int64]string{0: "0 B", 1023: "1023 B", 1024: "1.0 KiB", 1536: "1.5 KiB", 5 << 20: "5.0 MiB", 3 << 30: "3.0 GiB"}
	for in, want := range cases {
		if got := FormatBytes(in); got != want {
			t.Errorf("%d: got %q want %q", in, got, want)
		}
	}
}

func TestFormatDuration(t *testing.T) {
	cases := map[time.Duration]string{0: "00:00", 59 * time.Second: "00:59", 61 * time.Second: "01:01", 3725 * time.Second: "1:02:05"}
	for in, want := range cases {
		if got := FormatDuration(in); got != want {
			t.Errorf("%v: got %q want %q", in, got, want)
		}
	}
}

func TestRender(t *testing.T) {
	got := Render("uploading", 50, 100, 10)
	for _, want := range []string{"uploading", "50%", "50 B/100 B", "10 B/s", "ETA 00:05", "============>"} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in %q", want, got)
		}
	}
	if got := Render("x", 100, 100, 0); !strings.Contains(got, "100%") || !strings.Contains(got, "ETA 00:00") {
		t.Errorf("done: %q", got)
	}
	if got := Render("x", 0, 100, 0); !strings.Contains(got, "ETA --:--") {
		t.Errorf("unknown rate: %q", got)
	}
	if got := Render("x", 0, 0, 0); !strings.Contains(got, "100%") {
		t.Errorf("empty file: %q", got)
	}
}

func TestBarDrawsNothingWithoutTerminal(t *testing.T) {
	var buf bytes.Buffer
	b := New(&buf, "x", 100)
	b.Set(10)
	b.Set(100)
	b.Finish()
	if buf.Len() != 0 {
		t.Fatalf("wrote %q", buf.String())
	}
}

func TestBarSmoothsRateAndDraws(t *testing.T) {
	var buf bytes.Buffer
	clock := time.Unix(0, 0)
	b := &Bar{out: &buf, enabled: true, label: "up", total: 1000, now: func() time.Time { return clock }}
	b.Set(0)
	clock = clock.Add(time.Second)
	b.Set(100)
	clock = clock.Add(time.Second)
	b.Set(300)
	if b.rate <= 100 || b.rate >= 200 {
		t.Errorf("rate %f not smoothed between 100 and 200", b.rate)
	}
	if !strings.Contains(buf.String(), "\r\x1b[K") || !strings.Contains(buf.String(), "ETA") {
		t.Errorf("output %q", buf.String())
	}
	b.Finish()
	if !strings.HasSuffix(buf.String(), "\r\x1b[K") {
		t.Errorf("not cleared: %q", buf.String())
	}
}
