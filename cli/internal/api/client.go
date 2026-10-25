package api

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"hash"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/schorsch3000/Linkrot/internal/config"
)

// DigestLength is the length of the sha256 digest that follows every chunk.
const DigestLength = sha256.Size

const maxErrorBody = 64 << 10

// Client talks to one server with one token.
type Client struct {
	Base  string // https://host[/path], no trailing slash
	Token string
	HTTP  *http.Client
}

// NewClient creates a client with sensible timeouts (no overall timeout, uploads can be long).
func NewClient(base, token string) *Client {
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.ResponseHeaderTimeout = 2 * time.Minute
	return &Client{Base: strings.TrimRight(base, "/"), Token: token, HTTP: &http.Client{Transport: transport, CheckRedirect: refuseDowngrade}}
}

// refuseDowngrade stops a redirect from https to plain http (the token would be sent in the clear).
func refuseDowngrade(req *http.Request, via []*http.Request) error {
	if len(via) >= 10 {
		return errors.New("stopped after 10 redirects")
	}
	if !config.AllowedScheme(req.URL) {
		return fmt.Errorf("refusing redirect to plain http: %s", req.URL.Redacted())
	}
	return nil
}

// Health asks the server whether it is ready (GET /api/v1/health).
func (c *Client) Health(ctx context.Context) (*Health, error) {
	var h Health
	return &h, c.do(ctx, http.MethodGet, "/api/v1/health", nil, 0, "", nil, &h)
}

// Create starts an upload or resumes one. A completed file with the same hash yields an *Error with
// Code duplicate (carrying ID and URL).
func (c *Client) Create(ctx context.Context, req CreateRequest) (*File, error) {
	body, err := json.Marshal(req)
	if err != nil {
		return nil, err
	}
	var f File
	return &f, c.do(ctx, http.MethodPost, "/api/v1/files", bytes.NewReader(body), int64(len(body)), "application/json", nil, &f)
}

// Get returns one file.
func (c *Client) Get(ctx context.Context, id string) (*File, error) {
	var f File
	return &f, c.do(ctx, http.MethodGet, "/api/v1/files/"+url.PathEscape(id), nil, 0, "", nil, &f)
}

// List returns all live files, newest first.
func (c *Client) List(ctx context.Context) ([]File, error) {
	var out struct {
		Files []File `json:"files"`
	}
	if err := c.do(ctx, http.MethodGet, "/api/v1/files", nil, 0, "", nil, &out); err != nil {
		return nil, err
	}
	return out.Files, nil
}

// Patch changes metadata. Only non-nil members are sent; Uses may be set to null with ClearUses.
type Patch struct {
	Name      *string
	RmAfter   *string
	Uses      *int
	ClearUses bool
}

// Update changes the metadata of a file.
func (c *Client) Update(ctx context.Context, id string, p Patch) (*File, error) {
	m := map[string]any{}
	if p.Name != nil {
		m["name"] = *p.Name
	}
	if p.RmAfter != nil {
		m["rm_after"] = *p.RmAfter
	}
	switch {
	case p.Uses != nil:
		m["uses"] = *p.Uses
	case p.ClearUses:
		m["uses"] = nil
	}
	body, err := json.Marshal(m)
	if err != nil {
		return nil, err
	}
	var f File
	return &f, c.do(ctx, http.MethodPatch, "/api/v1/files/"+url.PathEscape(id), bytes.NewReader(body), int64(len(body)), "application/json", nil, &f)
}

// Delete removes a file.
func (c *Client) Delete(ctx context.Context, id string) error {
	return c.do(ctx, http.MethodDelete, "/api/v1/files/"+url.PathEscape(id), nil, 0, "", nil, nil)
}

// CleanupResult is the answer of the cleanup endpoint.
type CleanupResult struct {
	Ran          bool `json:"ran"`
	RowsDeleted  int  `json:"rows_deleted"`
	FilesDeleted int  `json:"files_deleted"`
}

// Cleanup asks the server to run its cleanup now.
func (c *Client) Cleanup(ctx context.Context) (*CleanupResult, error) {
	var r CleanupResult
	return &r, c.do(ctx, http.MethodPost, "/api/v1/cleanup", nil, 0, "", nil, &r)
}

// UploadChunk sends length bytes from data at offset. The body is the chunk followed by its sha256, which
// is computed while the bytes stream out. The result is either {offset} (more to come) or the finished file.
func (c *Client) UploadChunk(ctx context.Context, id string, offset int64, data io.Reader, length int64) (*File, error) {
	body := &chunkBody{r: io.LimitReader(data, length), h: sha256.New(), want: length}
	headers := map[string]string{"Upload-Offset": strconv.FormatInt(offset, 10)}
	var f File
	err := c.do(ctx, http.MethodPatch, "/api/v1/files/"+url.PathEscape(id)+"/content", body, length+DigestLength,
		"application/offset+octet-stream", headers, &f)
	if body.short {
		return nil, ErrSourceChanged
	}
	return &f, err
}

// ErrSourceChanged means the local file ended before the announced size: it was modified during the upload.
var ErrSourceChanged = errors.New("file was modified while uploading (it became shorter)")

// DownloadURL returns the URL of /dl for a file.
func (c *Client) DownloadURL(id, name string) string {
	return c.Base + "/dl/" + url.PathEscape(id) + "/" + url.PathEscape(name)
}

// Download starts a download; from > 0 asks for a range. The caller closes the body.
func (c *Client) Download(ctx context.Context, id, name string, from int64) (*http.Response, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.DownloadURL(id, name), nil)
	if err != nil {
		return nil, err
	}
	if from > 0 {
		req.Header.Set("Range", fmt.Sprintf("bytes=%d-", from))
	}
	resp, err := c.HTTP.Do(req) //nolint:bodyclose // returned to the caller
	if err != nil {
		return nil, err
	}
	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusPartialContent {
		defer func() { _ = resp.Body.Close() }()
		return nil, decodeError(resp)
	}
	return resp, nil
}

func (c *Client) do(ctx context.Context, method, path string, body io.Reader, length int64, contentType string, headers map[string]string, out any) error {
	req, err := http.NewRequestWithContext(ctx, method, c.Base+path, body)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+c.Token)
	req.Header.Set("Accept", "application/json")
	if contentType != "" {
		req.Header.Set("Content-Type", contentType)
	}
	if body != nil {
		req.ContentLength = length
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return err
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode >= 300 {
		return decodeError(resp)
	}
	if out == nil || resp.StatusCode == http.StatusNoContent {
		_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, maxErrorBody))
		return nil
	}
	if err := json.NewDecoder(resp.Body).Decode(out); err != nil {
		return fmt.Errorf("unexpected answer from server: %w", err)
	}
	return nil
}

func decodeError(resp *http.Response) error {
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, maxErrorBody))
	e := &Error{Status: resp.StatusCode}
	if s := resp.Header.Get("Retry-After"); s != "" {
		if secs, err := strconv.Atoi(s); err == nil && secs >= 0 {
			e.RetryAfter = time.Duration(secs) * time.Second
		}
	}
	var payload struct {
		Error struct {
			Code    string `json:"code"`
			Message string `json:"message"`
			Offset  *int64 `json:"offset"`
			ID      string `json:"id"`
			URL     string `json:"url"`
		} `json:"error"`
	}
	if json.Unmarshal(raw, &payload) == nil && payload.Error.Code != "" {
		e.Code, e.Message, e.Offset, e.ID, e.URL = payload.Error.Code, payload.Error.Message, payload.Error.Offset, payload.Error.ID, payload.Error.URL
		return e
	}
	e.Message = http.StatusText(resp.StatusCode)
	return e
}

// chunkBody streams chunk bytes and then their sha256 digest.
type chunkBody struct {
	r      io.Reader
	h      hash.Hash
	want   int64
	read   int64
	digest []byte
	pos    int
	short  bool
}

func (b *chunkBody) Read(p []byte) (int, error) {
	if b.digest == nil {
		n, err := b.r.Read(p)
		b.h.Write(p[:n])
		b.read += int64(n)
		if err != nil {
			if !errors.Is(err, io.EOF) {
				return n, err
			}
			if b.read != b.want {
				b.short = true
				return n, io.ErrUnexpectedEOF
			}
			b.digest = b.h.Sum(nil)
		}
		if n > 0 || b.digest == nil {
			return n, nil
		}
	}
	if b.pos >= len(b.digest) {
		return 0, io.EOF
	}
	n := copy(p, b.digest[b.pos:])
	b.pos += n
	return n, nil
}
