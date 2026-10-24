package cli

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestDown(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("report.txt", []byte(content))
	dir := t.TempDir()

	code, out, errs := e.run("down", id[:6], dir)

	want := filepath.Join(dir, "report.txt")
	if code != 0 || out != want+"\n" {
		t.Fatalf("code %d, stdout %q, stderr %q", code, out, errs)
	}
	if b, _ := os.ReadFile(want); string(b) != content {
		t.Fatalf("content %q", b)
	}
}

func TestDownRefusesToOverwriteAndUsage(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("report.txt", []byte(content))
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "report.txt"), []byte("mine"), 0o600); err != nil {
		t.Fatal(err)
	}

	code, out, errs := e.run("down", id, dir)

	if code != ExitError || out != "" || !strings.Contains(errs, "not overwriting") {
		t.Fatalf("code %d, stdout %q, stderr %q", code, out, errs)
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "report.txt")); string(b) != "mine" {
		t.Fatal("file was overwritten")
	}
	for _, args := range [][]string{{"down"}, {"down", id, "a", "b"}} {
		if code, _, _ := e.run(args...); code != ExitUsage {
			t.Errorf("%v: code %d", args, code)
		}
	}
}
