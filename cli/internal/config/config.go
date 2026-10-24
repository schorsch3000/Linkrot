// Package config reads and writes the client configuration file.
package config

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"strings"

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

// Save writes the file with mode 0600 (it contains the token) via a temporary file and rename.
func Save(path string, c Config) error {
	data, err := toml.Marshal(c)
	if err != nil {
		return fmt.Errorf("encode config: %w", err)
	}
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("create config directory: %w", err)
	}
	tmp, err := os.CreateTemp(dir, ".config-*")
	if err != nil {
		return fmt.Errorf("write config: %w", err)
	}
	defer func() { _ = os.Remove(tmp.Name()) }() // no-op after a successful rename
	if err := tmp.Chmod(0o600); err != nil {
		_ = tmp.Close()
		return fmt.Errorf("write config: %w", err)
	}
	if _, err := tmp.Write(data); err != nil {
		_ = tmp.Close()
		return fmt.Errorf("write config: %w", err)
	}
	if err := tmp.Close(); err != nil {
		return fmt.Errorf("write config: %w", err)
	}
	if err := os.Rename(tmp.Name(), path); err != nil {
		return fmt.Errorf("write config: %w", err)
	}
	return nil
}

// Check reports ErrNotConfigured if server or token are missing.
func (c Config) Check() error {
	if c.Server == "" || c.Token == "" {
		return ErrNotConfigured
	}
	return nil
}

// NormalizeServer validates an http(s) URL and removes a trailing slash.
func NormalizeServer(raw string) (string, error) {
	u, err := url.Parse(strings.TrimSpace(raw))
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return "", fmt.Errorf("invalid server URL %q: expected http(s)://host[/path]", raw)
	}
	if u.RawQuery != "" || u.Fragment != "" {
		return "", fmt.Errorf("invalid server URL %q: no query or fragment allowed", raw)
	}
	return strings.TrimRight(u.String(), "/"), nil
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
