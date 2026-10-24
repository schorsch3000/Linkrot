// Package upload implements the resumable chunked upload protocol (docs/API.md).
package upload

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
)

// DuplicateError is returned if the server already has the file.
type DuplicateError struct {
	ID  string
	URL string
}

func (e *DuplicateError) Error() string {
	return fmt.Sprintf("this file already exists on the server (%s)", e.ID)
}

// Options control an upload.
type Options struct {
	Name    string // name on the server; default is the base name of the file
	RmAfter string // lifetime counted from completion; empty = server default
	Uses    *int   // download limit; nil = server default

	OnHash     func(done, total int64) // progress while hashing
	OnProgress func(done, total int64) // progress while uploading
	OnRetry    func(err error, wait time.Duration)

	MaxRetries int                                              // consecutive failures tolerated; default 8
	Sleep      func(ctx context.Context, d time.Duration) error // replaceable for tests
}

// Upload sends the file and returns the finished file object. An interrupted upload can be resumed by
// running it again, the server recognises the file by its hash.
func Upload(ctx context.Context, c *api.Client, path string, o Options) (*api.File, error) {
	if o.MaxRetries == 0 {
		o.MaxRetries = 8
	}
	if o.Sleep == nil {
		o.Sleep = sleep
	}
	f, err := os.Open(path) //nolint:gosec // the user names the file to upload
	if err != nil {
		return nil, err
	}
	defer func() { _ = f.Close() }()
	info, err := f.Stat()
	if err != nil {
		return nil, err
	}
	if !info.Mode().IsRegular() {
		return nil, fmt.Errorf("%s is not a regular file", path)
	}
	size := info.Size()
	if o.Name == "" {
		o.Name = filepath.Base(path)
	}

	sum, err := hashFile(f, size, o.OnHash)
	if err != nil {
		return nil, fmt.Errorf("hash %s: %w", path, err)
	}

	var file *api.File
	err = retry(ctx, &o, func() error {
		var err error
		file, err = c.Create(ctx, api.CreateRequest{Name: o.Name, Size: size, SHA256: sum, RmAfter: o.RmAfter, Uses: o.Uses})
		return err
	})
	if err != nil {
		var ae *api.Error
		if errors.As(err, &ae) && ae.Code == api.CodeDuplicate {
			return nil, &DuplicateError{ID: ae.ID, URL: ae.URL}
		}
		return nil, err
	}
	if file.Status == api.StatusReady {
		return file, nil
	}
	if file.ChunkSize <= 0 {
		return nil, errors.New("server did not announce a chunk size")
	}
	return sendChunks(ctx, c, f, size, file, &o)
}

func sendChunks(ctx context.Context, c *api.Client, f *os.File, size int64, file *api.File, o *Options) (*api.File, error) {
	offset := file.Offset
	report := func(done int64) {
		if o.OnProgress != nil {
			o.OnProgress(done, size)
		}
	}
	report(offset)
	failures := 0
	for {
		n := min(file.ChunkSize, size-offset)
		body := &countingReader{r: io.NewSectionReader(f, offset, n), base: offset, report: report}
		res, err := c.UploadChunk(ctx, file.ID, offset, body, n)
		if err == nil {
			failures = 0
			if res.Status == api.StatusReady {
				report(size)
				return res, nil
			}
			offset = res.Offset
			report(offset)
			continue
		}
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		var ae *api.Error
		switch {
		case errors.Is(err, api.ErrSourceChanged):
			return nil, err
		case errors.As(err, &ae) && ae.Code == api.CodeOffsetMismatch && ae.Offset != nil && failures < o.MaxRetries:
			// Somebody else (or a lost answer) moved the server on: continue where it is.
			failures++
			offset = *ae.Offset
			report(offset)
			continue
		case errors.As(err, &ae) && !ae.Retryable() && ae.Code != api.CodeChunkHashMismatch:
			return nil, err
		}
		failures++
		if failures > o.MaxRetries {
			return nil, fmt.Errorf("giving up after %d failed attempts: %w", failures-1, err)
		}
		if err := wait(ctx, o, err, failures); err != nil {
			return nil, err
		}
		// Ask the server where we are: the answer to the last chunk may just have been lost.
		cur, gerr := c.Get(ctx, file.ID)
		switch {
		case gerr == nil && cur.Status == api.StatusReady:
			report(size)
			return cur, nil
		case gerr == nil:
			offset = cur.Offset
			report(offset)
		case isFatal(gerr):
			return nil, gerr
		}
	}
}

// retry runs fn, retrying transient failures with backoff.
func retry(ctx context.Context, o *Options, fn func() error) error {
	for failures := 1; ; failures++ {
		err := fn()
		if err == nil || ctx.Err() != nil || isFatal(err) {
			return err
		}
		if failures > o.MaxRetries {
			return fmt.Errorf("giving up after %d failed attempts: %w", failures-1, err)
		}
		if err := wait(ctx, o, err, failures); err != nil {
			return err
		}
	}
}

// isFatal reports whether retrying cannot help.
func isFatal(err error) bool {
	var ae *api.Error
	if errors.As(err, &ae) {
		return !ae.Retryable()
	}
	return errors.Is(err, api.ErrSourceChanged) || errors.Is(err, context.Canceled)
}

func wait(ctx context.Context, o *Options, err error, failure int) error {
	d := min(time.Second<<min(failure-1, 5), 30*time.Second)
	var ae *api.Error
	if errors.As(err, &ae) && ae.RetryAfter > d {
		d = ae.RetryAfter
	}
	if o.OnRetry != nil {
		o.OnRetry(err, d)
	}
	return o.Sleep(ctx, d)
}

func sleep(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

// hashFile returns the hex sha256 of the first size bytes of f.
func hashFile(f io.ReaderAt, size int64, progress func(done, total int64)) (string, error) {
	h := sha256.New()
	r := &countingReader{r: io.NewSectionReader(f, 0, size), report: func(done int64) {
		if progress != nil {
			progress(done, size)
		}
	}}
	if _, err := io.Copy(h, r); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

// countingReader reports base + bytes read so far.
type countingReader struct {
	r      io.Reader
	base   int64
	n      int64
	report func(done int64)
}

func (c *countingReader) Read(p []byte) (int, error) {
	n, err := c.r.Read(p)
	c.n += int64(n)
	if n > 0 && c.report != nil {
		c.report(c.base + c.n)
	}
	return n, err
}
