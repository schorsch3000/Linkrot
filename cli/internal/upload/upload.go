// Package upload implements the resumable chunked upload protocol (docs/API.md).
package upload

import (
	"bytes"
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

// StreamName is the name on the server of an upload from stdin without --mv.
const StreamName = "stdin"

// UploadStream sends everything r delivers and returns the finished file object. Size and hash are unknown at
// the start: the chunks go out as they are read (one chunk is buffered) and the final sha256 seals the upload.
// There is no resuming after the process is gone, the data cannot be read again.
func UploadStream(ctx context.Context, c *api.Client, r io.Reader, o Options) (*api.File, error) {
	if o.MaxRetries == 0 {
		o.MaxRetries = 8
	}
	if o.Sleep == nil {
		o.Sleep = sleep
	}
	if o.Name == "" {
		o.Name = StreamName
	}
	var file *api.File
	err := retry(ctx, &o, func() error {
		var err error
		file, err = c.CreateStream(ctx, api.CreateStreamRequest{Name: o.Name, RmAfter: o.RmAfter, Uses: o.Uses})
		return err
	})
	if err != nil {
		return nil, err
	}
	if file.ChunkSize <= 0 {
		return nil, errors.New("server did not announce a chunk size")
	}

	whole := sha256.New()
	buf := make([]byte, file.ChunkSize)
	var offset int64
	if o.OnProgress != nil {
		o.OnProgress(0, -1)
	}
	for last := false; !last; {
		n, err := io.ReadFull(r, buf)
		switch {
		case err == nil:
		case errors.Is(err, io.EOF), errors.Is(err, io.ErrUnexpectedEOF):
			last = true
		default:
			return nil, fmt.Errorf("read input: %w", err)
		}
		if n == 0 {
			break
		}
		whole.Write(buf[:n])
		if err := sendStreamChunk(ctx, c, file.ID, offset, buf[:n], &o); err != nil {
			return nil, err
		}
		offset += int64(n)
		if o.OnProgress != nil {
			o.OnProgress(offset, -1)
		}
	}

	sum := hex.EncodeToString(whole.Sum(nil))
	var sealed *api.File
	err = retry(ctx, &o, func() error {
		var err error
		sealed, err = c.Seal(ctx, file.ID, offset, sum)
		return err
	})
	if err != nil {
		var ae *api.Error
		if errors.As(err, &ae) && ae.Code == api.CodeDuplicate {
			return nil, &DuplicateError{ID: ae.ID, URL: ae.URL}
		}
		return nil, err
	}
	return sealed, nil
}

// sendStreamChunk sends one buffered chunk, retrying transient failures. The data of earlier chunks is gone, so
// the only recovery is for this chunk: if the answer was lost, the server is already past it.
func sendStreamChunk(ctx context.Context, c *api.Client, id string, offset int64, data []byte, o *Options) error {
	end := offset + int64(len(data))
	for failures := 0; ; {
		_, err := c.UploadChunk(ctx, id, offset, bytes.NewReader(data), int64(len(data)))
		if err == nil {
			return nil
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		var ae *api.Error
		if errors.As(err, &ae) && ae.Code == api.CodeOffsetMismatch && ae.Offset != nil {
			if *ae.Offset == end {
				return nil
			}
			return fmt.Errorf("the server is at offset %d, this stream is at %d and cannot go back: %w", *ae.Offset, offset, err)
		}
		if errors.As(err, &ae) && !ae.Retryable() && ae.Code != api.CodeChunkHashMismatch {
			return err
		}
		failures++
		if failures > o.MaxRetries {
			return fmt.Errorf("giving up after %d failed attempts: %w", failures-1, err)
		}
		if err := wait(ctx, o, err, failures); err != nil {
			return err
		}
		cur, gerr := c.Get(ctx, id)
		switch {
		case gerr == nil && cur.Offset == end:
			return nil
		case gerr == nil && cur.Offset != offset:
			return fmt.Errorf("the server is at offset %d, this stream is at %d and cannot go back", cur.Offset, offset)
		case gerr != nil && isFatal(gerr):
			return gerr
		}
	}
}
