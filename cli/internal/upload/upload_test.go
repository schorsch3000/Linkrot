package upload

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/testutil"
)

func writeFile(t *testing.T, name, content string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), name)
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func newOptions() (*Options, *[]time.Duration) {
	var sleeps []time.Duration
	return &Options{Sleep: func(_ context.Context, d time.Duration) error {
		sleeps = append(sleeps, d)
		return nil
	}}, &sleeps
}

func setup(t *testing.T) (*testutil.FakeServer, *api.Client) {
	s := testutil.NewFakeServer(t)
	return s, api.NewClient(s.URL, s.Token)
}

const content = "hello linkrot, this is 31 bytes" // 31 bytes, chunk size of the fake is 10

func TestUploadInChunks(t *testing.T) {
	s, c := setup(t)
	path := writeFile(t, "report.txt", content)
	o, _ := newOptions()
	var progress []int64
	var hashed int64
	o.OnProgress = func(done, _ int64) { progress = append(progress, done) }
	o.OnHash = func(done, _ int64) { hashed = done }
	o.RmAfter = "2d"

	file, err := Upload(context.Background(), c, path, *o)
	if err != nil {
		t.Fatal(err)
	}

	if file.Status != api.StatusReady || file.URL == "" || file.Name != "report.txt" {
		t.Fatalf("file %+v", file)
	}
	if got := string(s.Content(file.ID)); got != content {
		t.Fatalf("content %q", got)
	}
	if s.Chunks != 4 {
		t.Errorf("chunks %d, want 4", s.Chunks)
	}
	if hashed != int64(len(content)) {
		t.Errorf("hash progress %d", hashed)
	}
	if last := progress[len(progress)-1]; last != int64(len(content)) {
		t.Errorf("final progress %d", last)
	}
	for i := 1; i < len(progress); i++ {
		if progress[i] < progress[i-1] {
			t.Fatalf("progress went backwards: %v", progress)
		}
	}
}

func TestNameOverride(t *testing.T) {
	_, c := setup(t)
	o, _ := newOptions()
	o.Name = "renamed.bin"

	file, err := Upload(context.Background(), c, writeFile(t, "orig.txt", content), *o)
	if err != nil || file.Name != "renamed.bin" {
		t.Fatalf("%+v, %v", file, err)
	}
}

func TestEmptyFileCompletesAtCreate(t *testing.T) {
	s, c := setup(t)
	o, _ := newOptions()

	file, err := Upload(context.Background(), c, writeFile(t, "empty", ""), *o)
	if err != nil || file.Status != api.StatusReady {
		t.Fatalf("%+v, %v", file, err)
	}
	if s.Chunks != 0 {
		t.Fatal("no chunk expected")
	}
}

func TestDuplicateIsReported(t *testing.T) {
	s, c := setup(t)
	id := s.Add("existing.txt", []byte(content))
	o, _ := newOptions()

	_, err := Upload(context.Background(), c, writeFile(t, "again.txt", content), *o)

	var dup *DuplicateError
	if !errors.As(err, &dup) || dup.ID != id || !strings.Contains(dup.URL, id) {
		t.Fatalf("got %v", err)
	}
}

func TestResumeContinuesAtServerOffset(t *testing.T) {
	s, c := setup(t)
	path := writeFile(t, "a.txt", content)
	o, _ := newOptions()
	failAt := 2
	s.Intercept = func(r *http.Request) (int, http.Header, string) {
		if r.Method == http.MethodPatch && strings.HasSuffix(r.URL.Path, "/content") && s.Chunks == failAt {
			return 400, nil, `{"error":{"code":"invalid_request","message":"boom"}}`
		}
		return 0, nil, ""
	}
	if _, err := Upload(context.Background(), c, path, *o); err == nil {
		t.Fatal("expected the fatal error")
	}
	s.Intercept = nil
	chunksBefore := s.Chunks

	file, err := Upload(context.Background(), c, path, *o)
	if err != nil {
		t.Fatal(err)
	}
	if got := s.Chunks - chunksBefore; got != 2 {
		t.Errorf("resume sent %d chunks, want the remaining 2", got)
	}
	if string(s.Content(file.ID)) != content {
		t.Fatal("content differs")
	}
}

func TestTransientErrorsAreRetriedWithBackoff(t *testing.T) {
	s, c := setup(t)
	o, sleeps := newOptions()
	failures := 0
	s.Intercept = func(r *http.Request) (int, http.Header, string) {
		if r.Method == http.MethodPatch && failures < 3 {
			failures++
			return 503, nil, "busy"
		}
		return 0, nil, ""
	}

	file, err := Upload(context.Background(), c, writeFile(t, "a.txt", content), *o)

	if err != nil || string(s.Content(file.ID)) != content {
		t.Fatalf("%v", err)
	}
	want := []time.Duration{time.Second, 2 * time.Second, 4 * time.Second}
	if len(*sleeps) != 3 || (*sleeps)[0] != want[0] || (*sleeps)[1] != want[1] || (*sleeps)[2] != want[2] {
		t.Fatalf("sleeps %v, want %v", *sleeps, want)
	}
}

func TestLockedHonoursRetryAfter(t *testing.T) {
	s, c := setup(t)
	o, sleeps := newOptions()
	locked := true
	s.Intercept = func(r *http.Request) (int, http.Header, string) {
		if r.Method == http.MethodPatch && locked {
			locked = false
			return 409, http.Header{"Retry-After": {"7"}}, `{"error":{"code":"locked","message":"busy"}}`
		}
		return 0, nil, ""
	}

	if _, err := Upload(context.Background(), c, writeFile(t, "a.txt", content), *o); err != nil {
		t.Fatal(err)
	}
	if len(*sleeps) != 1 || (*sleeps)[0] != 7*time.Second {
		t.Fatalf("sleeps %v", *sleeps)
	}
}

func TestLostAnswerOfLastChunkIsRecovered(t *testing.T) {
	s, c := setup(t)
	o, _ := newOptions()
	s.Intercept = func(r *http.Request) (int, http.Header, string) {
		return 0, nil, ""
	}
	// The server accepts the last chunk, but the answer is replaced by a gateway error.
	inner := s.Config.Handler
	s.Config.Handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodPatch && r.Header.Get("Upload-Offset") == "30" {
			inner.ServeHTTP(&discardWriter{}, r)
			http.Error(w, "gateway", http.StatusBadGateway)
			return
		}
		inner.ServeHTTP(w, r)
	})

	file, err := Upload(context.Background(), c, writeFile(t, "a.txt", content), *o)

	if err != nil || file.Status != api.StatusReady {
		t.Fatalf("%+v, %v", file, err)
	}
}

type discardWriter struct{ h http.Header }

func (d *discardWriter) Header() http.Header {
	if d.h == nil {
		d.h = http.Header{}
	}
	return d.h
}
func (d *discardWriter) Write(b []byte) (int, error) { return len(b), nil }
func (d *discardWriter) WriteHeader(int)             {}

func TestGivesUpAfterMaxRetries(t *testing.T) {
	s, c := setup(t)
	o, sleeps := newOptions()
	o.MaxRetries = 2
	s.Intercept = func(r *http.Request) (int, http.Header, string) {
		if r.Method == http.MethodPatch {
			return 500, nil, "no"
		}
		return 0, nil, ""
	}

	_, err := Upload(context.Background(), c, writeFile(t, "a.txt", content), *o)

	if err == nil || !strings.Contains(err.Error(), "giving up after 2 failed attempts") {
		t.Fatalf("got %v", err)
	}
	if len(*sleeps) != 2 {
		t.Fatalf("sleeps %v", *sleeps)
	}
}

func TestFatalErrorsAreNotRetried(t *testing.T) {
	for _, status := range []int{400, 401, 404, 413, 422, 507} {
		s, c := setup(t)
		o, sleeps := newOptions()
		s.Intercept = func(r *http.Request) (int, http.Header, string) {
			if r.Method == http.MethodPost {
				return status, nil, `{"error":{"code":"invalid_request","message":"no"}}`
			}
			return 0, nil, ""
		}
		_, err := Upload(context.Background(), c, writeFile(t, "a.txt", content), *o)
		var ae *api.Error
		if !errors.As(err, &ae) || ae.Status != status || len(*sleeps) != 0 {
			t.Errorf("status %d: err %v, sleeps %v", status, err, *sleeps)
		}
	}
}

func TestWrongTokenIsFatal(t *testing.T) {
	s, _ := setup(t)
	o, sleeps := newOptions()

	_, err := Upload(context.Background(), api.NewClient(s.URL, "wrong"), writeFile(t, "a.txt", content), *o)

	var ae *api.Error
	if !errors.As(err, &ae) || ae.Code != api.CodeUnauthorized || len(*sleeps) != 0 {
		t.Fatalf("%v %v", err, *sleeps)
	}
}

func TestChunkHashMismatchIsRetried(t *testing.T) {
	s, c := setup(t)
	o, _ := newOptions()
	corrupt := true
	inner := s.Config.Handler
	s.Config.Handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodPatch && corrupt {
			corrupt = false
			http.Error(w, `{"error":{"code":"chunk_hash_mismatch","message":"x","offset":0}}`, http.StatusUnprocessableEntity)
			return
		}
		inner.ServeHTTP(w, r)
	})

	if _, err := Upload(context.Background(), c, writeFile(t, "a.txt", content), *o); err != nil {
		t.Fatal(err)
	}
}

func TestContextCancelStopsUpload(t *testing.T) {
	_, c := setup(t)
	o, _ := newOptions()
	ctx, cancel := context.WithCancel(context.Background())
	o.OnProgress = func(done, _ int64) {
		if done >= 10 {
			cancel()
		}
	}

	_, err := Upload(ctx, c, writeFile(t, "a.txt", content), *o)

	if !errors.Is(err, context.Canceled) {
		t.Fatalf("got %v", err)
	}
}

func TestNotARegularFile(t *testing.T) {
	_, c := setup(t)
	o, _ := newOptions()
	if _, err := Upload(context.Background(), c, t.TempDir(), *o); err == nil {
		t.Fatal("directory must be rejected")
	}
	if _, err := Upload(context.Background(), c, filepath.Join(t.TempDir(), "missing"), *o); err == nil {
		t.Fatal("missing file must be rejected")
	}
}

func TestHashFile(t *testing.T) {
	sum := sha256.Sum256([]byte(content))
	got, err := hashFile(bytes.NewReader([]byte(content)), int64(len(content)), nil)
	if err != nil || got != hex.EncodeToString(sum[:]) {
		t.Fatalf("%q %v", got, err)
	}
}
