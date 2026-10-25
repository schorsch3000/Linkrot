package api

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// recorded is one request as the server saw it.
type recorded struct {
	method, path, contentType, offset, auth string
	body                                    []byte
}

// newRecordingServer answers every request with status and body and remembers the requests.
func newRecordingServer(t *testing.T, status int, answer string) (*Client, *[]recorded) {
	t.Helper()
	var seen []recorded
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		seen = append(seen, recorded{r.Method, r.URL.EscapedPath(), r.Header.Get("Content-Type"), r.Header.Get("Upload-Offset"), r.Header.Get("Authorization"), body})
		w.WriteHeader(status)
		_, _ = io.WriteString(w, answer)
	}))
	t.Cleanup(srv.Close)
	return NewClient(srv.URL+"/", "lr_tok"), &seen
}

func TestCreateSendsTheJSONDocumentedInTheAPI(t *testing.T) {
	c, seen := newRecordingServer(t, 201, `{"id":"abcd","name":"a.txt","size":3,"status":"uploading","offset":0,"chunk_size":10}`)
	uses := 2

	f, err := c.Create(context.Background(), CreateRequest{Name: "a.txt", Size: 3, SHA256: "ab", RmAfter: "2d", Uses: &uses})

	if err != nil || f.ID != "abcd" {
		t.Fatalf("%+v, %v", f, err)
	}
	r := (*seen)[0]
	if r.method != "POST" || r.path != "/api/v1/files" || r.contentType != "application/json" || r.auth != "Bearer lr_tok" {
		t.Errorf("request %+v", r)
	}
	var sent map[string]any
	if err := json.Unmarshal(r.body, &sent); err != nil {
		t.Fatal(err)
	}
	if sent["name"] != "a.txt" || sent["size"] != float64(3) || sent["sha256"] != "ab" || sent["rm_after"] != "2d" || sent["uses"] != float64(2) {
		t.Errorf("body %s", r.body)
	}
}

func TestCreateOmitsOptionalMembers(t *testing.T) {
	c, seen := newRecordingServer(t, 201, `{}`)

	if _, err := c.Create(context.Background(), CreateRequest{Name: "a", SHA256: "ab"}); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string((*seen)[0].body), "rm_after") || strings.Contains(string((*seen)[0].body), "uses") {
		t.Errorf("body %s", (*seen)[0].body)
	}
}

func TestListDeleteCleanupHealthAndGet(t *testing.T) {
	ctx := context.Background()

	c, seen := newRecordingServer(t, 200, `{"files":[{"id":"a1","name":"x"},{"id":"b2","name":"y"}]}`)
	files, err := c.List(ctx)
	if err != nil || len(files) != 2 || files[1].ID != "b2" || (*seen)[0].path != "/api/v1/files" {
		t.Errorf("List: %+v, %v, %+v", files, err, *seen)
	}

	c, seen = newRecordingServer(t, 204, ``)
	if err := c.Delete(ctx, "a/b"); err != nil {
		t.Errorf("Delete: %v", err)
	}
	if r := (*seen)[0]; r.method != "DELETE" || r.path != "/api/v1/files/a%2Fb" {
		t.Errorf("Delete request %+v", r)
	}

	c, seen = newRecordingServer(t, 200, `{"ran":true,"rows_deleted":3,"files_deleted":2}`)
	res, err := c.Cleanup(ctx)
	if err != nil || !res.Ran || res.RowsDeleted != 3 || res.FilesDeleted != 2 || (*seen)[0].method != "POST" || (*seen)[0].path != "/api/v1/cleanup" {
		t.Errorf("Cleanup: %+v, %v", res, err)
	}

	c, seen = newRecordingServer(t, 200, `{"status":"warn","checks":[{"name":"disk","level":"warn","message":"low"}]}`)
	h, err := c.Health(ctx)
	if err != nil || h.Status != LevelWarn || len(h.Checks) != 1 || h.Checks[0].Name != "disk" || (*seen)[0].path != "/api/v1/health" {
		t.Errorf("Health: %+v, %v", h, err)
	}

	c, _ = newRecordingServer(t, 200, `{"id":"a1","name":"x","size":4,"status":"ready"}`)
	f, err := c.Get(ctx, "a1")
	if err != nil || f.Status != StatusReady {
		t.Errorf("Get: %+v, %v", f, err)
	}
}

func TestUploadChunkSendsOffsetContentTypeAndTrailingDigest(t *testing.T) {
	c, seen := newRecordingServer(t, 200, `{"offset":10}`)

	f, err := c.UploadChunk(context.Background(), "abcd", 20, strings.NewReader("0123456789-and-more"), 10)

	if err != nil || f.Offset != 10 {
		t.Fatalf("%+v, %v", f, err)
	}
	r := (*seen)[0]
	if r.method != "PATCH" || r.path != "/api/v1/files/abcd/content" || r.offset != "20" || r.contentType != "application/offset+octet-stream" {
		t.Errorf("request %+v", r)
	}
	if len(r.body) != 10+DigestLength || string(r.body[:10]) != "0123456789" {
		t.Errorf("body is the chunk plus its digest, got %d bytes", len(r.body))
	}
}

func TestUploadChunkOfAShortSourceIsReported(t *testing.T) {
	c, _ := newRecordingServer(t, 200, `{}`)

	_, err := c.UploadChunk(context.Background(), "abcd", 0, strings.NewReader("abc"), 10)

	if !errors.Is(err, ErrSourceChanged) {
		t.Fatalf("got %v", err)
	}
}

func TestAnswerThatIsNotJSONIsAnError(t *testing.T) {
	c, _ := newRecordingServer(t, 200, `<html>proxy error page</html>`)

	if _, err := c.List(context.Background()); err == nil || !strings.Contains(err.Error(), "unexpected answer from server") {
		t.Fatalf("got %v", err)
	}
}

func TestErrorAnswersCarryTheirCodeAndAreClassified(t *testing.T) {
	cases := []struct {
		status    int
		body      string
		code      string
		retryable bool
	}{
		{409, `{"error":{"code":"locked","message":"busy"}}`, CodeLocked, true},
		{429, `{"error":{"code":"rate_limited","message":"slow down"}}`, CodeRateLimited, true},
		{502, `bad gateway`, "", true},
		{507, `{"error":{"code":"insufficient_storage","message":"full"}}`, "insufficient_storage", false},
		{401, `{"error":{"code":"unauthorized","message":"no"}}`, CodeUnauthorized, false},
		{404, `{"error":{"code":"not_found","message":"gone"}}`, CodeNotFound, false},
	}
	for _, tc := range cases {
		c, _ := newRecordingServer(t, tc.status, tc.body)
		err := c.Delete(context.Background(), "x")
		var ae *Error
		if !errors.As(err, &ae) {
			t.Fatalf("%d: %v", tc.status, err)
		}
		if ae.Status != tc.status || ae.Code != tc.code || ae.Retryable() != tc.retryable {
			t.Errorf("%d: %+v retryable=%v", tc.status, ae, ae.Retryable())
		}
	}
}

func TestErrorMessages(t *testing.T) {
	if got := (&Error{Status: 502, Message: "Bad Gateway"}).Error(); got != "server answered HTTP 502: Bad Gateway" {
		t.Errorf("without a code: %q", got)
	}
	if got := (&Error{Status: 409, Code: CodeLocked, Message: "busy"}).Error(); got != "server: busy (locked)" {
		t.Errorf("with a code: %q", got)
	}
}
