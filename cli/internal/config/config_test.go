package config

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
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

func TestNormalizeServerRefusesPlainHTTPExceptLoopback(t *testing.T) {
	for _, ok := range []string{"https://linkrot.example", "http://localhost:8080", "http://127.0.0.1:1234", "http://127.5.5.5", "http://[::1]:8080", "HTTP://LOCALHOST"} {
		if _, err := NormalizeServer(ok); err != nil {
			t.Errorf("%s: %v", ok, err)
		}
	}
	for _, bad := range []string{"http://linkrot.example", "http://192.168.1.10:8080", "http://10.0.0.1", "http://localhost.evil.example", "http://localhost@evil.example", "ftp://localhost"} {
		if _, err := NormalizeServer(bad); err == nil {
			t.Errorf("%s was accepted", bad)
		}
	}
}

func TestCheckRefusesAStoredPlainHTTPServer(t *testing.T) {
	err := Config{Server: "http://linkrot.example", Token: "lr_x"}.Check()
	if err == nil || !strings.Contains(err.Error(), "refusing plain http") {
		t.Fatalf("got %v", err)
	}
	if err := (Config{Server: "https://linkrot.example", Token: "lr_x"}).Check(); err != nil {
		t.Fatal(err)
	}
}

func TestSaveFailsClearlyWhenTheDirectoryCannotBeCreated(t *testing.T) {
	dir := t.TempDir()
	blocker := filepath.Join(dir, "blocker")
	if err := os.WriteFile(blocker, []byte("a file"), 0o600); err != nil {
		t.Fatal(err)
	}

	err := Save(filepath.Join(blocker, "sub", "config.toml"), Config{Server: "https://x", Token: "t"})

	if err == nil || !strings.Contains(err.Error(), "create config directory") {
		t.Fatalf("got %v", err)
	}
}

func TestSaveFailsWhenTheTargetIsADirectoryAndLeavesNoTempFile(t *testing.T) {
	dir := t.TempDir()
	target := filepath.Join(dir, "config.toml")
	if err := os.Mkdir(target, 0o700); err != nil {
		t.Fatal(err)
	}

	if err := Save(target, Config{Server: "https://x", Token: "t"}); err == nil {
		t.Fatal("renaming a file over a directory must fail")
	}

	entries, _ := os.ReadDir(dir)
	if len(entries) != 1 {
		t.Errorf("a temporary file was left behind: %v", entries)
	}
}

func TestSaveReplacesAnExistingFileAtomically(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.toml")
	for _, token := range []string{"first", "second"} {
		if err := Save(path, Config{Server: "https://x", Token: token}); err != nil {
			t.Fatal(err)
		}
	}

	c, err := Load(path)
	if err != nil || c.Token != "second" {
		t.Fatalf("%+v, %v", c, err)
	}
}

// dotfile managers (stow, chezmoi, home-manager) keep the real file elsewhere and link it into place
func linked(t *testing.T, mode os.FileMode, content string) (link, target string) {
	t.Helper()
	managed, home := t.TempDir(), t.TempDir()
	target, link = filepath.Join(managed, "config.toml"), filepath.Join(home, "config.toml")
	if err := os.WriteFile(target, []byte(content), mode); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(target, mode); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}
	return link, target
}

func isLink(t *testing.T, path string) bool {
	t.Helper()
	info, err := os.Lstat(path)
	return err == nil && info.Mode()&os.ModeSymlink != 0
}

func TestSaveFollowsASymlinkAndKeepsIt(t *testing.T) {
	link, target := linked(t, 0o600, "server = 'https://old.example'\n")
	want := Config{Server: "https://lr.example", Token: "lr_secret"}

	if err := Save(link, want); err != nil {
		t.Fatal(err)
	}

	if !isLink(t, link) {
		t.Fatal("the symlink was replaced by a regular file")
	}
	if got, err := Load(target); err != nil || got != want {
		t.Fatalf("target holds %+v, %v", got, err)
	}
	if entries, _ := os.ReadDir(filepath.Dir(target)); len(entries) != 1 {
		t.Fatalf("temporary files left: %v", entries)
	}
}

func TestSaveKeepsTheModeOfAManagedFile(t *testing.T) {
	link, target := linked(t, 0o400, "")
	// 0400: the owner can read it only, which is as safe as it gets, and chezmoi would see a mode change otherwise
	if err := Save(link, Config{Server: "https://lr.example", Token: "t"}); err != nil {
		t.Fatal(err)
	}
	if info, _ := os.Stat(target); info.Mode().Perm() != 0o400 {
		t.Fatalf("mode %v, want 0400", info.Mode().Perm())
	}
}

func TestSaveThroughADanglingSymlinkCreatesTheTarget(t *testing.T) {
	dir := t.TempDir()
	target, link := filepath.Join(dir, "managed", "config.toml"), filepath.Join(dir, "config.toml")
	if err := os.Mkdir(filepath.Dir(target), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}
	want := Config{Server: "https://lr.example", Token: "lr_secret"}

	if err := Save(link, want); err != nil {
		t.Fatal(err)
	}

	if got, err := Load(target); err != nil || got != want {
		t.Fatalf("%+v %v", got, err)
	}
	if !isLink(t, link) {
		t.Fatal("the symlink was replaced")
	}
	if info, _ := os.Stat(target); info.Mode().Perm() != 0o600 {
		t.Fatalf("a new file must be 0600, is %v", info.Mode().Perm())
	}
}

func TestSaveInAReadOnlyPlaceSaysWhyAndChangesNothing(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("root can write anywhere")
	}
	link, target := linked(t, 0o600, "server = 'https://old.example'\n")
	if err := os.Chmod(filepath.Dir(target), 0o500); err != nil { // like the Nix store
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.Chmod(filepath.Dir(target), 0o700) })

	err := Save(link, Config{Server: "https://lr.example", Token: "t"})

	if err == nil || !strings.Contains(err.Error(), "symlink") || !strings.Contains(err.Error(), target) {
		t.Fatalf("%v", err)
	}
	if !isLink(t, link) {
		t.Fatal("the symlink was replaced")
	}
	if b, _ := os.ReadFile(target); string(b) != "server = 'https://old.example'\n" {
		t.Fatalf("target changed: %q", b)
	}
}

func TestSaveRefusesToPutTheTokenIntoAFileOthersCanRead(t *testing.T) {
	link, target := linked(t, 0o644, "server = 'https://old.example'\n")

	err := Save(link, Config{Server: "https://lr.example", Token: "lr_secret"})

	var unsafe *UnsafeFileError
	if !errors.As(err, &unsafe) || unsafe.Path != target || unsafe.Mode.Perm() != 0o644 {
		t.Fatalf("%v", err)
	}
	if b, _ := os.ReadFile(target); string(b) != "server = 'https://old.example'\n" {
		t.Fatalf("target changed: %q", b)
	}
}

func TestSaveLooseWritesItAnywayAndKeepsTheMode(t *testing.T) {
	link, target := linked(t, 0o644, "")
	want := Config{Server: "https://lr.example", Token: "lr_secret"}

	if err := SaveLoose(link, want); err != nil {
		t.Fatal(err)
	}

	if got, err := Load(target); err != nil || got != want {
		t.Fatalf("%+v %v", got, err)
	}
	if info, _ := os.Stat(target); info.Mode().Perm() != 0o644 {
		t.Fatalf("mode %v: the user decided to keep it", info.Mode().Perm())
	}
}

func TestSaveWithoutATokenIntoALooseManagedFileIsFine(t *testing.T) {
	link, _ := linked(t, 0o644, "")
	if err := Save(link, Config{Server: "https://lr.example"}); err != nil {
		t.Fatalf("nothing secret is written: %v", err)
	}
}

func TestAPlainLooseFileIsReplacedByASafeOne(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.toml")
	if err := os.WriteFile(path, []byte("server = 'x'\n"), 0o644); err != nil { //nolint:gosec // the point of the test
		t.Fatal(err)
	}
	if err := os.Chmod(path, 0o644); err != nil { //nolint:gosec // the point of the test
		t.Fatal(err)
	}

	if err := Save(path, Config{Server: "https://lr.example", Token: "t"}); err != nil {
		t.Fatal(err)
	}

	if info, _ := os.Stat(path); info.Mode().Perm() != 0o600 {
		t.Fatalf("mode %v, want 0600", info.Mode().Perm())
	}
}

func TestLooseMode(t *testing.T) {
	for mode, want := range map[os.FileMode]bool{0o600: false, 0o400: false, 0o640: true, 0o604: true, 0o644: true, 0o666: true} {
		link, _ := linked(t, mode, "")
		if got, loose := LooseMode(link); loose != want || (want && got.Perm() != mode) {
			t.Errorf("%v: loose %v (mode %v), want %v", mode, loose, got, want)
		}
	}
	if _, loose := LooseMode(filepath.Join(t.TempDir(), "nope")); loose {
		t.Error("a missing file is not loose")
	}
}
