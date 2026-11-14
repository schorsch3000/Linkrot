// Package api is the client for the Linkrot HTTP API (docs/API.md).
package api

import (
	"fmt"
	"time"
)

// File is the file object of the API. ChunkSize is only set in answers to create requests.
type File struct {
	ID          string `json:"id"`
	Name        string `json:"name"`
	Size        int64  `json:"size"`
	SHA256      string `json:"sha256"`
	Status      string `json:"status"`
	Offset      int64  `json:"offset"`
	CreatedAt   int64  `json:"created_at"`
	CompletedAt *int64 `json:"completed_at"`
	ExpiresAt   *int64 `json:"expires_at"`
	UsesLeft    *int64 `json:"uses_left"`
	URL         string `json:"url,omitempty"`
	ChunkSize   int64  `json:"chunk_size,omitempty"`
}

// Quota is the storage quota of the server: what the live files and running uploads occupy, and the limit
// (nil if the host has none).
type Quota struct {
	Used  int64  `json:"used"`
	Limit *int64 `json:"limit"`
}

// Status values of File.
const (
	StatusUploading = "uploading"
	StatusReady     = "ready"
)

// CreateRequest starts or resumes an upload.
type CreateRequest struct {
	Name    string `json:"name"`
	Size    int64  `json:"size"`
	SHA256  string `json:"sha256"`
	RmAfter string `json:"rm_after,omitempty"`
	Uses    *int   `json:"uses,omitempty"`
}

// CreateStreamRequest starts a streaming upload: size and sha256 are not known yet and are given to Seal.
type CreateStreamRequest struct {
	Name    string `json:"name"`
	Stream  bool   `json:"stream"`
	RmAfter string `json:"rm_after,omitempty"`
	Uses    *int   `json:"uses,omitempty"`
}

// SealRequest completes a streaming upload.
type SealRequest struct {
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256"`
}

// Error codes of the API.
const (
	CodeDuplicate         = "duplicate"
	CodeOffsetMismatch    = "offset_mismatch"
	CodeLocked            = "locked"
	CodeChunkHashMismatch = "chunk_hash_mismatch"
	CodeFileHashMismatch  = "file_hash_mismatch"
	CodeNotFound          = "not_found"
	CodeUnauthorized      = "unauthorized"
	CodeRateLimited       = "rate_limited"
)

// Error is an error answer of the server.
type Error struct {
	Status     int
	Code       string
	Message    string
	Offset     *int64 // offset_mismatch, chunk_hash_mismatch: the current server offset
	ID         string // duplicate: the existing file
	URL        string // duplicate: its URL
	RetryAfter time.Duration
}

func (e *Error) Error() string {
	if e.Code == "" {
		return fmt.Sprintf("server answered HTTP %d: %s", e.Status, e.Message)
	}
	return fmt.Sprintf("server: %s (%s)", e.Message, e.Code)
}

// Retryable reports whether trying the same request again later may succeed.
func (e *Error) Retryable() bool {
	switch {
	case e.Code == CodeLocked, e.Code == CodeRateLimited:
		return true
	case e.Status == 429, e.Status >= 500 && e.Status != 507:
		return true
	default:
		return false
	}
}

// Health levels of GET /api/v1/health.
const (
	LevelOK    = "ok"
	LevelWarn  = "warn"
	LevelError = "error"
)

// HealthCheck is one line of the health report.
type HealthCheck struct {
	Name    string `json:"name"`
	Level   string `json:"level"`
	Message string `json:"message"`
}

// Health is the answer of GET /api/v1/health: the worst level of all checks, and the checks.
type Health struct {
	Status string        `json:"status"`
	Checks []HealthCheck `json:"checks"`
}

// SettingKeys are the settings of a host in the order the server lists them. All are booleans.
var SettingKeys = []string{
	"show_taglines",
	"show_startpage",
	"show_title",
	"show_linkrot_line",
	"show_curl_line",
	"show_hash_line",
}

// CustomNames are the files a host can replace with `custom put`.
var CustomNames = []string{"taglines", "headerlines", "css"}

// CustomResult is the answer to replacing a customizable file: the number of lines in use (taglines, headerlines) or the
// URL of the stylesheet (css).
type CustomResult struct {
	Lines int    `json:"lines"`
	URL   string `json:"url"`
}

// Page is one static page of the host, as `GET /api/v1/pages` lists it.
type Page struct {
	Slug      string `json:"slug"`
	Title     string `json:"title"`
	Link      string `json:"link"`
	Order     int    `json:"order"`
	Published bool   `json:"published"`
	Lang      string `json:"lang"`
	URL       string `json:"url"`
	UpdatedAt int64  `json:"updated_at"`
}
