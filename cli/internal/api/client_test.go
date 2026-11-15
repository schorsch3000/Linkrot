package api

import (
	"bytes"
	"context"
	"crypto/sha256"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"testing/iotest"
	"time"
)

func TestChunkBodyAppendsDigest(t *testing.T) {
	data := []byte("0123456789")
	sum := sha256.Sum256(data)
	// OneByteReader and DataErrReader exercise short reads and EOF together with data.
	for _, src := range []io.Reader{bytes.NewReader(data), iotest.OneByteReader(bytes.NewReader(data)), iotest.DataErrReader(bytes.NewReader(data))} {
		b := &chunkBody{r: io.LimitReader(src, 10), h: sha256.New(), want: 10}
		got, err := io.ReadAll(b)
		if err != nil {
			t.Fatal(err)
		}
		if !bytes.Equal(got[:10], data) || !bytes.Equal(got[10:], sum[:]) || len(got) != 10+DigestLength {
			t.Fatalf("body %x", got)
		}
	}
}

func TestChunkBodyDetectsShortSource(t *testing.T) {
	b := &chunkBody{r: io.LimitReader(strings.NewReader("abc"), 10), h: sha256.New(), want: 10}
	if _, err := io.ReadAll(b); !errors.Is(err, io.ErrUnexpectedEOF) || !b.short {
		t.Fatalf("err %v short %v", err, b.short)
	}
}

func TestErrorDecoding(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/v1/files/dup":
			w.WriteHeader(409)
			_, _ = io.WriteString(w, `{"error":{"code":"duplicate","message":"exists","id":"abcd","url":"https://x/file/abcd/a"}}`)
		case "/api/v1/files/slow":
			w.Header().Set("Retry-After", "9")
			w.WriteHeader(429)
			_, _ = io.WriteString(w, `{"error":{"code":"rate_limited","message":"slow down"}}`)
		case "/api/v1/files/html":
			w.WriteHeader(502)
			_, _ = io.WriteString(w, "<html>Bad Gateway</html>")
		case "/api/v1/files/off":
			w.WriteHeader(409)
			_, _ = io.WriteString(w, `{"error":{"code":"offset_mismatch","message":"m","offset":42}}`)
		}
	}))
	defer srv.Close()
	c := NewClient(srv.URL+"/", "tok")
	ctx := t.Context()

	var e *Error
	_, err := c.Get(ctx, "dup")
	if !errors.As(err, &e) || e.Code != CodeDuplicate || e.ID != "abcd" || e.URL == "" || e.Retryable() {
		t.Fatalf("dup: %+v", e)
	}
	_, err = c.Get(ctx, "slow")
	if !errors.As(err, &e) || e.RetryAfter != 9*time.Second || !e.Retryable() {
		t.Fatalf("slow: %+v", e)
	}
	_, err = c.Get(ctx, "html")
	if !errors.As(err, &e) || e.Status != 502 || e.Code != "" || !e.Retryable() || !strings.Contains(e.Error(), "502") {
		t.Fatalf("html: %+v", e)
	}
	_, err = c.Get(ctx, "off")
	if !errors.As(err, &e) || e.Offset == nil || *e.Offset != 42 {
		t.Fatalf("off: %+v", e)
	}
}

func TestRequestsCarryTokenAndEscapeIDs(t *testing.T) {
	var gotAuth, gotPath, gotBody, gotMethod string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotAuth, gotPath, gotMethod = r.Header.Get("Authorization"), r.URL.EscapedPath(), r.Method
		b, _ := io.ReadAll(r.Body)
		gotBody = string(b)
		_, _ = io.WriteString(w, `{"id":"x"}`)
	}))
	defer srv.Close()
	c := NewClient(srv.URL, "lr_tok")

	name, uses := "new name", 3
	if _, err := c.Update(t.Context(), "a/b", Patch{Name: &name, Uses: &uses}); err != nil {
		t.Fatal(err)
	}
	if gotAuth != "Bearer lr_tok" || gotPath != "/api/v1/files/a%2Fb" || gotMethod != "PATCH" {
		t.Fatalf("%q %q %q", gotAuth, gotPath, gotMethod)
	}
	if gotBody != `{"name":"new name","uses":3}` {
		t.Fatalf("body %s", gotBody)
	}
	if _, err := c.Update(t.Context(), "x", Patch{ClearUses: true}); err != nil || gotBody != `{"uses":null}` {
		t.Fatalf("%v %s", err, gotBody)
	}
}

func TestDownloadURLAndRange(t *testing.T) {
	var gotRange string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotRange = r.Header.Get("Range")
		if strings.HasSuffix(r.URL.Path, "gone") {
			http.NotFound(w, r)
			return
		}
		_, _ = io.WriteString(w, "data")
	}))
	defer srv.Close()
	c := NewClient(srv.URL, "t")

	if got := c.DownloadURL("id1", "a b.txt"); got != srv.URL+"/dl/id1/a%20b.txt" {
		t.Fatal(got)
	}
	resp, err := c.Download(t.Context(), "id1", "a.txt", 5)
	if err != nil {
		t.Fatal(err)
	}
	_ = resp.Body.Close()
	if gotRange != "bytes=5-" {
		t.Fatalf("range %q", gotRange)
	}
	var e *Error
	gone, err := c.Download(t.Context(), "id1", "gone", 0)
	if gone != nil {
		_ = gone.Body.Close()
	}
	if !errors.As(err, &e) || e.Status != 404 {
		t.Fatalf("got %v", err)
	}
}

func TestRedirectToPlainHTTPIsRefused(t *testing.T) {
	req := func(raw string) *http.Request {
		r, err := http.NewRequestWithContext(context.Background(), http.MethodGet, raw, nil)
		if err != nil {
			t.Fatal(err)
		}
		return r
	}
	if err := refuseDowngrade(req("http://linkrot.example/x"), nil); err == nil {
		t.Error("https to http was followed")
	}
	if err := refuseDowngrade(req("https://linkrot.example/x"), nil); err != nil {
		t.Errorf("https redirect refused: %v", err)
	}
	if err := refuseDowngrade(req("http://127.0.0.1:8080/x"), nil); err != nil {
		t.Errorf("loopback redirect refused: %v", err)
	}
	if err := refuseDowngrade(req("https://linkrot.example/x"), make([]*http.Request, 10)); err == nil {
		t.Error("redirect loop not stopped")
	}
}

// stallingServer answers like the handler, except that a request for which stall returns true gets its first bytes (if
// any) and then silence until the test is over: a server or middlebox that went quiet with the connection still open.
func stallingServer(t *testing.T, stall func(r *http.Request) bool, inner http.Handler) *httptest.Server {
	t.Helper()
	gate := make(chan struct{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if stall(r) {
			_, _ = io.WriteString(w, `{"status":"ok"`) // the headers and a part of the body are out
			w.(http.Flusher).Flush()
			<-gate
			return
		}
		inner.ServeHTTP(w, r)
	}))
	t.Cleanup(srv.Close)
	t.Cleanup(func() { close(gate) }) // runs first: the stuck handlers end, then the server can close
	return srv
}

func TestSilentServerEndsARequestAfterTheIdleTimeout(t *testing.T) {
	srv := stallingServer(t, func(*http.Request) bool { return true }, nil)
	c := NewClient(srv.URL, "tok")
	c.IdleTimeout = 150 * time.Millisecond
	ctx, cancel := context.WithTimeout(t.Context(), 3*time.Second) // safety net: without an idle timeout this is what ends it
	defer cancel()

	start := time.Now()
	_, err := c.Health(ctx)

	if err == nil || ctx.Err() != nil {
		t.Fatalf("err %v after %v, want the request to give up by itself", err, time.Since(start))
	}
	if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("%v: this is the safety net, not the idle timeout", err)
	}
	var ne net.Error
	if !errors.As(err, &ne) || !ne.Timeout() {
		t.Errorf("%v is not a timeout error", err)
	}
}

func TestBytesThatKeepTricklingResetTheIdleTimeout(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		for _, part := range []string{`{"status"`, `:"o`, `k"}`} {
			_, _ = io.WriteString(w, part)
			w.(http.Flusher).Flush()
			time.Sleep(100 * time.Millisecond) // slower than nothing, faster than the idle timeout; the sum is longer than it
		}
	}))
	t.Cleanup(srv.Close)
	c := NewClient(srv.URL, "tok")
	c.IdleTimeout = 250 * time.Millisecond

	h, err := c.Health(t.Context())

	if err != nil || h.Status != "ok" {
		t.Fatalf("%+v %v", h, err)
	}
}
