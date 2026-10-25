package cli

import (
	"bytes"
	"context"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/color"
	"github.com/schorsch3000/Linkrot/internal/config"
)

// runColored is like env.runAt, but with colors forced on.
func (e *env) runColored(args ...string) (code int, stdout, stderr string) {
	e.t.Helper()
	var out, errb bytes.Buffer
	app := &App{
		Name: "lr", Out: &out, Err: &errb, ConfigPath: config.Path, Paint: color.Force(),
		Now:   func() time.Time { return time.Unix(2000, 0) },
		Sleep: func(context.Context, time.Duration) error { return nil },
	}
	code = app.Run(args)
	return code, out.String(), errb.String()
}

func TestHealthIsAskedBeforeEveryServerAction(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("a.txt", []byte("aaaa"))
	f := e.file("up.txt", "hello")

	for _, args := range [][]string{{"ls"}, {"info", id}, {"set", id, "--uses", "2"}, {"cleanup"}, {"up", f}, {"rm", id}} {
		before := e.server.HealthCalls
		if code, _, errs := e.run(args...); code != 0 {
			t.Fatalf("%v: code %d: %s", args, code, errs)
		}
		if e.server.HealthCalls != before+1 {
			t.Errorf("%v: health asked %d times", args, e.server.HealthCalls-before)
		}
	}
}

func TestHealthIsNotAskedWithoutAServerAction(t *testing.T) {
	e := newEnv(t)

	for _, args := range [][]string{{"--version"}, {"config"}, {"help"}, {"completion", "bash"}} {
		if code, _, errs := e.run(args...); code != 0 {
			t.Fatalf("%v: code %d: %s", args, code, errs)
		}
	}
	if e.server.HealthCalls != 0 {
		t.Errorf("health asked %d times", e.server.HealthCalls)
	}
}

func TestWarningsAreYellowOnStderrAndTheCommandGoesOn(t *testing.T) {
	e := newEnv(t)
	e.server.Add("a.txt", []byte("aaaa"))
	e.server.Health = &api.Health{Status: api.LevelWarn, Checks: []api.HealthCheck{
		{Name: "disk", Level: api.LevelOK, Message: "fine"},
		{Name: "cleanup", Level: api.LevelWarn, Message: "the cleanup has not run yet"},
	}}

	code, out, errs := e.runColored("ls")

	if code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	if want := "\x1b[33mlr: warning: server cleanup: the cleanup has not run yet\x1b[0m\n"; errs != want {
		t.Errorf("stderr %q, want %q", errs, want)
	}
	if !strings.Contains(out, "a.txt") || strings.Contains(out, "warning") {
		t.Errorf("stdout must hold the result only:\n%s", out)
	}
}

func TestPlainWarningWithoutATerminal(t *testing.T) {
	e := newEnv(t)
	e.server.Health = &api.Health{Status: api.LevelWarn, Checks: []api.HealthCheck{{Name: "disk", Level: api.LevelWarn, Message: "low disk space"}}}

	code, _, errs := e.runAt("ls")

	if code != 0 || errs != "lr: warning: server disk: low disk space\n" {
		t.Errorf("code %d, stderr %q", code, errs)
	}
}

func TestAnErrorIsRedAndStopsTheCommand(t *testing.T) {
	e := newEnv(t)
	f := e.file("up.txt", "hello")
	e.server.Health = &api.Health{Status: api.LevelError, Checks: []api.HealthCheck{
		{Name: "storage", Level: api.LevelError, Message: "the data directory is missing or not writable"},
		{Name: "cleanup", Level: api.LevelWarn, Message: "the cleanup has not run yet"},
	}}

	code, out, errs := e.runColored("up", f)

	if code != 1 || out != "" {
		t.Fatalf("code %d, stdout %q", code, out)
	}
	if !strings.Contains(errs, "\x1b[31mlr: server storage: the data directory is missing or not writable\x1b[0m\n") {
		t.Errorf("no red error line:\n%q", errs)
	}
	if !strings.Contains(errs, "\x1b[33mlr: warning: server cleanup") || !strings.Contains(errs, "not continuing") {
		t.Errorf("stderr:\n%q", errs)
	}
	if e.server.Chunks != 0 || len(e.server.Content("x")) != 0 {
		t.Error("nothing may be uploaded")
	}
}

func TestServerWithoutHealthEndpointIsFine(t *testing.T) {
	e := newEnv(t)
	e.server.Add("a.txt", []byte("aaaa"))
	e.server.Intercept = func(r *http.Request) (int, http.Header, string) {
		if r.URL.Path == "/api/v1/health" {
			return 404, nil, `{"error":{"code":"not_found","message":"not found"}}`
		}
		return 0, nil, ""
	}

	code, out, errs := e.run("ls")

	if code != 0 || errs != "" || !strings.Contains(out, "a.txt") {
		t.Errorf("code %d, stderr %q", code, errs)
	}
}

func TestHealthFailureIsTheNormalError(t *testing.T) {
	e := newEnv(t)
	e.server.Token = "something-else"

	code, _, errs := e.run("ls")

	if code != 1 || !strings.Contains(errs, "unauthorized") {
		t.Errorf("code %d, stderr %q", code, errs)
	}
}
