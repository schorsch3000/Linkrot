package cli

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/config"
	"github.com/schorsch3000/Linkrot/internal/testutil"
)

const content = "hello linkrot, this is 31 bytes"

type env struct {
	t      *testing.T
	server *testutil.FakeServer
	copied []string // "selection:text"
	dir    string
}

// newEnv starts a fake server and writes a config pointing at it.
func newEnv(t *testing.T) *env {
	t.Helper()
	s := testutil.NewFakeServer(t)
	path := filepath.Join(t.TempDir(), "config.toml")
	t.Setenv(config.EnvPath, path)
	if err := config.Save(path, config.Config{Server: s.URL, Token: s.Token}); err != nil {
		t.Fatal(err)
	}
	return &env{t: t, server: s, dir: t.TempDir()}
}

func (e *env) file(name, data string) string {
	e.t.Helper()
	p := filepath.Join(e.dir, name)
	if err := os.WriteFile(p, []byte(data), 0o600); err != nil {
		e.t.Fatal(err)
	}
	return p
}

func (e *env) run(args ...string) (code int, stdout, stderr string) {
	e.t.Helper()
	var out, errb bytes.Buffer
	app := &App{
		Name: "lr", Out: &out, Err: &errb, ConfigPath: config.Path,
		Copy:  func(sel, text string) error { e.copied = append(e.copied, sel+":"+text); return nil },
		Sleep: func(context.Context, time.Duration) error { return nil },
	}
	code = app.Run(args)
	return code, out.String(), errb.String()
}

func TestUpPrintsOnlyTheURLOnStdout(t *testing.T) {
	e := newEnv(t)

	code, out, errs := e.run("up", e.file("report.txt", content))

	if code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	if !strings.HasPrefix(out, e.server.URL+"/file/") || strings.Count(out, "\n") != 1 || !strings.HasSuffix(out, "/report.txt\n") {
		t.Fatalf("stdout %q", out)
	}
	if errs != "" {
		t.Fatalf("stderr %q", errs)
	}
	if len(e.copied) != 0 {
		t.Fatal("must not copy without --copy")
	}
}

func TestUpMvAndOptionsReachTheServer(t *testing.T) {
	e := newEnv(t)
	var created api.CreateRequest
	e.server.Intercept = func(r *http.Request) (int, http.Header, string) {
		if r.Method == http.MethodPost && r.URL.Path == "/api/v1/files" {
			body, _ := io.ReadAll(r.Body)
			_ = json.Unmarshal(body, &created)
			r.Body = io.NopCloser(bytes.NewReader(body))
		}
		return 0, nil, ""
	}

	code, out, errs := e.run("up", e.file("report.txt", content), "--mv", "other.bin", "--rm-after", "3w", "--uses", "2")

	if code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	if !strings.HasSuffix(out, "/other.bin\n") {
		t.Fatalf("stdout %q", out)
	}
	if created.Name != "other.bin" || created.RmAfter != "3w" || created.Uses == nil || *created.Uses != 2 || created.Size != int64(len(content)) {
		t.Fatalf("create request %+v", created)
	}
}

func TestUpJSON(t *testing.T) {
	e := newEnv(t)

	code, out, _ := e.run("up", e.file("a.txt", content), "--json")

	var f api.File
	if code != 0 || json.Unmarshal([]byte(out), &f) != nil || f.Status != "ready" || f.URL == "" || f.Size != int64(len(content)) {
		t.Fatalf("code %d out %q", code, out)
	}
}

func TestUpCopy(t *testing.T) {
	e := newEnv(t)

	_, out, _ := e.run("up", e.file("a.txt", content), "--copy")
	url := strings.TrimSpace(out)
	if len(e.copied) != 1 || e.copied[0] != "osc52:"+url {
		t.Fatalf("copied %v", e.copied)
	}

	e.copied = nil
	_, out, _ = e.run("up", e.file("b.txt", content+"!"), "--copy=primary")
	if len(e.copied) != 1 || e.copied[0] != "primary:"+strings.TrimSpace(out) {
		t.Fatalf("copied %v", e.copied)
	}
}

func TestUpCopyFailureOnlyWarns(t *testing.T) {
	e := newEnv(t)
	var out, errb bytes.Buffer
	app := &App{
		Name: "lr", Out: &out, Err: &errb, ConfigPath: config.Path,
		Copy: func(string, string) error { return clipErr{} },
	}

	code := app.Run([]string{"up", e.file("a.txt", content), "--copy=clipboard"})

	if code != 0 || !strings.Contains(errb.String(), "warning: could not copy the URL") || out.Len() == 0 {
		t.Fatalf("code %d, stderr %q", code, errb.String())
	}
}

type clipErr struct{}

func (clipErr) Error() string { return "no tool" }

func TestUpDuplicatePrintsExistingURLAndExits3(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("first.txt", []byte(content))

	code, out, errs := e.run("up", e.file("again.txt", content), "--copy")

	if code != ExitDuplicate {
		t.Fatalf("code %d", code)
	}
	if !strings.Contains(out, "/file/"+id+"/first.txt") || strings.Count(out, "\n") != 1 {
		t.Fatalf("stdout %q", out)
	}
	if !strings.Contains(errs, "already exists") || !strings.Contains(errs, id) {
		t.Fatalf("stderr %q", errs)
	}
	if len(e.copied) != 1 {
		t.Fatalf("the existing URL should be copied too: %v", e.copied)
	}

	_, out, _ = e.run("up", e.file("again.txt", content), "--json")
	var v map[string]any
	if json.Unmarshal([]byte(out), &v) != nil || v["duplicate"] != true || v["id"] != id {
		t.Fatalf("json %q", out)
	}
}

func TestUpResumesAfterFailure(t *testing.T) {
	e := newEnv(t)
	fail := true
	e.server.Intercept = func(r *http.Request) (int, http.Header, string) {
		if fail && r.Method == http.MethodPatch && r.Header.Get("Upload-Offset") == "10" {
			fail = false
			return 503, nil, "busy"
		}
		return 0, nil, ""
	}

	code, out, errs := e.run("up", e.file("a.txt", content))

	if code != 0 || out == "" {
		t.Fatalf("code %d: %s", code, errs)
	}
	if !strings.Contains(errs, "retrying in 1s") {
		t.Fatalf("stderr %q", errs)
	}
}

func TestUpUsageAndConfigErrors(t *testing.T) {
	e := newEnv(t)
	f := e.file("a.txt", content)
	for _, args := range [][]string{
		{"up"},
		{"up", f, "extra"},
		{"up", f, "--rm-after", "5min"},
		{"up", f, "--rm-after", "0h"},
		{"up", f, "--uses", "0"},
		{"up", f, "--mv", ""},
		{"up", f, "--copy=nonsense"},
		{"up", f, "--bogus"},
	} {
		if code, _, errs := e.run(args...); code != ExitUsage {
			t.Errorf("%v: code %d, stderr %q", args, code, errs)
		}
	}

	t.Setenv(config.EnvPath, filepath.Join(t.TempDir(), "empty.toml"))
	code, _, errs := e.run("up", f)
	if code != ExitError || !strings.Contains(errs, "not configured") {
		t.Errorf("unconfigured: code %d, stderr %q", code, errs)
	}
}

func TestUpFailures(t *testing.T) {
	e := newEnv(t)
	if code, _, errs := e.run("up", filepath.Join(e.dir, "missing")); code != ExitError || !strings.Contains(errs, "missing") {
		t.Errorf("missing file: code %d, stderr %q", code, errs)
	}
	e.server.Token = "other"
	if code, _, errs := e.run("up", e.file("a.txt", content)); code != ExitError || !strings.Contains(errs, "invalid token") {
		t.Errorf("wrong token: code %d, stderr %q", code, errs)
	}
}
