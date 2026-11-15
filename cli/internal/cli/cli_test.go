package cli

import (
	"bytes"
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
