package cli

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/schorsch3000/Linkrot/internal/config"
)

func TestCustomGetPrintsTheBuiltInLinesBeforeAnUpload(t *testing.T) {
	e := newEnv(t)
	code, out, errs := e.run("custom", "get", "taglines")
	if code != 0 || out != "built-in 1\nbuilt-in 2\n" {
		t.Fatalf("code %d: %q %s", code, out, errs)
	}
}

func TestCustomPutGetResetRoundTrip(t *testing.T) {
	e := newEnv(t)
	f := e.file("t.txt", "mine 1\nmine 2\n")
	if code, out, errs := e.run("custom", "put", "taglines", f); code != 0 || !strings.Contains(out, "taglines: 2 lines in use") {
		t.Fatalf("put: code %d: %q %s", code, out, errs)
	}
	if _, out, _ := e.run("custom", "get", "taglines"); out != "mine 1\nmine 2\n" {
		t.Fatalf("get after put: %q", out)
	}

	target := filepath.Join(e.dir, "down.txt")
	if code, out, errs := e.run("custom", "get", "taglines", "-o", target); code != 0 || !strings.Contains(out, "wrote your file") {
		t.Fatalf("get -o: code %d: %q %s", code, out, errs)
	}
	if b, err := os.ReadFile(target); err != nil || string(b) != "mine 1\nmine 2\n" {
		t.Fatalf("file: %q, %v", b, err)
	}

	if code, out, errs := e.run("custom", "reset", "taglines"); code != 0 || !strings.Contains(out, "back to the built-in lines") {
		t.Fatalf("reset: code %d: %q %s", code, out, errs)
	}
	if _, out, _ := e.run("custom", "get", "taglines"); out != "built-in 1\nbuilt-in 2\n" {
		t.Fatalf("get after reset: %q", out)
	}
	if code, out, _ := e.run("custom", "get", "headerlines", "-o", target); code != 0 || !strings.Contains(out, "wrote the built-in lines") {
		t.Fatalf("built-in message: %d %q", code, out)
	}
}

func TestCustomPutFromStandardInput(t *testing.T) {
	e := newEnv(t)
	var out, errb bytes.Buffer
	app := &App{Name: "lr", Out: &out, Err: &errb, In: strings.NewReader("from stdin\n"), ConfigPath: config.Path}
	if code := app.Run([]string{"custom", "put", "headerlines", "-"}); code != 0 {
		t.Fatalf("code %d: %s", code, errb.String())
	}
	if e.server.Custom["headerlines"] != "from stdin\n" {
		t.Fatalf("server has %v", e.server.Custom)
	}
}

func TestCustomRefusesBadInputBeforeAskingTheServer(t *testing.T) {
	e := newEnv(t)
	for _, args := range [][]string{
		{"custom", "get"},
		{"custom", "get", "passwd"},
		{"custom", "put", "taglines"},
		{"custom", "put", "nonsense", "x"},
		{"custom", "reset"},
	} {
		if code, _, _ := e.run(args...); code != ExitUsage {
			t.Errorf("%v: code %d, want %d", args, code, ExitUsage)
		}
	}
	if e.server.HealthCalls != 0 {
		t.Errorf("the server was asked %d times", e.server.HealthCalls)
	}
}

func TestCustomPutShowsTheServersObjection(t *testing.T) {
	e := newEnv(t)
	f := e.file("empty.txt", "\n \n")
	code, _, errs := e.run("custom", "put", "taglines", f)
	if code == 0 || !strings.Contains(errs, "no usable line") {
		t.Fatalf("code %d: %s", code, errs)
	}
}
