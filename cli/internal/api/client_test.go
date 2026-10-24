package api

import (
	"bytes"
	"crypto/sha256"
	"errors"
	"io"
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
