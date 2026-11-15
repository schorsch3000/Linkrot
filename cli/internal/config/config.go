// Package config reads and writes the client configuration file.
package config

import (
	"errors"
	"fmt"
	"io/fs"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"

	"github.com/pelletier/go-toml/v2"
)

// EnvPath overrides the location of the config file (used by tests and scripts).
const EnvPath = "LINKROT_CONFIG"

// ErrNotConfigured is returned when server or token are missing.
var ErrNotConfigured = errors.New("not configured: run `config --server <url>` and `config --token <token>`")

// Config is the content of config.toml.
type Config struct {
	Server string `toml:"server"`
	Token  string `toml:"token"`
	// Email is the address of the host, where the codes for `auth` and `token` go; remembered by `auth`.
	Email string `toml:"email,omitempty"`
}

// Path returns the config file location: $LINKROT_CONFIG, else $XDG_CONFIG_HOME/linkrot/config.toml,
// else ~/.config/linkrot/config.toml. The directory is fixed and does not depend on the name the
// program is called with, so every alias (linkrot, lr, ...) shares one configuration.
func Path() (string, error) {
	if p := os.Getenv(EnvPath); p != "" {
		return p, nil
	}
	base := os.Getenv("XDG_CONFIG_HOME")
	if base == "" {
		home, err := os.UserHomeDir()
		if err != nil {
			return "", fmt.Errorf("cannot determine config directory: %w", err)
		}
		base = filepath.Join(home, ".config")
	}
	return filepath.Join(base, "linkrot", "config.toml"), nil
}

// Load reads the file. A missing file is not an error and yields an empty Config.
func Load(path string) (Config, error) {
	var c Config
	data, err := os.ReadFile(path) //nolint:gosec // path is the user's own config file
	if errors.Is(err, os.ErrNotExist) {
		return c, nil
	}
	if err != nil {
		return c, fmt.Errorf("read config: %w", err)
	}
	if err := toml.Unmarshal(data, &c); err != nil {
		return c, fmt.Errorf("parse %s: %w", path, err)
	}
	return c, nil
}

// Save writes the file via a temporary file and rename, fsynced so that a crash leaves the old or the new file, never an
// empty one. A new file gets mode 0600 (it contains the token), also when it replaces a plain file that was readable by
// others. If path is a symlink (stow, chezmoi, home-manager keep the real file elsewhere) the link is followed and kept,
// and the mode of the file it points to is kept too; a token is not written into such a file if others can read it
// (*UnsafeFileError), use SaveLoose to do it anyway.
func Save(path string, c Config) error { return save(path, c, false) }

// SaveLoose is Save for a config file that is readable by others and that the user decided to keep that way.
func SaveLoose(path string, c Config) error { return save(path, c, true) }

func save(path string, c Config, allowLoose bool) error {
	data, err := toml.Marshal(c)
	if err != nil {
		return fmt.Errorf("encode config: %w", err)
	}
	target, mode, managed, err := resolve(path)
	if err != nil {
		return fmt.Errorf("write config: %w", err)
	}
	if managed && c.Token != "" && !allowLoose && runtime.GOOS != "windows" && mode&0o077 != 0 {
		return &UnsafeFileError{Path: target, Mode: mode}
	}
	dir := filepath.Dir(target)
	if !managed {
		if err := os.MkdirAll(dir, 0o700); err != nil {
			return fmt.Errorf("create config directory: %w", err)
		}
	}
	fail := func(err error) error {
		if managed {
			return fmt.Errorf("write config: %s is a symlink to %s, which cannot be written: %w", path, target, err)
		}
		return fmt.Errorf("write config: %w", err)
	}
	tmp, err := os.CreateTemp(dir, ".config-*")
	if err != nil {
		return fail(err)
	}
	defer func() { _ = os.Remove(tmp.Name()) }() // no-op after a successful rename
	if err := tmp.Chmod(mode); err != nil {
		_ = tmp.Close()
		return fail(err)
	}
	if _, err := tmp.Write(data); err != nil {
		_ = tmp.Close()
		return fail(err)
	}
	if err := tmp.Sync(); err != nil {
		_ = tmp.Close()
		return fail(err)
	}
	if err := tmp.Close(); err != nil {
		return fail(err)
	}
	if err := os.Rename(tmp.Name(), target); err != nil {
		return fail(err)
	}
	syncDir(dir)
	return nil
}

// resolve follows symlinks at path (also dangling ones). managed says that path was one. mode is the mode the written
// file gets: that of the existing file behind a symlink, else 0600.
func resolve(path string) (target string, mode fs.FileMode, managed bool, err error) {
	target, mode = path, 0o600
	for range 40 {
		info, lerr := os.Lstat(target)
		switch {
		case lerr != nil && (errors.Is(lerr, fs.ErrNotExist) || errors.Is(lerr, syscall.ENOTDIR)): // MkdirAll reports the latter
			return target, mode, managed, nil
		case lerr != nil:
			return "", 0, false, lerr
		case info.Mode()&fs.ModeSymlink != 0:
			link, rerr := os.Readlink(target)
			if rerr != nil {
				return "", 0, false, rerr
			}
			if !filepath.IsAbs(link) {
				link = filepath.Join(filepath.Dir(target), link)
			}
			target, managed = link, true
		case !info.Mode().IsRegular():
			return "", 0, false, fmt.Errorf("%s is not a regular file", target)
		default:
			if managed {
				mode = info.Mode().Perm()
			}
			return target, mode, managed, nil
		}
	}
	return "", 0, false, fmt.Errorf("%s: too many levels of symbolic links", path)
}

// syncDir makes the rename durable; it is best effort (not possible on Windows).
func syncDir(dir string) {
	if d, err := os.Open(dir); err == nil { //nolint:gosec // the config directory
		_ = d.Sync()
		_ = d.Close()
	}
}

// UnsafeFileError means the config file that would receive the token can be read by others.
type UnsafeFileError struct {
	Path string
	Mode fs.FileMode
}

func (e *UnsafeFileError) Error() string {
	return fmt.Sprintf("%s is readable by others (mode %04o), not writing the token into it", e.Path, e.Mode.Perm())
}

// LooseMode reports the mode of the config file (behind symlinks) and whether group or others can read it. It is never
// loose on Windows, which has no such modes.
func LooseMode(path string) (fs.FileMode, bool) {
	info, err := os.Stat(path)
	if err != nil || !info.Mode().IsRegular() || runtime.GOOS == "windows" {
		return 0, false
	}
	return info.Mode().Perm(), info.Mode().Perm()&0o077 != 0
}

// Check reports ErrNotConfigured if server or token are missing, and refuses a server that is reached over plain http.
func (c Config) Check() error {
	if c.Server == "" || c.Token == "" {
		return ErrNotConfigured
	}
	if _, err := NormalizeServer(c.Server); err != nil {
		return err
	}
	return nil
}

// NormalizeServer validates a server URL and removes a trailing slash. It must be https; plain http is only
// accepted for localhost and loopback addresses (development), never for a network.
func NormalizeServer(raw string) (string, error) {
	u, err := url.Parse(strings.TrimSpace(raw))
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return "", fmt.Errorf("invalid server URL %q: expected https://host[/path]", raw)
	}
	if u.RawQuery != "" || u.Fragment != "" {
		return "", fmt.Errorf("invalid server URL %q: no query or fragment allowed", raw)
	}
	if !AllowedScheme(u) {
		return "", fmt.Errorf("refusing plain http for %q: use https (http is only allowed for localhost)", raw)
	}
	return strings.TrimRight(u.String(), "/"), nil
}

// AllowedScheme reports whether the token may be sent to u: https, or http to localhost / a loopback address.
func AllowedScheme(u *url.URL) bool {
	if u.Scheme == "https" {
		return true
	}
	if u.Scheme != "http" {
		return false
	}
	host := strings.ToLower(u.Hostname())
	if host == "localhost" {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}

// MaskToken shortens a token for display.
func MaskToken(token string) string {
	switch {
	case token == "":
		return "(not set)"
	case len(token) <= 8:
		return "****"
	default:
		return token[:6] + "…" + token[len(token)-2:]
	}
}
