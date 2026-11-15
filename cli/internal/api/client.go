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
	"net"
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
	// IdleTimeout is how long a connection may go without a single byte moving, in either direction, before the request
	// fails (and is retried by the callers). Zero means the default.
	IdleTimeout time.Duration
}

// defaultIdleTimeout is how long a transfer may stand completely still. Retry and resume only help if a stuck connection
// is given up on.
const defaultIdleTimeout = time.Minute

// NewClient creates a client with sensible timeouts: no overall timeout (uploads and downloads can be long), but a
// connection that does not move a single byte for IdleTimeout fails, whatever it was doing.
func NewClient(base, token string) *Client {
	c := &Client{Base: strings.TrimRight(base, "/"), Token: token, IdleTimeout: defaultIdleTimeout}
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.ResponseHeaderTimeout = 2 * time.Minute
	dialer := &net.Dialer{Timeout: 30 * time.Second, KeepAlive: 30 * time.Second}
	transport.DialContext = func(ctx context.Context, network, addr string) (net.Conn, error) {
		conn, err := dialer.DialContext(ctx, network, addr)
		if err != nil {
			return nil, err
		}
		return &idleConn{Conn: conn, idle: c.idleTimeout}, nil
	}
	c.HTTP = &http.Client{Transport: transport, CheckRedirect: refuseDowngrade}
	return c
}

func (c *Client) idleTimeout() time.Duration {
	if c.IdleTimeout <= 0 {
		return defaultIdleTimeout
	}
	return c.IdleTimeout
}

// idleConn gives every read and write a deadline that moves along with the traffic, so only a connection on which
// nothing happens for the whole idle time fails.
type idleConn struct {
	net.Conn
	idle func() time.Duration
}

func (c *idleConn) Read(p []byte) (int, error) {
	_ = c.SetReadDeadline(time.Now().Add(c.idle()))
	return c.Conn.Read(p)
}

func (c *idleConn) Write(p []byte) (int, error) {
	_ = c.SetWriteDeadline(time.Now().Add(c.idle()))
	return c.Conn.Write(p)
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

// CreateStream starts a streaming upload (no size, no hash yet); Stream is set for the caller.
func (c *Client) CreateStream(ctx context.Context, req CreateStreamRequest) (*File, error) {
	req.Stream = true
	body, err := json.Marshal(req)
	if err != nil {
		return nil, err
	}
	var f File
	return &f, c.do(ctx, http.MethodPost, "/api/v1/files", bytes.NewReader(body), int64(len(body)), "application/json", nil, &f)
}

// Seal completes a streaming upload: the server compares size and sha256 with what it received.
func (c *Client) Seal(ctx context.Context, id string, size int64, sha256 string) (*File, error) {
	body, err := json.Marshal(SealRequest{Size: size, SHA256: sha256})
	if err != nil {
		return nil, err
	}
	var f File
	return &f, c.do(ctx, http.MethodPost, "/api/v1/files/"+url.PathEscape(id)+"/seal", bytes.NewReader(body), int64(len(body)), "application/json", nil, &f)
}

// Get returns one file.
func (c *Client) Get(ctx context.Context, id string) (*File, error) {
	var f File
	return &f, c.do(ctx, http.MethodGet, "/api/v1/files/"+url.PathEscape(id), nil, 0, "", nil, &f)
}

// List returns all live files, newest first.
func (c *Client) List(ctx context.Context) ([]File, error) {
	files, _, err := c.ListWithQuota(ctx)
	return files, err
}

// ListWithQuota returns all live files and the quota of the server (nil for a server that does not report it).
func (c *Client) ListWithQuota(ctx context.Context) ([]File, *Quota, error) {
	var out struct {
		Files []File `json:"files"`
		Quota *Quota `json:"quota"`
	}
	if err := c.do(ctx, http.MethodGet, "/api/v1/files", nil, 0, "", nil, &out); err != nil {
		return nil, nil, err
	}
	return out.Files, out.Quota, nil
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
	if c.Token != "" {
		req.Header.Set("Authorization", "Bearer "+c.Token)
	}
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
	e.RetryAfter = parseRetryAfter(resp.Header.Get("Retry-After"), time.Now())
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

// Settings returns the settings of the host (GET /api/v1/settings).
func (c *Client) Settings(ctx context.Context) (map[string]bool, error) {
	var out struct {
		Settings map[string]bool `json:"settings"`
	}
	if err := c.do(ctx, http.MethodGet, "/api/v1/settings", nil, 0, "", nil, &out); err != nil {
		return nil, err
	}
	return out.Settings, nil
}

// UpdateSettings changes the given settings and returns all of them (PATCH /api/v1/settings).
func (c *Client) UpdateSettings(ctx context.Context, changes map[string]bool) (map[string]bool, error) {
	body, err := json.Marshal(changes)
	if err != nil {
		return nil, err
	}
	var out struct {
		Settings map[string]bool `json:"settings"`
	}
	if err := c.do(ctx, http.MethodPatch, "/api/v1/settings", bytes.NewReader(body), int64(len(body)), "application/json", nil, &out); err != nil {
		return nil, err
	}
	return out.Settings, nil
}

// getRaw fetches a text answer (not JSON) of the API.
func (c *Client) getRaw(ctx context.Context, path string) ([]byte, http.Header, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.Base+path, nil)
	if err != nil {
		return nil, nil, err
	}
	req.Header.Set("Authorization", "Bearer "+c.Token)
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, nil, err
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode >= 300 {
		return nil, nil, decodeError(resp)
	}
	content, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	return content, resp.Header, err
}

// GetCustom downloads one of the customizable files (GET /api/v1/custom/{name}): what the host uploaded, or the built-in
// defaults. customized tells which.
func (c *Client) GetCustom(ctx context.Context, name string) (content []byte, customized bool, err error) {
	content, header, err := c.getRaw(ctx, "/api/v1/custom/"+url.PathEscape(name))
	if err != nil {
		return nil, false, err
	}
	return content, header.Get("X-Customized") == "true", nil
}

// PutCustom replaces one of the customizable files (PUT /api/v1/custom/{name}).
func (c *Client) PutCustom(ctx context.Context, name string, content []byte) (*CustomResult, error) {
	var out CustomResult
	err := c.do(ctx, http.MethodPut, "/api/v1/custom/"+url.PathEscape(name), bytes.NewReader(content), int64(len(content)), "text/plain; charset=utf-8", nil, &out)
	return &out, err
}

// ResetCustom goes back to the built-in defaults (DELETE /api/v1/custom/{name}).
func (c *Client) ResetCustom(ctx context.Context, name string) error {
	return c.do(ctx, http.MethodDelete, "/api/v1/custom/"+url.PathEscape(name), nil, 0, "", nil, nil)
}

// Pages lists the static pages of the host in the order of the footer (GET /api/v1/pages).
func (c *Client) Pages(ctx context.Context) ([]Page, error) {
	var out struct {
		Pages []Page `json:"pages"`
	}
	if err := c.do(ctx, http.MethodGet, "/api/v1/pages", nil, 0, "", nil, &out); err != nil {
		return nil, err
	}
	return out.Pages, nil
}

// GetPage downloads the Markdown of a page exactly as it was uploaded, or with html its rendered body.
func (c *Client) GetPage(ctx context.Context, slug string, html bool) ([]byte, error) {
	path := "/api/v1/pages/" + url.PathEscape(slug)
	if html {
		path += "?format=html"
	}
	content, _, err := c.getRaw(ctx, path)
	return content, err
}

// PutPage uploads a page (PUT /api/v1/pages/{slug}); the server renders it and refuses what is not allowed.
func (c *Client) PutPage(ctx context.Context, slug string, content []byte) (*Page, error) {
	var out Page
	err := c.do(ctx, http.MethodPut, "/api/v1/pages/"+url.PathEscape(slug), bytes.NewReader(content), int64(len(content)), "text/markdown; charset=utf-8", nil, &out)
	return &out, err
}

// DeletePage removes a page; a page that is not there is fine.
func (c *Client) DeletePage(ctx context.Context, slug string) error {
	return c.do(ctx, http.MethodDelete, "/api/v1/pages/"+url.PathEscape(slug), nil, 0, "", nil, nil)
}

// PageTemplate downloads a page to start from: every key of the frontmatter and some dummy content.
func (c *Client) PageTemplate(ctx context.Context) ([]byte, error) {
	content, _, err := c.getRaw(ctx, "/api/v1/page-template")
	return content, err
}

// MaxRetryAfter caps what a server can ask us to wait: a wrong or hostile header must not park the client for hours.
const MaxRetryAfter = 5 * time.Minute

// parseRetryAfter reads a Retry-After header: a number of seconds or an HTTP date. Zero if it is missing or unusable
// (and for a date that has passed); never more than MaxRetryAfter.
func parseRetryAfter(s string, now time.Time) time.Duration {
	s = strings.TrimSpace(s)
	if secs, err := strconv.ParseInt(s, 10, 64); err == nil {
		switch {
		case secs <= 0:
			return 0
		case secs >= int64(MaxRetryAfter/time.Second): // also keeps the multiplication from overflowing
			return MaxRetryAfter
		}
		return time.Duration(secs) * time.Second
	}
	if t, err := http.ParseTime(s); err == nil {
		return min(max(t.Sub(now), 0), MaxRetryAfter)
	}
	return 0
}

// AuthStart asks the server to mail a code to email (POST /api/v1/auth/start). The server answers alike whether or not
// the address belongs to the host, and takes a few seconds to do so. The client needs no token.
func (c *Client) AuthStart(ctx context.Context, email string) (*AuthChallenge, error) {
	body, err := json.Marshal(map[string]string{"email": email})
	if err != nil {
		return nil, err
	}
	var out AuthChallenge
	return &out, c.do(ctx, http.MethodPost, "/api/v1/auth/start", bytes.NewReader(body), int64(len(body)), "application/json", nil, &out)
}

// AuthVerify trades the code from the mail for a management session. Wrong, used and expired codes are all an *Error
// with Code invalid_code.
func (c *Client) AuthVerify(ctx context.Context, challenge, code string) (*AuthSession, error) {
	body, err := json.Marshal(map[string]string{"challenge": challenge, "code": code})
	if err != nil {
		return nil, err
	}
	var out AuthSession
	return &out, c.do(ctx, http.MethodPost, "/api/v1/auth/verify", bytes.NewReader(body), int64(len(body)), "application/json", nil, &out)
}

// Tokens lists the API tokens of the host. Needs a management session as the client's Token.
func (c *Client) Tokens(ctx context.Context) ([]Token, error) {
	var out struct {
		Tokens []Token `json:"tokens"`
	}
	if err := c.do(ctx, http.MethodGet, "/api/v1/tokens", nil, 0, "", nil, &out); err != nil {
		return nil, err
	}
	return out.Tokens, nil
}

// CreateToken makes a token with the same rights as any other and returns it; this is the only time it is shown.
func (c *Client) CreateToken(ctx context.Context, name string) (string, error) {
	body, err := json.Marshal(map[string]string{"name": name})
	if err != nil {
		return "", err
	}
	var out struct {
		Token string `json:"token"`
	}
	if err := c.do(ctx, http.MethodPost, "/api/v1/tokens", bytes.NewReader(body), int64(len(body)), "application/json", nil, &out); err != nil {
		return "", err
	}
	return out.Token, nil
}

// DeleteToken revokes a token.
func (c *Client) DeleteToken(ctx context.Context, name string) error {
	return c.do(ctx, http.MethodDelete, "/api/v1/tokens/"+url.PathEscape(name), nil, 0, "", nil, nil)
}
