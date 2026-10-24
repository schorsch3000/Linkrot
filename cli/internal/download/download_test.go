package download

import (
	"context"
	"errors"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/testutil"
)

const content = "0123456789abcdefghij0123456789ABCDEFGHIJ" // 40 bytes

func setup(t *testing.T) (*testutil.FakeServer, *api.Client, string) {
	t.Helper()
	s := testutil.NewFakeServer(t)
	return s, api.NewClient(s.URL, s.Token), t.TempDir()
}

func noSleep() Options {
	return Options{Sleep: func(context.Context, time.Duration) error { return nil }}
}

func read(t *testing.T, path string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func TestTargetIsAnExistingDirectory(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))

	path, err := Download(t.Context(), c, id, dir, noSleep())

	if err != nil || path != filepath.Join(dir, "report.txt") || read(t, path) != content {
		t.Fatalf("%q %v", path, err)
	}
	if _, err := os.Stat(path + ".part"); !errors.Is(err, os.ErrNotExist) {
		t.Fatal(".part must be gone")
	}
}

func TestTargetIsTheFileName(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	target := filepath.Join(dir, "copy.bin")

	path, err := Download(t.Context(), c, id, target, noSleep())

	if err != nil || path != target || read(t, target) != content {
		t.Fatalf("%q %v", path, err)
	}
}

func TestNoTargetUsesCurrentDirectory(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	t.Chdir(dir)

	path, err := Download(t.Context(), c, id, "", noSleep())

	if err != nil || path != "report.txt" || read(t, filepath.Join(dir, "report.txt")) != content {
		t.Fatalf("%q %v", path, err)
	}
}

func TestNeverOverwrites(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	existing := filepath.Join(dir, "report.txt")
	if err := os.WriteFile(existing, []byte("precious"), 0o600); err != nil {
		t.Fatal(err)
	}
	other := filepath.Join(dir, "other.txt")
	if err := os.WriteFile(other, []byte("precious"), 0o600); err != nil {
		t.Fatal(err)
	}

	for _, target := range []string{dir, other} {
		_, err := Download(t.Context(), c, id, target, noSleep())
		if err == nil || !strings.Contains(err.Error(), "already exists") {
			t.Errorf("target %s: %v", target, err)
		}
	}
	if read(t, existing) != "precious" || read(t, other) != "precious" {
		t.Fatal("existing files were touched")
	}
	if s.Chunks != 0 {
		t.Fatal("nothing should have been requested")
	}
}

func TestNeverCreatesDirectories(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))

	for _, target := range []string{filepath.Join(dir, "missing", "file.txt"), filepath.Join(dir, "newdir") + "/"} {
		if _, err := Download(t.Context(), c, id, target, noSleep()); err == nil || !strings.Contains(err.Error(), "never created") {
			t.Errorf("target %s: %v", target, err)
		}
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 0 {
		t.Fatalf("something was created: %v", entries)
	}
}

func TestUnsafeServerNames(t *testing.T) {
	s, c, dir := setup(t)
	t.Chdir(dir)
	for _, name := range []string{"../evil", "a/b", "..", "."} {
		id := s.Add(name, []byte(content))
		if _, err := Download(t.Context(), c, id, "", noSleep()); err == nil || !strings.Contains(err.Error(), "unusable file name") {
			t.Errorf("%q: %v", name, err)
		}
		// an explicit target makes the server name irrelevant
		path, err := Download(t.Context(), c, id, filepath.Join(dir, "safe-"+filepath.Base(name)+".bin"), noSleep())
		if err != nil || read(t, path) != content {
			t.Errorf("%q with target: %v", name, err)
		}
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(dir), "evil")); err == nil {
		t.Fatal("file written outside of the target directory")
	}
}

func TestResumeFromPartFile(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	if err := os.WriteFile(filepath.Join(dir, "report.txt.part"), []byte(content[:15]), 0o600); err != nil {
		t.Fatal(err)
	}
	var gotRange string
	s.Intercept = func(r *http.Request) (int, http.Header, string) {
		if strings.HasPrefix(r.URL.Path, "/dl/") {
			gotRange = r.Header.Get("Range")
		}
		return 0, nil, ""
	}
	var progress []int64
	o := noSleep()
	o.OnProgress = func(done, _ int64) { progress = append(progress, done) }

	path, err := Download(t.Context(), c, id, dir, o)

	if err != nil || read(t, path) != content {
		t.Fatalf("%v", err)
	}
	if gotRange != "bytes=15-" || progress[0] != 15 || progress[len(progress)-1] != 40 {
		t.Fatalf("range %q progress %v", gotRange, progress)
	}
}

func TestServerIgnoringRangeStartsOver(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	if err := os.WriteFile(filepath.Join(dir, "report.txt.part"), []byte(content[:15]), 0o600); err != nil {
		t.Fatal(err)
	}
	s.Intercept = func(r *http.Request) (int, http.Header, string) {
		if strings.HasPrefix(r.URL.Path, "/dl/") {
			return 200, nil, content
		}
		return 0, nil, ""
	}

	path, err := Download(t.Context(), c, id, dir, noSleep())

	if err != nil || read(t, path) != content {
		t.Fatalf("%v", err)
	}
}

func TestInterruptedTransferIsResumedAutomatically(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	inner := s.Config.Handler
	requests := 0
	var ranges []string
	s.Config.Handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.URL.Path, "/dl/") {
			inner.ServeHTTP(w, r)
			return
		}
		requests++
		ranges = append(ranges, r.Header.Get("Range"))
		if requests == 1 {
			w.Header().Set("Content-Length", "40")
			_, _ = io.WriteString(w, content[:12])
			w.(http.Flusher).Flush()
			panic(http.ErrAbortHandler) // cut the connection in the middle of the body
		}
		inner.ServeHTTP(w, r)
	})

	path, err := Download(t.Context(), c, id, dir, noSleep())

	if err != nil || read(t, path) != content {
		t.Fatalf("%v", err)
	}
	if len(ranges) != 2 || ranges[0] != "" || ranges[1] != "bytes=12-" {
		t.Fatalf("ranges %q", ranges)
	}
}

func TestCorruptedDataIsDiscarded(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	s.Intercept = func(r *http.Request) (int, http.Header, string) {
		if strings.HasPrefix(r.URL.Path, "/dl/") {
			return 200, nil, strings.Repeat("X", 40)
		}
		return 0, nil, ""
	}

	_, err := Download(t.Context(), c, id, dir, noSleep())

	if err == nil || !strings.Contains(err.Error(), "checksum") {
		t.Fatalf("%v", err)
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 0 {
		t.Fatalf("leftovers: %v", entries)
	}
}

func TestGivesUpAndKeepsPartialData(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	s.Intercept = func(r *http.Request) (int, http.Header, string) {
		if strings.HasPrefix(r.URL.Path, "/dl/") {
			return 503, nil, "busy"
		}
		return 0, nil, ""
	}
	o := noSleep()
	o.MaxRetries = 2

	_, err := Download(t.Context(), c, id, dir, o)

	if err == nil || !strings.Contains(err.Error(), "giving up") {
		t.Fatalf("%v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, "report.txt.part")); err != nil {
		t.Fatal("the .part file must stay so that a later run can resume")
	}
}

func TestPartFileProblems(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	part := filepath.Join(dir, "report.txt.part")
	if err := os.WriteFile(part, []byte(content+"too much"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := Download(t.Context(), c, id, dir, noSleep()); err == nil || !strings.Contains(err.Error(), "larger") {
		t.Fatalf("oversized part: %v", err)
	}
	if err := os.Remove(part); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("/etc/passwd", part); err != nil {
		t.Fatal(err)
	}
	if _, err := Download(t.Context(), c, id, dir, noSleep()); err == nil || !strings.Contains(err.Error(), "not a regular file") {
		t.Fatalf("symlinked part: %v", err)
	}
}

func TestNotReadyAndUnknown(t *testing.T) {
	s, c, dir := setup(t)
	uploading := s.AddUploading("big.iso", 100, 10)

	if _, err := Download(t.Context(), c, uploading, dir, noSleep()); err == nil || !strings.Contains(err.Error(), "still being uploaded") {
		t.Fatalf("%v", err)
	}
	var ae *api.Error
	if _, err := Download(t.Context(), c, "abcdefghijklmnop", dir, noSleep()); !errors.As(err, &ae) || ae.Status != 404 {
		t.Fatalf("%v", err)
	}
}
