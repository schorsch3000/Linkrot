package cli

import (
	"bytes"
	"context"
	"io"
	"strings"
	"testing"
	"time"

	"github.com/schorsch3000/Linkrot/internal/config"
)

// runAsking is env.run with a stdin-less ReadLine that answers the prompt for the mailed code.
func (e *env) runAsking(code string, args ...string) (int, string, string, []string) {
	e.t.Helper()
	var out, errb bytes.Buffer
	var prompts []string
	app := &App{
		Name: "lr", Out: &out, Err: &errb, ConfigPath: config.Path,
		Sleep: func(context.Context, time.Duration) error { return nil },
		ReadLine: func(prompt string) (string, error) {
			prompts = append(prompts, prompt)
			return code, nil
		},
	}
	return app.Run(args), out.String(), errb.String(), prompts
}

func TestAuthCreatesATokenAndSavesItWithServerAndEmail(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"
	path, _ := config.Path()
	if err := config.Save(path, config.Config{}); err != nil { // a fresh machine
		t.Fatal(err)
	}

	code, out, errs, prompts := e.runAsking("abcd-efgh", "auth", "--server", e.server.URL, "--email", "Owner@Example.com", "--name", "laptop")

	if code != 0 || out != "" {
		t.Fatalf("code %d, stdout %q: %s", code, out, errs)
	}
	if len(prompts) != 1 || prompts[0] != "Code: " {
		t.Errorf("prompts %q", prompts)
	}
	c, _ := config.Load(path)
	if c.Server != e.server.URL || c.Token != "lr_made_laptop" || c.Email != "owner@example.com" {
		t.Errorf("config %+v", c)
	}
	if _, ok := e.server.Auth.Tokens["laptop"]; !ok {
		t.Errorf("server tokens %v", e.server.Auth.Tokens)
	}
	if strings.Contains(errs, "lr_made_laptop") {
		t.Errorf("the token must not be printed: %s", errs)
	}
}

func TestAuthWithAWrongAddressLooksLikeAMailThatNeverComes(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"

	code, _, errs, _ := e.runAsking("ABCD-EFGH", "auth", "--email", "stranger@example.com")

	if code == 0 || !strings.Contains(errs, "wrong or expired") {
		t.Fatalf("code %d: %s", code, errs)
	}
	if e.server.Auth.Starts != 1 || len(e.server.Auth.Tokens) != 0 {
		t.Errorf("starts %d, tokens %v", e.server.Auth.Starts, e.server.Auth.Tokens)
	}
}

func TestAuthWrongCodeCreatesNothingAndKeepsTheConfig(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"
	path, _ := config.Path()

	code, _, errs, _ := e.runAsking("NOPE-NOPE", "auth", "--email", "owner@example.com")

	if code == 0 || !strings.Contains(errs, "wrong or expired") {
		t.Fatalf("code %d: %s", code, errs)
	}
	if c, _ := config.Load(path); c.Token != e.server.Token {
		t.Errorf("token changed to %q", c.Token)
	}
}

func TestAuthNeedsServerAndEmail(t *testing.T) {
	e := newEnv(t)
	path, _ := config.Path()
	if code, _, errs, _ := e.runAsking("x", "auth"); code != ExitUsage || !strings.Contains(errs, "--email") {
		t.Errorf("no email: code %d: %s", code, errs)
	}
	if err := config.Save(path, config.Config{}); err != nil {
		t.Fatal(err)
	}
	if code, _, errs, _ := e.runAsking("x", "auth", "--email", "a@example.com"); code != ExitUsage || !strings.Contains(errs, "no server") {
		t.Errorf("no server: code %d: %s", code, errs)
	}
	if code, _, _, _ := e.runAsking("x", "auth", "--email", "nope"); code != ExitUsage {
		t.Errorf("bad email: code %d", code)
	}
	if code, _, _, _ := e.runAsking("x", "auth", "--email", "a@example.com", "--name", "Bad Name"); code != ExitUsage {
		t.Errorf("bad name: code %d", code)
	}
}

func TestAuthRefusesATakenName(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"
	e.server.Auth.Tokens = map[string]string{"laptop": "lr_x"}

	code, _, errs, _ := e.runAsking("ABCD-EFGH", "auth", "--email", "owner@example.com", "--name", "laptop")

	if code == 0 || !strings.Contains(errs, "--name") {
		t.Fatalf("code %d: %s", code, errs)
	}
}

func TestTokenCommandsNeedAFreshCodeEachTime(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"
	e.server.Auth.Tokens = map[string]string{"old": "lr_old"}
	common := []string{"--email", "owner@example.com"}

	code, out, errs, _ := e.runAsking("ABCD-EFGH", append([]string{"token", "new", "phone"}, common...)...)
	if code != 0 || out != "lr_made_phone\n" {
		t.Fatalf("new: code %d, out %q: %s", code, out, errs)
	}
	code, out, errs, _ = e.runAsking("ABCD-EFGH", append([]string{"token", "ls"}, common...)...)
	if code != 0 || out != "old\tnever\nphone\tnever\n" {
		t.Fatalf("ls: code %d, out %q: %s", code, out, errs)
	}
	code, _, errs, _ = e.runAsking("ABCD-EFGH", append([]string{"token", "rm", "old"}, common...)...)
	if code != 0 {
		t.Fatalf("rm: code %d: %s", code, errs)
	}
	if _, ok := e.server.Auth.Tokens["old"]; ok || e.server.Auth.Starts != 3 {
		t.Errorf("tokens %v, starts %d (one code per command)", e.server.Auth.Tokens, e.server.Auth.Starts)
	}
}

func TestTokenCommandsUseTheEmailTheConfigRemembers(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"
	path, _ := config.Path()
	if err := config.Save(path, config.Config{Server: e.server.URL, Token: e.server.Token, Email: "owner@example.com"}); err != nil {
		t.Fatal(err)
	}

	if code, out, errs, _ := e.runAsking("ABCD-EFGH", "token", "ls", "--json"); code != 0 || !strings.HasPrefix(out, "[]") {
		t.Fatalf("code %d, out %q: %s", code, out, errs)
	}
}

func TestTokenRmOfAnUnknownNameFails(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"

	code, _, errs, _ := e.runAsking("ABCD-EFGH", "token", "rm", "ghost", "--email", "owner@example.com")

	if code != ExitError || !strings.Contains(errs, "no such token") {
		t.Fatalf("code %d: %s", code, errs)
	}
}

func TestTokenNewRefusesABadNameWithoutMailingAnything(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"

	code, _, _, _ := e.runAsking("x", "token", "new", "Bad_Name", "--email", "owner@example.com")

	if code != ExitUsage || e.server.Auth.Starts != 0 {
		t.Fatalf("code %d, starts %d", code, e.server.Auth.Starts)
	}
}

func TestDefaultTokenNameIsAcceptedByTheServer(t *testing.T) {
	if name := defaultTokenName(); !tokenName.MatchString(name) {
		t.Errorf("%q", name)
	}
}

// runScript is env.run with the answers to the prompts in order: the code first, then what is typed at `token>`.
// Running out of answers is the end of the input (Ctrl+D).
func (e *env) runScript(answers []string, args ...string) (int, string, string) {
	e.t.Helper()
	var out, errb bytes.Buffer
	app := &App{
		Name: "lr", Out: &out, Err: &errb, ConfigPath: config.Path,
		Sleep: func(context.Context, time.Duration) error { return nil },
		ReadLine: func(string) (string, error) {
			if len(answers) == 0 {
				return "", io.EOF
			}
			next := answers[0]
			answers = answers[1:]
			return next, nil
		},
	}
	return app.Run(args), out.String(), errb.String()
}

func TestTokenSessionDoesSeveralOperationsWithOneCode(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"
	e.server.Auth.Tokens = map[string]string{"old": "lr_old"}

	code, out, errs := e.runScript([]string{"ABCD-EFGH", "ls", "new phone", "rm old", "ls", "quit"}, "token", "--email", "owner@example.com")

	if code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	if want := "old\tnever\nlr_made_phone\nphone\tnever\n"; out != want {
		t.Errorf("stdout %q, want %q", out, want)
	}
	if e.server.Auth.Starts != 1 {
		t.Errorf("%d codes were asked for, want 1", e.server.Auth.Starts)
	}
	if _, ok := e.server.Auth.Tokens["old"]; ok {
		t.Errorf("old was not revoked: %v", e.server.Auth.Tokens)
	}
}

func TestTokenSessionEndsCleanlyAtEndOfInput(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"

	if code, _, errs := e.runScript([]string{"ABCD-EFGH", "ls"}, "token", "--email", "owner@example.com"); code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
}

func TestTokenSessionShowsErrorsAndGoesOn(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"

	code, out, errs := e.runScript([]string{"ABCD-EFGH", "rm ghost", "bogus", "new Bad_Name", "new fine", "help", "q"}, "token", "--email", "owner@example.com")

	if code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	for _, want := range []string{"no such token", `unknown command "bogus"`, "use 1-32 characters", "list the tokens"} {
		if !strings.Contains(errs, want) {
			t.Errorf("stderr lacks %q:\n%s", want, errs)
		}
	}
	if out != "lr_made_fine\n" {
		t.Errorf("stdout %q: the session stopped early", out)
	}
}

func TestTokenSessionEndsWhenTheServerSaysItEnded(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"
	e.server.Auth.Expired = true

	code, _, errs := e.runScript([]string{"ABCD-EFGH", "ls", "ls"}, "token", "--email", "owner@example.com")

	if code != ExitError || !strings.Contains(errs, "session has ended") {
		t.Fatalf("code %d: %s", code, errs)
	}
}

func TestTokenSessionReadsTheCodeAndTheCommandsFromOnePipe(t *testing.T) {
	e := newEnv(t)
	e.server.Auth.Email = "owner@example.com"
	var out, errb bytes.Buffer
	app := &App{
		Name: "lr", Out: &out, Err: &errb, ConfigPath: config.Path, In: strings.NewReader("ABCD-EFGH\nnew phone\nls\n"),
		Sleep: func(context.Context, time.Duration) error { return nil },
	}

	if code := app.Run([]string{"token", "--email", "owner@example.com"}); code != 0 {
		t.Fatalf("code %d: %s", code, errb.String())
	}
	if out.String() != "lr_made_phone\nphone\tnever\n" {
		t.Errorf("stdout %q", out.String())
	}
}

func TestTokenWithStrayArgumentsIsAUsageError(t *testing.T) {
	e := newEnv(t)
	if code, _, _ := e.runScript(nil, "token", "frobnicate"); code != ExitUsage {
		t.Errorf("code %d", code)
	}
	if e.server.Auth.Starts != 0 {
		t.Error("a mail was asked for")
	}
}
