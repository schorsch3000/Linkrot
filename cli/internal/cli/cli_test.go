package cli

import (
	"bytes"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/schorsch3000/Linkrot/internal/config"
)

// run executes the CLI under the given program name with a private config file.
func run(t *testing.T, name string, args ...string) (code int, stdout, stderr string) {
	t.Helper()
	t.Setenv(config.EnvPath, filepath.Join(t.TempDir(), "config.toml"))
	return runWithConfig(t, name, args...)
}

func runWithConfig(t *testing.T, name string, args ...string) (code int, stdout, stderr string) {
	t.Helper()
	var out, errb bytes.Buffer
	code = Execute(name, args, &out, &errb)
	return code, out.String(), errb.String()
}

func TestConfigSetAndShow(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.toml")
	t.Setenv(config.EnvPath, path)

	if code, _, errs := runWithConfig(t, "linkrot", "config", "--server", "https://lr.example/"); code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	if code, _, errs := runWithConfig(t, "linkrot", "config", "--token", "lr_abcdefghijklmnop"); code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	code, out, _ := runWithConfig(t, "linkrot", "config")
	if code != 0 {
		t.Fatalf("code %d", code)
	}
	for _, want := range []string{"server: https://lr.example\n", "token:  lr_abc…op\n", "file:   " + path} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if strings.Contains(out, "lr_abcdefghijklmnop") {
		t.Error("token must be masked")
	}
	c, err := config.Load(path)
	if err != nil || c.Server != "https://lr.example" || c.Token != "lr_abcdefghijklmnop" {
		t.Fatalf("saved config %+v, %v", c, err)
	}
	info, err := os.Stat(path)
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("mode: %v, %v", info, err)
	}
}

func TestConfigShowEmpty(t *testing.T) {
	code, out, _ := run(t, "linkrot", "config")
	if code != 0 || !strings.Contains(out, "server: (not set)") || !strings.Contains(out, "token:  (not set)") {
		t.Fatalf("code %d, out %q", code, out)
	}
}

func TestUsageErrorsExitWith2(t *testing.T) {
	cases := [][]string{
		{"nope"},
		{"config", "--bogus"},
		{"config", "extra"},
		{"config", "--server", "not a url"},
		{"config", "--token", ""},
	}
	for _, args := range cases {
		code, _, errs := run(t, "linkrot", args...)
		if code != ExitUsage {
			t.Errorf("%v: code %d, stderr %q", args, code, errs)
		}
		if !strings.HasPrefix(errs, "linkrot: ") {
			t.Errorf("%v: stderr %q", args, errs)
		}
	}
}

func TestProgramNameComesFromArgv0(t *testing.T) {
	_, out, _ := run(t, "lr")
	if !strings.Contains(out, "lr uploads files") || !strings.Contains(out, "lr [command]") {
		t.Fatalf("help does not use the program name:\n%s", out)
	}
	if strings.Contains(out, "linkrot") {
		t.Fatalf("help mentions linkrot although called as lr:\n%s", out)
	}
	_, out, _ = run(t, "weird-name", "config", "--help")
	if !strings.Contains(out, "weird-name config --server") {
		t.Fatalf("examples do not use the program name:\n%s", out)
	}
}

func TestCompletionScriptsUseProgramName(t *testing.T) {
	for _, shell := range []string{"bash", "zsh", "fish"} {
		code, out, errs := run(t, "lr", "completion", shell)
		if code != 0 || !strings.Contains(out, "lr") || strings.Contains(out, "linkrot") {
			t.Errorf("%s: code %d, stderr %q", shell, code, errs)
		}
	}
}

func TestVersionFlag(t *testing.T) {
	old := Version
	Version = "1.2.3"
	t.Cleanup(func() { Version = old })

	code, out, _ := run(t, "lr", "--version")

	if code != 0 || out != "lr 1.2.3\n" {
		t.Fatalf("code %d, out %q", code, out)
	}
}

// The token on the command line shows up in shell history and in ps; "--token -" takes it from stdin instead.
func TestConfigTokenFromStdin(t *testing.T) {
	for name, tc := range map[string]struct{ stdin, want string }{
		"one line":        {"lr_abcdefghijklmnop\n", "lr_abcdefghijklmnop"},
		"no newline":      {"lr_abcdefghijklmnop", "lr_abcdefghijklmnop"},
		"crlf and blanks": {"  lr_abcdefghijklmnop \r\n", "lr_abcdefghijklmnop"},
		"only the first":  {"lr_abcdefghijklmnop\nsomething else\n", "lr_abcdefghijklmnop"},
		"long token":      {strings.Repeat("a", 200) + "\n", strings.Repeat("a", 200)},
	} {
		t.Run(name, func(t *testing.T) {
			path := filepath.Join(t.TempDir(), "config.toml")
			t.Setenv(config.EnvPath, path)
			var out, errb bytes.Buffer
			app := &App{Name: "lr", In: strings.NewReader(tc.stdin), Out: &out, Err: &errb, ConfigPath: config.Path}

			code := app.Run([]string{"config", "--token", "-"})

			if code != 0 {
				t.Fatalf("code %d: %s", code, errb.String())
			}
			c, err := config.Load(path)
			if err != nil || c.Token != tc.want {
				t.Fatalf("token %q, %v; want %q", c.Token, err, tc.want)
			}
		})
	}
}

func TestConfigTokenFromStdinMustNotBeEmpty(t *testing.T) {
	for name, stdin := range map[string]string{"nothing": "", "blank line": "\n", "blanks": "  \r\n"} {
		t.Run(name, func(t *testing.T) {
			path := filepath.Join(t.TempDir(), "config.toml")
			t.Setenv(config.EnvPath, path)
			var out, errb bytes.Buffer
			app := &App{Name: "lr", In: strings.NewReader(stdin), Out: &out, Err: &errb, ConfigPath: config.Path}

			code := app.Run([]string{"config", "--token", "-"})

			if code != ExitUsage || !strings.Contains(errb.String(), "token") {
				t.Fatalf("code %d, stderr %q", code, errb.String())
			}
			if _, err := os.Stat(path); err == nil {
				t.Fatal("the config must not be written")
			}
		})
	}
}

func TestConfigTokenFromStdinCombinesWithServer(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.toml")
	t.Setenv(config.EnvPath, path)
	app := &App{Name: "lr", In: strings.NewReader("lr_abcdefghijklmnop\n"), Out: &bytes.Buffer{}, Err: &bytes.Buffer{}, ConfigPath: config.Path}

	if code := app.Run([]string{"config", "--server", "https://lr.example", "--token", "-"}); code != 0 {
		t.Fatalf("code %d", code)
	}

	c, err := config.Load(path)
	if err != nil || c.Server != "https://lr.example" || c.Token != "lr_abcdefghijklmnop" {
		t.Fatalf("%+v %v", c, err)
	}
}

// "--token" without a value asks for the token on the terminal, hidden. Tests play the terminal with ReadSecret.
func promptApp(t *testing.T, secret string) (app *App, errb *bytes.Buffer, prompts *[]string) {
	t.Helper()
	errb = &bytes.Buffer{}
	prompts = new([]string)
	app = &App{
		Name: "lr", In: strings.NewReader("never read\n"), Out: &bytes.Buffer{}, Err: errb, ConfigPath: config.Path,
		ReadSecret: func(prompt string) (string, error) { *prompts = append(*prompts, prompt); return secret, nil },
	}
	return app, errb, prompts
}

func TestConfigTokenWithoutValuePrompts(t *testing.T) {
	for name, args := range map[string][]string{
		"alone":       {"config", "--token"},
		"before flag": {"config", "--token", "--server", "https://lr.example"},
		"after flag":  {"config", "--server", "https://lr.example", "--token"},
	} {
		t.Run(name, func(t *testing.T) {
			path := filepath.Join(t.TempDir(), "config.toml")
			t.Setenv(config.EnvPath, path)
			app, errb, prompts := promptApp(t, " lr_abcdefghijklmnop \n")

			code := app.Run(args)

			if code != 0 {
				t.Fatalf("code %d: %s", code, errb.String())
			}
			if len(*prompts) != 1 || !strings.Contains((*prompts)[0], "oken") {
				t.Errorf("prompts %q", *prompts)
			}
			c, err := config.Load(path)
			if err != nil || c.Token != "lr_abcdefghijklmnop" || (len(args) > 2) != (c.Server == "https://lr.example") {
				t.Fatalf("%+v %v", c, err)
			}
		})
	}
}

func TestConfigTokenGivenAsValueStillWorksAndDoesNotPrompt(t *testing.T) {
	for name, args := range map[string][]string{
		"space":        {"config", "--token", "lr_abcdefghijklmnop"},
		"equals":       {"config", "--token=lr_abcdefghijklmnop"},
		"after server": {"config", "--server", "https://lr.example", "--token", "lr_abcdefghijklmnop"},
		"before":       {"config", "--token", "lr_abcdefghijklmnop", "--server", "https://lr.example"},
	} {
		t.Run(name, func(t *testing.T) {
			path := filepath.Join(t.TempDir(), "config.toml")
			t.Setenv(config.EnvPath, path)
			app, errb, prompts := promptApp(t, "wrong")

			code := app.Run(args)

			if code != 0 || len(*prompts) != 0 {
				t.Fatalf("code %d, prompts %q: %s", code, *prompts, errb.String())
			}
			if c, err := config.Load(path); err != nil || c.Token != "lr_abcdefghijklmnop" {
				t.Fatalf("%+v %v", c, err)
			}
		})
	}
}

func TestConfigTokenPromptNeedsATerminal(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.toml")
	t.Setenv(config.EnvPath, path)
	var out, errb bytes.Buffer
	// stdin is a pipe or a file here, and has a token on it that must not be taken silently
	app := &App{Name: "lr", In: strings.NewReader("lr_abcdefghijklmnop\n"), Out: &out, Err: &errb, ConfigPath: config.Path}

	code := app.Run([]string{"config", "--token"})

	if code != ExitUsage || !strings.Contains(errb.String(), "terminal") || !strings.Contains(errb.String(), "--token -") {
		t.Fatalf("code %d, stderr %q", code, errb.String())
	}
	if _, err := os.Stat(path); err == nil {
		t.Fatal("the config must not be written")
	}
}

func TestConfigTokenPromptMustNotBeEmptyOrFail(t *testing.T) {
	for name, tc := range map[string]struct {
		secret string
		err    error
	}{
		"empty":  {"", nil},
		"blanks": {"  \n", nil},
		"failed": {"", io.ErrClosedPipe},
	} {
		t.Run(name, func(t *testing.T) {
			path := filepath.Join(t.TempDir(), "config.toml")
			t.Setenv(config.EnvPath, path)
			var errb bytes.Buffer
			app := &App{
				Name: "lr", Out: &bytes.Buffer{}, Err: &errb, ConfigPath: config.Path,
				ReadSecret: func(string) (string, error) { return tc.secret, tc.err },
			}

			code := app.Run([]string{"config", "--token"})

			if code == 0 || !strings.Contains(errb.String(), "token") {
				t.Fatalf("code %d, stderr %q", code, errb.String())
			}
			if _, err := os.Stat(path); err == nil {
				t.Fatal("the config must not be written")
			}
		})
	}
}

func TestConfigStrayArgumentsAreStillUsageErrors(t *testing.T) {
	for _, args := range [][]string{
		{"config", "extra"},
		{"config", "--server", "https://lr.example", "extra"},
		{"config", "--token", "lr_abcdefghijklmnop", "extra"},
		{"config", "--token", "--server", "https://lr.example", "extra"},
	} {
		app, errb, prompts := promptApp(t, "lr_abcdefghijklmnop")
		t.Setenv(config.EnvPath, filepath.Join(t.TempDir(), "config.toml"))

		if code := app.Run(args); code != ExitUsage {
			t.Errorf("%v: code %d, stderr %q, prompts %q", args, code, errb.String(), *prompts)
		}
	}
}

// a config file that others can read, behind a symlink as dotfile managers make them
func looseLinkedConfig(t *testing.T) (link, target string) {
	t.Helper()
	managed, home := t.TempDir(), t.TempDir()
	target, link = filepath.Join(managed, "config.toml"), filepath.Join(home, "config.toml")
	if err := os.WriteFile(target, []byte("server = 'https://old.example'\n"), 0o644); err != nil { //nolint:gosec // the point of the test
		t.Fatal(err)
	}
	if err := os.Chmod(target, 0o644); err != nil { //nolint:gosec // the point of the test
		t.Fatal(err)
	}
	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}
	t.Setenv(config.EnvPath, link)
	return link, target
}

func TestConfigRefusesToWriteTheTokenIntoAFileOthersCanRead(t *testing.T) {
	link, target := looseLinkedConfig(t)

	code, _, errs := runWithConfig(t, "lr", "config", "--token", "lr_abcdefghijklmnop")

	if code != ExitError {
		t.Fatalf("code %d, stderr %q", code, errs)
	}
	for _, want := range []string{target, "readable by others", "chmod 600 " + target, "--allow-unsafe-file"} {
		if !strings.Contains(errs, want) {
			t.Errorf("stderr lacks %q:\n%s", want, errs)
		}
	}
	if b, _ := os.ReadFile(target); string(b) != "server = 'https://old.example'\n" {
		t.Fatalf("the file was changed: %q", b)
	}
	_ = link
}

func TestConfigAllowUnsafeFileWritesItAndKeepsTheMode(t *testing.T) {
	link, target := looseLinkedConfig(t)

	code, _, errs := runWithConfig(t, "lr", "config", "--allow-unsafe-file", "--token", "lr_abcdefghijklmnop")

	if code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	if c, err := config.Load(link); err != nil || c.Token != "lr_abcdefghijklmnop" {
		t.Fatalf("%+v %v", c, err)
	}
	if info, _ := os.Stat(target); info.Mode().Perm() != 0o644 {
		t.Fatalf("mode %v", info.Mode().Perm())
	}
}

func TestLooseConfigFileIsWarnedAboutOnceAndNotWhenQuiet(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.toml")
	t.Setenv(config.EnvPath, path)
	if err := config.Save(path, config.Config{Server: "https://lr.example", Token: "lr_abcdefghijklmnop"}); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(path, 0o644); err != nil { //nolint:gosec // the point of the test
		t.Fatal(err)
	}

	_, _, errs := runWithConfig(t, "lr", "config")
	if strings.Count(errs, "readable by others") != 1 || !strings.Contains(errs, "chmod 600 "+path) {
		t.Errorf("no (single) warning:\n%s", errs)
	}
	if _, _, errs := runWithConfig(t, "lr", "-q", "config"); errs != "" {
		t.Errorf("-q must be silent:\n%s", errs)
	}
	if err := os.Chmod(path, 0o600); err != nil {
		t.Fatal(err)
	}
	if _, _, errs := runWithConfig(t, "lr", "config"); errs != "" {
		t.Errorf("a private file must not be warned about:\n%s", errs)
	}
}

func TestLooseConfigFileIsWarnedAboutWhenTheServerIsUsed(t *testing.T) {
	e := newEnv(t)
	path, _ := config.Path()
	if err := os.Chmod(path, 0o640); err != nil { //nolint:gosec // the point of the test
		t.Fatal(err)
	}

	code, _, errs := e.run("ls")

	if code != 0 || !strings.Contains(errs, "readable by others") {
		t.Fatalf("code %d, stderr %q", code, errs)
	}
}
