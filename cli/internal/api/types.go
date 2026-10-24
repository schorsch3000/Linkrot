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
