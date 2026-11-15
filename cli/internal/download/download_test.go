package download

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"io/fs"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/testutil"
)

const content = "0123456789abcdefghij0123456789ABCDEFGHIJ" // 40 bytes

var contentSum = func() string { h := sha256.Sum256([]byte(content)); return hex.EncodeToString(h[:]) }()

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
	if _, err := os.Stat(PartPath(path, contentSum)); !errors.Is(err, os.ErrNotExist) {
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
	if err := os.WriteFile(PartPath(filepath.Join(dir, "report.txt"), contentSum), []byte(content[:15]), 0o600); err != nil {
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
	if err := os.WriteFile(PartPath(filepath.Join(dir, "report.txt"), contentSum), []byte(content[:15]), 0o600); err != nil {
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
	if _, err := os.Stat(PartPath(filepath.Join(dir, "report.txt"), contentSum)); err != nil {
		t.Fatal("the .part file must stay so that a later run can resume")
	}
}

func TestPartFileProblems(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	part := PartPath(filepath.Join(dir, "report.txt"), contentSum)
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

func TestRealSleepWaitsAndIsInterruptible(t *testing.T) {
	start := time.Now()
	if err := sleep(context.Background(), 20*time.Millisecond); err != nil || time.Since(start) < 15*time.Millisecond {
		t.Fatalf("sleep returned early or failed: %v", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := sleep(ctx, time.Hour); !errors.Is(err, context.Canceled) {
		t.Fatalf("got %v", err)
	}
}

func TestOpenPartCreatesResumesAndRefuses(t *testing.T) {
	dir := t.TempDir()
	part := filepath.Join(dir, "a.part")

	f, have, err := openPart(part, 100)
	if err != nil || have != 0 {
		t.Fatalf("fresh: %d, %v", have, err)
	}
	if _, err := f.WriteString("12345"); err != nil {
		t.Fatal(err)
	}
	_ = f.Close()

	f, have, err = openPart(part, 100)
	if err != nil || have != 5 {
		t.Fatalf("resume: %d, %v", have, err)
	}
	_ = f.Close()

	if _, _, err := openPart(part, 3); err == nil || !strings.Contains(err.Error(), "larger than the file") {
		t.Errorf("a part file larger than the file must be refused, got %v", err)
	}

	sub := filepath.Join(dir, "dirpart")
	if err := os.Mkdir(sub, 0o700); err != nil {
		t.Fatal(err)
	}
	if _, _, err := openPart(sub, 100); err == nil || !strings.Contains(err.Error(), "not a regular file") {
		t.Errorf("a directory must be refused, got %v", err)
	}

	if _, _, err := openPart(filepath.Join(dir, "missing-dir", "a.part"), 100); err == nil {
		t.Error("a part file in a missing directory must fail")
	}
}

// A response that ends cleanly before the file is complete and makes no progress (empty body, or the
// whole short body again and again) must count as a failed attempt, not be re-requested in a tight loop.
func TestBodyThatEndsEarlyWithoutProgressIsGivenUpOn(t *testing.T) {
	for name, body := range map[string]string{
		"empty body": "",
		"short body": content[:10],
	} {
		t.Run(name, func(t *testing.T) {
			s, c, dir := setup(t)
			id := s.Add("report.txt", []byte(content))
			ctx, cancel := context.WithCancel(t.Context())
			defer cancel()
			requests := 0
			s.Intercept = func(r *http.Request) (int, http.Header, string) {
				if !strings.HasPrefix(r.URL.Path, "/dl/") {
					return 0, nil, ""
				}
				if requests++; requests > 100 { // safety net: a client that spins is stopped here
					cancel()
				}
				return 200, nil, body
			}
			var sleeps int
			o := noSleep()
			o.MaxRetries = 3
			o.Sleep = func(context.Context, time.Duration) error { sleeps++; return nil }

			_, err := Download(ctx, c, id, dir, o)

			if err == nil || !strings.Contains(err.Error(), "giving up after 3 failed attempts") {
				t.Fatalf("got %v after %d requests, want giving up after 3 failed attempts", err, requests)
			}
			if requests > 10 || sleeps != 3 {
				t.Fatalf("%d requests, %d sleeps", requests, sleeps)
			}
			if _, err := os.Stat(PartPath(filepath.Join(dir, "report.txt"), contentSum)); err != nil {
				t.Fatal("the .part file must stay so that a later run can resume")
			}
		})
	}
}

// A body that ends early but made progress is resumed without using up the retries.
func TestBodyThatEndsEarlyWithProgressIsResumed(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	inner := s.Config.Handler
	requests := 0
	s.Config.Handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.URL.Path, "/dl/") {
			inner.ServeHTTP(w, r)
			return
		}
		requests++
		if requests == 1 {
			_, _ = io.WriteString(w, content[:12]) // 200, clean end, 12 of 40 bytes
			return
		}
		inner.ServeHTTP(w, r)
	})
	o := noSleep()
	o.MaxRetries = 1

	path, err := Download(t.Context(), c, id, dir, o)

	if err != nil || read(t, path) != content {
		t.Fatalf("%v", err)
	}
}

// A server that goes silent in the middle of the body, connection still open, must not hang the download: the idle
// timeout ends that request and the transfer resumes from what arrived.
func TestSilentServerInTheMiddleOfTheBodyIsResumed(t *testing.T) {
	s, c, dir := setup(t)
	c.IdleTimeout = 150 * time.Millisecond
	id := s.Add("report.txt", []byte(content))
	inner := s.Config.Handler
	gate := make(chan struct{})
	t.Cleanup(func() { close(gate) })
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
			<-gate // silence
			return
		}
		inner.ServeHTTP(w, r)
	})
	ctx, cancel := context.WithTimeout(t.Context(), 5*time.Second) // safety net
	defer cancel()

	path, err := Download(ctx, c, id, dir, noSleep())

	if err != nil || read(t, path) != content {
		t.Fatalf("%v", err)
	}
	if len(ranges) != 2 || ranges[0] != "" || ranges[1] != "bytes=12-" {
		t.Fatalf("ranges %q", ranges)
	}
}

// A file that merely looks like a partial download (Firefox, curl, a backup tool wrote it) is not ours: it is neither
// appended to nor removed, whatever happens to the download.
func TestForeignPartFileIsLeftAlone(t *testing.T) {
	foreign := "somebody else's half-finished download"
	for name, corrupt := range map[string]bool{"download succeeds": false, "download is corrupted": true} {
		t.Run(name, func(t *testing.T) {
			s, c, dir := setup(t)
			id := s.Add("report.txt", []byte(content))
			if corrupt {
				s.Intercept = func(r *http.Request) (int, http.Header, string) {
					if strings.HasPrefix(r.URL.Path, "/dl/") {
						return 200, nil, strings.Repeat("X", 40)
					}
					return 0, nil, ""
				}
			}
			theirs := filepath.Join(dir, "report.txt.part")
			if err := os.WriteFile(theirs, []byte(foreign), 0o600); err != nil {
				t.Fatal(err)
			}

			path, err := Download(t.Context(), c, id, dir, noSleep())

			if corrupt != (err != nil) || (!corrupt && read(t, path) != content) {
				t.Fatalf("%q %v", path, err)
			}
			if got, rerr := os.ReadFile(theirs); rerr != nil || string(got) != foreign {
				t.Fatalf("the foreign file was touched: %q, %v", got, rerr)
			}
		})
	}
}

// Partial data of another version of the file (same name, other content) is not continued with this one.
func TestPartialOfAnotherVersionIsNotResumed(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	other := PartPath(filepath.Join(dir, "report.txt"), strings.Repeat("0", 64))
	if err := os.WriteFile(other, []byte("0123456789abc"), 0o600); err != nil { // looks like the start of content
		t.Fatal(err)
	}

	path, err := Download(t.Context(), c, id, dir, noSleep())

	if err != nil || read(t, path) != content {
		t.Fatalf("%v", err)
	}
	if read(t, other) != "0123456789abc" {
		t.Fatal("the partial of the other version was touched")
	}
}

func TestPartPathDependsOnTheContent(t *testing.T) {
	a, b := PartPath("x/report.txt", contentSum), PartPath("x/report.txt", strings.Repeat("0", 64))
	if a == b || a == "x/report.txt.part" || !strings.HasSuffix(a, ".part") || !strings.HasPrefix(a, "x/report.txt") {
		t.Fatalf("%q %q", a, b)
	}
}

func linkUnsupported(string, string) error {
	return &os.LinkError{Op: "link", Err: syscall.EPERM} // what FAT, some SMB/FUSE mounts and Termux answer
}

// On a filesystem without hard links the file still arrives, by an exclusive create and a copy.
func TestFilesystemWithoutHardLinksFallsBackToACopy(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	o := noSleep()
	o.Link = linkUnsupported

	path, err := Download(t.Context(), c, id, dir, o)

	if err != nil || path != filepath.Join(dir, "report.txt") || read(t, path) != content {
		t.Fatalf("%q %v", path, err)
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 1 {
		t.Fatalf("leftovers: %v", entries)
	}
}

// The fallback keeps the promise: a file that appears at the last moment is not replaced, the download is kept.
func TestFallbackNeverOverwritesAFileThatAppearedMeanwhile(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	dest := filepath.Join(dir, "report.txt")
	o := noSleep()
	o.Link = func(string, string) error {
		if err := os.WriteFile(dest, []byte("mine"), 0o600); err != nil {
			t.Error(err)
		}
		return linkUnsupported("", "")
	}

	_, err := Download(t.Context(), c, id, dir, o)

	if err == nil || !strings.Contains(err.Error(), "not overwriting") || !strings.Contains(err.Error(), ".part") {
		t.Fatalf("%v", err)
	}
	if read(t, dest) != "mine" {
		t.Fatal("the file that appeared was replaced")
	}
	if _, err := os.Stat(PartPath(dest, contentSum)); err != nil {
		t.Fatal("the download must be kept")
	}
}

// "already exists" from link() is the answer, not a reason to copy.
func TestLinkSaysExistsThenThereIsNoFallback(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	o := noSleep()
	o.Link = func(string, string) error { return &os.LinkError{Op: "link", Err: fs.ErrExist} }

	_, err := Download(t.Context(), c, id, dir, o)

	if err == nil || !strings.Contains(err.Error(), "not overwriting") {
		t.Fatalf("%v", err)
	}
	if _, err := os.Lstat(filepath.Join(dir, "report.txt")); err == nil {
		t.Fatal("the destination was created")
	}
}

// A copy that does not match what was verified is removed again; the download stays.
func TestCopyThatDoesNotMatchIsRemoved(t *testing.T) {
	dir := t.TempDir()
	part, dest := filepath.Join(dir, "a.part"), filepath.Join(dir, "a")
	if err := os.WriteFile(part, []byte("not the content"), 0o600); err != nil {
		t.Fatal(err)
	}

	err := moveByCopy(part, dest, &api.File{Size: int64(len(content)), SHA256: contentSum})

	if err == nil {
		t.Fatal("expected an error")
	}
	if _, err := os.Lstat(dest); err == nil {
		t.Fatal("the bad copy was left under the final name")
	}
	if _, err := os.Stat(part); err != nil {
		t.Fatal("the download must be kept")
	}
}

// A server that says how long to wait is believed (up to the cap), downloads included.
func TestRetryAfterOfTheServerIsHonoured(t *testing.T) {
	s, c, dir := setup(t)
	id := s.Add("report.txt", []byte(content))
	failed := false
	s.Intercept = func(r *http.Request) (int, http.Header, string) {
		if strings.HasPrefix(r.URL.Path, "/dl/") && !failed {
			failed = true
			return 503, http.Header{"Retry-After": {"7"}}, "busy"
		}
		return 0, nil, ""
	}
	var sleeps []time.Duration
	o := noSleep()
	o.Sleep = func(_ context.Context, d time.Duration) error { sleeps = append(sleeps, d); return nil }

	path, err := Download(t.Context(), c, id, dir, o)

	if err != nil || read(t, path) != content {
		t.Fatalf("%v", err)
	}
	if len(sleeps) != 1 || sleeps[0] != 7*time.Second {
		t.Fatalf("sleeps %v, want [7s]", sleeps)
	}
}
