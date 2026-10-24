// Package download fetches a file safely: it never overwrites, never creates directories, resumes
// interrupted transfers and verifies the sha256 before the file appears under its final name.
package download

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
)

// Options control a download.
type Options struct {
	OnProgress func(done, total int64)
	OnRetry    func(err error, wait time.Duration)
	MaxRetries int
	Sleep      func(ctx context.Context, d time.Duration) error
}

// Download fetches the file with the given id. target is empty (current directory, original name),
// an existing directory (original name inside it) or the file name to use. It returns the final path.
func Download(ctx context.Context, c *api.Client, id, target string, o Options) (string, error) {
	if o.MaxRetries == 0 {
		o.MaxRetries = 8
	}
	if o.Sleep == nil {
		o.Sleep = sleep
	}
	file, err := c.Get(ctx, id)
	if err != nil {
		return "", err
	}
	if file.Status != api.StatusReady {
		return "", errors.New("the file is still being uploaded")
	}
	dest, err := Destination(target, file.Name)
	if err != nil {
		return "", err
	}
	part := dest + ".part"

	out, size, err := openPart(part, file.Size)
	if err != nil {
		return "", err
	}
	defer func() { _ = out.Close() }()

	report := func(done int64) {
		if o.OnProgress != nil {
			o.OnProgress(done, file.Size)
		}
	}
	report(size)
	for failures := 0; size < file.Size; {
		before := size
		size, err = fetch(ctx, c, file, out, size, report)
		if err == nil {
			continue
		}
		if ctx.Err() != nil {
			return "", ctx.Err()
		}
		var ae *api.Error
		if errors.As(err, &ae) && !ae.Retryable() {
			return "", err
		}
		if size > before {
			failures = 0 // progress was made, the connection is not hopeless
		}
		failures++
		if failures > o.MaxRetries {
			return "", fmt.Errorf("giving up after %d failed attempts (partial data kept in %s): %w", failures-1, part, err)
		}
		d := min(time.Second<<min(failures-1, 5), 30*time.Second)
		if o.OnRetry != nil {
			o.OnRetry(err, d)
		}
		if err := o.Sleep(ctx, d); err != nil {
			return "", err
		}
	}

	if err := out.Sync(); err != nil {
		return "", err
	}
	if err := verify(part, file); err != nil {
		_ = out.Close()
		_ = os.Remove(part)
		return "", err
	}
	if err := out.Close(); err != nil {
		return "", err
	}
	// link() fails if dest exists, so a file that appeared meanwhile is never replaced (rename would).
	if err := os.Link(part, dest); err != nil {
		if errors.Is(err, fs.ErrExist) {
			return "", fmt.Errorf("%s already exists, not overwriting (the download is kept as %s)", dest, part)
		}
		return "", fmt.Errorf("move into place: %w (the download is kept as %s)", err, part)
	}
	if err := os.Remove(part); err != nil {
		return "", err
	}
	return dest, nil
}

// fetch requests the file from offset on and appends what arrives. It returns the new size of the partial
// file (which is 0-based again if the server ignored the range request).
func fetch(ctx context.Context, c *api.Client, file *api.File, out *os.File, offset int64, report func(int64)) (int64, error) {
	resp, err := c.Download(ctx, file.ID, file.Name, offset)
	if err != nil {
		return offset, err
	}
	defer func() { _ = resp.Body.Close() }()

	switch {
	case offset > 0 && resp.StatusCode == http.StatusOK:
		// The server ignored the range: start over.
		if err := out.Truncate(0); err != nil {
			return offset, err
		}
		if _, err := out.Seek(0, io.SeekStart); err != nil {
			return offset, err
		}
		offset = 0
		report(0)
	case resp.StatusCode == http.StatusPartialContent:
		var from int64
		if _, err := fmt.Sscanf(resp.Header.Get("Content-Range"), "bytes %d-", &from); err != nil || from != offset {
			return offset, fmt.Errorf("server answered with an unexpected range %q", resp.Header.Get("Content-Range"))
		}
	}
	buf := make([]byte, 256<<10)
	for {
		n, rerr := resp.Body.Read(buf)
		if n > 0 {
			if offset+int64(n) > file.Size {
				return offset, errors.New("server sent more data than the file size")
			}
			if _, werr := out.Write(buf[:n]); werr != nil {
				return offset, werr
			}
			offset += int64(n)
			report(offset)
		}
		if rerr == io.EOF {
			return offset, nil
		}
		if rerr != nil {
			return offset, rerr
		}
	}
}

// openPart opens the partial file for writing at its end. A fresh one is created exclusively; an existing
// one is resumed. It returns the number of bytes already there.
func openPart(part string, total int64) (*os.File, int64, error) {
	f, err := os.OpenFile(part, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o666) //nolint:gosec // permissions follow the umask
	if err == nil {
		return f, 0, nil
	}
	if !errors.Is(err, fs.ErrExist) {
		return nil, 0, err
	}
	info, err := os.Lstat(part)
	if err != nil {
		return nil, 0, err
	}
	if !info.Mode().IsRegular() {
		return nil, 0, fmt.Errorf("%s exists and is not a regular file", part)
	}
	if info.Size() > total {
		return nil, 0, fmt.Errorf("%s is larger than the file; it belongs to something else, remove it first", part)
	}
	f, err = os.OpenFile(part, os.O_WRONLY, 0) //nolint:gosec // checked above
	if err != nil {
		return nil, 0, err
	}
	if _, err := f.Seek(info.Size(), io.SeekStart); err != nil {
		_ = f.Close()
		return nil, 0, err
	}
	return f, info.Size(), nil
}

func verify(path string, file *api.File) error {
	f, err := os.Open(path) //nolint:gosec // our own partial file
	if err != nil {
		return err
	}
	defer func() { _ = f.Close() }()
	h := sha256.New()
	n, err := io.Copy(h, f)
	if err != nil {
		return err
	}
	if n != file.Size || hex.EncodeToString(h.Sum(nil)) != file.SHA256 {
		return errors.New("the downloaded data does not match the checksum of the file; it was discarded, try again")
	}
	return nil
}

// Destination decides where the file goes and refuses everything that would overwrite or create anything.
func Destination(target, serverName string) (string, error) {
	var dest string
	switch target {
	case "":
		name, err := safeName(serverName)
		if err != nil {
			return "", err
		}
		dest = name
	default:
		info, err := os.Stat(target)
		switch {
		case err == nil && info.IsDir():
			name, nerr := safeName(serverName)
			if nerr != nil {
				return "", nerr
			}
			dest = filepath.Join(target, name)
		case err == nil:
			return "", fmt.Errorf("%s already exists, not overwriting", target)
		case !errors.Is(err, fs.ErrNotExist):
			return "", err
		case strings.HasSuffix(target, "/") || strings.HasSuffix(target, string(filepath.Separator)):
			return "", fmt.Errorf("%s is not an existing directory and directories are never created", target)
		default:
			parent := filepath.Dir(target)
			if pinfo, perr := os.Stat(parent); perr != nil || !pinfo.IsDir() {
				return "", fmt.Errorf("directory %s does not exist and directories are never created", parent)
			}
			dest = target
		}
	}
	if _, err := os.Lstat(dest); err == nil {
		return "", fmt.Errorf("%s already exists, not overwriting", dest)
	}
	return dest, nil
}

// safeName reduces a name from the server to a plain file name or refuses it.
func safeName(name string) (string, error) {
	base := filepath.Base(name)
	if name == "" || base != name || base == "." || base == ".." || strings.ContainsAny(name, "/\\\x00") {
		return "", fmt.Errorf("the server sent an unusable file name %q; name the target explicitly", name)
	}
	return base, nil
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
