package config

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func TestSaveLoadRoundTripWithMode0600(t *testing.T) {
	path := filepath.Join(t.TempDir(), "sub", "config.toml")
	want := Config{Server: "https://lr.example", Token: "lr_secret"}

	if err := Save(path, want); err != nil {
		t.Fatal(err)
	}
	got, err := Load(path)
	if err != nil {
		t.Fatal(err)
	}
	if got != want {
		t.Fatalf("got %+v, want %+v", got, want)
	}
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("mode %v, want 0600", info.Mode().Perm())
	}
	dirInfo, err := os.Stat(filepath.Dir(path))
	if err != nil {
		t.Fatal(err)
	}
	if dirInfo.Mode().Perm() != 0o700 {
		t.Fatalf("dir mode %v, want 0700", dirInfo.Mode().Perm())
	}
}

func TestLoadMissingFileIsEmpty(t *testing.T) {
	got, err := Load(filepath.Join(t.TempDir(), "nope.toml"))
	if err != nil || got != (Config{}) {
		t.Fatalf("got %+v, %v", got, err)
	}
}

func TestLoadGarbageFails(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.toml")
	if err := os.WriteFile(path, []byte("= not toml ="), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(path); err == nil {
		t.Fatal("expected an error")
	}
}

func TestPath(t *testing.T) {
	t.Setenv(EnvPath, "")
	t.Setenv("XDG_CONFIG_HOME", "/xdg")
	if got, _ := Path(); got != "/xdg/linkrot/config.toml" {
		t.Fatalf("xdg: %q", got)
	}
	t.Setenv("XDG_CONFIG_HOME", "")
	t.Setenv("HOME", "/home/u")
	if got, _ := Path(); got != "/home/u/.config/linkrot/config.toml" {
		t.Fatalf("home: %q", got)
	}
	t.Setenv(EnvPath, "/custom.toml")
	if got, _ := Path(); got != "/custom.toml" {
		t.Fatalf("env: %q", got)
	}
}

func TestCheck(t *testing.T) {
	for _, c := range []Config{{}, {Server: "https://x"}, {Token: "t"}} {
		if err := c.Check(); !errors.Is(err, ErrNotConfigured) {
			t.Fatalf("%+v: %v", c, err)
		}
	}
	if err := (Config{Server: "https://x", Token: "t"}).Check(); err != nil {
		t.Fatal(err)
	}
}

func TestNormalizeServer(t *testing.T) {
	good := map[string]string{
		"https://lr.example":        "https://lr.example",
		"https://lr.example/":       "https://lr.example",
		" http://localhost:8080// ": "http://localhost:8080",
		"https://lr.example/lr/":    "https://lr.example/lr",
	}
	for in, want := range good {
		got, err := NormalizeServer(in)
		if err != nil || got != want {
			t.Errorf("%q: got %q, %v; want %q", in, got, err, want)
		}
	}
	for _, in := range []string{"", "lr.example", "ftp://lr.example", "https://", "https://x?a=b", "https://x#f"} {
		if _, err := NormalizeServer(in); err == nil {
			t.Errorf("%q: expected an error", in)
		}
	}
}

func TestMaskToken(t *testing.T) {
	if MaskToken("") != "(not set)" || MaskToken("short") != "****" {
		t.Fatal("short tokens")
	}
	if got := MaskToken("lr_abcdefghijklmnop"); got != "lr_abc…op" {
		t.Fatalf("got %q", got)
	}
}
