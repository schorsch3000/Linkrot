package cli

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestSlugOf(t *testing.T) {
	for in, want := range map[string]string{
		"imprint.md":                    "imprint",
		"/tmp/Imprint_DE.md":            "imprint-de",
		"Datenschutz.markdown":          "datenschutz",
		"a b  c.md":                     "a-b-c",
		"---.md":                        "",
		"über.md":                       "ber",
		strings.Repeat("a", 60) + ".md": strings.Repeat("a", 40),
	} {
		if got := slugOf(in); got != want {
			t.Errorf("slugOf(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestPagePutUsesTheFileNameAndLsListsIt(t *testing.T) {
	e := newEnv(t)
	f := e.file("Imprint.md", "---\ntitle: Imprint\n---\nHello\n")

	code, out, errs := e.run("page", "put", f)
	if code != 0 || !strings.Contains(out, "imprint: published") || !strings.Contains(out, "/page/imprint") {
		t.Fatalf("put: code %d: %q %s", code, out, errs)
	}
	if e.server.Pages["imprint"] != "---\ntitle: Imprint\n---\nHello\n" {
		t.Fatalf("server has %v", e.server.Pages)
	}
	code, out, errs = e.run("page", "ls")
	if code != 0 || !strings.Contains(out, "imprint") || !strings.Contains(out, "NAME") {
		t.Fatalf("ls: code %d: %q %s", code, out, errs)
	}
	if _, out, _ := e.run("page", "ls", "--json"); !strings.Contains(out, `"slug": "imprint"`) {
		t.Fatalf("ls --json: %q", out)
	}
}

func TestPageLsMarksDrafts(t *testing.T) {
	e := newEnv(t)
	e.server.Pages = map[string]string{"draft": "---\npublished: false\n---\nx\n"}
	if _, out, _ := e.run("page", "ls"); !strings.Contains(out, "no (draft)") {
		t.Fatalf("ls: %q", out)
	}
}

func TestPageLsWithoutPagesPrintsNothing(t *testing.T) {
	e := newEnv(t)
	if code, out, _ := e.run("page", "ls"); code != 0 || out != "" {
		t.Fatalf("code %d: %q", code, out)
	}
	if _, out, _ := e.run("page", "ls", "--json"); strings.TrimSpace(out) != "[]" {
		t.Fatalf("json: %q", out)
	}
}

func TestPagePutNameAndStandardInput(t *testing.T) {
	e := newEnv(t)
	f := e.file("whatever.md", "x\n")
	if code, _, errs := e.run("page", "put", "--name", "legal", f); code != 0 || e.server.Pages["legal"] != "x\n" {
		t.Fatalf("code %d: %s %v", code, errs, e.server.Pages)
	}
	if code, _, _ := e.run("page", "put", "-"); code != ExitUsage {
		t.Errorf("stdin without --name: code %d, want %d", code, ExitUsage)
	}
}

func TestPageGetGivesTheOriginalBack(t *testing.T) {
	e := newEnv(t)
	e.server.Pages = map[string]string{"imprint": "---\r\ntitle: Ünïcode\r\n---\r\nno newline at the end"}
	code, out, errs := e.run("page", "get", "imprint")
	if code != 0 || out != e.server.Pages["imprint"] {
		t.Fatalf("code %d: %q %s", code, out, errs)
	}
	target := filepath.Join(e.dir, "down.md")
	if code, out, _ := e.run("page", "get", "imprint", "-o", target); code != 0 || !strings.Contains(out, "wrote") {
		t.Fatalf("get -o: %d %q", code, out)
	}
	if b, err := os.ReadFile(target); err != nil || string(b) != e.server.Pages["imprint"] {
		t.Fatalf("file %q, %v", b, err)
	}
	if _, out, _ := e.run("page", "get", "imprint", "--html"); out != "<p>rendered</p>" {
		t.Fatalf("html: %q", out)
	}
	if code, _, errs := e.run("page", "get", "nothing"); code == 0 || !strings.Contains(errs, "no such page") {
		t.Fatalf("unknown page: %d %s", code, errs)
	}
}

func TestPagePutShowsTheServersObjection(t *testing.T) {
	e := newEnv(t)
	code, _, errs := e.run("page", "put", e.file("bad.md", "<script>alert(1)</script>"))
	if code == 0 || !strings.Contains(errs, "raw HTML is not allowed") {
		t.Fatalf("code %d: %s", code, errs)
	}
}

func TestPageRmAndTemplate(t *testing.T) {
	e := newEnv(t)
	e.server.Pages = map[string]string{"a": "x", "b": "y"}
	if code, _, errs := e.run("page", "rm", "a", "b"); code != 0 || len(e.server.Pages) != 0 {
		t.Fatalf("rm: code %d %s %v", code, errs, e.server.Pages)
	}
	if _, out, _ := e.run("page", "template"); !strings.Contains(out, "published: false") {
		t.Fatalf("template: %q", out)
	}
	target := filepath.Join(e.dir, "t.md")
	if code, _, _ := e.run("page", "template", "-o", target); code != 0 {
		t.Fatal("template -o failed")
	}
	if b, err := os.ReadFile(target); err != nil || !strings.Contains(string(b), "Dummy") {
		t.Fatalf("file %q, %v", b, err)
	}
}

func TestPageRefusesBadInputBeforeAskingTheServer(t *testing.T) {
	e := newEnv(t)
	for _, args := range [][]string{{"page", "get"}, {"page", "put"}, {"page", "rm"}, {"page", "put", e.file("---.md", "x")}} {
		if code, _, _ := e.run(args...); code != ExitUsage {
			t.Errorf("%v: code %d, want %d", args, code, ExitUsage)
		}
	}
	if e.server.HealthCalls != 0 {
		t.Errorf("the server was asked %d times", e.server.HealthCalls)
	}
}
