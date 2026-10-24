package cli

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/config"
)

// fixedNow is one second after the fake server's completion time, so files expire in 7d minus 1s.
func (e *env) runAt(args ...string) (code int, stdout, stderr string) {
	e.t.Helper()
	var out, errb bytes.Buffer
	app := &App{
		Name: "lr", Out: &out, Err: &errb, ConfigPath: config.Path,
		Now:   func() time.Time { return time.Unix(2000, 0) },
		Sleep: func(context.Context, time.Duration) error { return nil },
	}
	code = app.Run(args)
	return code, out.String(), errb.String()
}

func TestLs(t *testing.T) {
	e := newEnv(t)
	e.server.Add("a.txt", []byte("aaaa"))
	e.server.AddUploading("big.iso", 200, 50)

	code, out, errs := e.runAt("ls")

	if code != 0 || errs != "" {
		t.Fatalf("code %d: %s", code, errs)
	}
	lines := strings.Split(strings.TrimRight(out, "\n"), "\n")
	if len(lines) != 3 {
		t.Fatalf("output:\n%s", out)
	}
	for _, want := range []string{"ID", "NAME", "SIZE", "EXPIRES", "USES", "STATUS", "URL"} {
		if !strings.Contains(lines[0], want) {
			t.Errorf("header lacks %q: %s", want, lines[0])
		}
	}
	// newest first: the upload, then the ready file
	if !strings.Contains(lines[1], "big.iso") || !strings.Contains(lines[1], "uploading 25%") {
		t.Errorf("upload line: %s", lines[1])
	}
	if !strings.Contains(lines[2], "a.txt") || !strings.Contains(lines[2], "4 B") || !strings.Contains(lines[2], "7d") ||
		!strings.Contains(lines[2], "ready") || !strings.Contains(lines[2], e.server.URL+"/file/") {
		t.Errorf("ready line: %s", lines[2])
	}
}

func TestLsEmptyAndJSON(t *testing.T) {
	e := newEnv(t)
	if code, out, _ := e.runAt("ls"); code != 0 || out != "" {
		t.Fatalf("empty ls printed %q", out)
	}
	if _, out, _ := e.runAt("ls", "--json"); strings.TrimSpace(out) != "[]" {
		t.Fatalf("empty json %q", out)
	}
	e.server.Add("a.txt", []byte("aaaa"))
	var files []api.File
	_, out, _ := e.runAt("ls", "--json")
	if err := json.Unmarshal([]byte(out), &files); err != nil || len(files) != 1 || files[0].Name != "a.txt" {
		t.Fatalf("%v %q", err, out)
	}
}

func TestInfo(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("a.txt", []byte("aaaa"))

	code, out, errs := e.runAt("info", id)

	if code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	for _, want := range []string{id, "a.txt", "4 B (4 bytes)", "ready", "uses left:  unlimited", "(7d)", e.server.URL + "/file/" + id + "/a.txt"} {
		if !strings.Contains(out, want) {
			t.Errorf("missing %q in:\n%s", want, out)
		}
	}
	_, out, _ = e.runAt("info", id, "--json")
	var f api.File
	if json.Unmarshal([]byte(out), &f) != nil || f.ID != id {
		t.Fatalf("json %q", out)
	}
}

func TestIDPrefixResolution(t *testing.T) {
	e := newEnv(t)
	first := e.server.Add("first.txt", []byte("1"))
	var ids []string
	for i := range 10 {
		ids = append(ids, e.server.Add("f"+string(rune('a'+i)), []byte{byte(i + 2)}))
	}
	// ids tst1…(first) and tst1…(11th) share the prefix "tst1", every other digit is unique
	unique := ids[0][:4] // second file: tst2
	if code, out, errs := e.runAt("info", unique, "--json"); code != 0 {
		t.Fatalf("code %d: %s %s", code, out, errs)
	}

	code, _, errs := e.runAt("info", first[:4])
	if code != ExitError || !strings.Contains(errs, "ambiguous") || !strings.Contains(errs, first) {
		t.Errorf("ambiguous: code %d, %q", code, errs)
	}
	if code, _, errs := e.runAt("info", "nope"); code != ExitError || !strings.Contains(errs, "no file") {
		t.Errorf("unknown prefix: code %d, %q", code, errs)
	}
	if code, _, errs := e.runAt("info", "tst"); code != ExitUsage || !strings.Contains(errs, "too short") {
		t.Errorf("short: code %d, %q", code, errs)
	}
	if code, _, _ := e.runAt("info", first); code != 0 {
		t.Errorf("full id: code %d", code)
	}
	if code, _, errs := e.runAt("info", "abcdefghijklmnop"); code != ExitError || !strings.Contains(errs, "not found") {
		t.Errorf("unknown full id: code %d, %q", code, errs)
	}
}

func TestSet(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("a.txt", []byte("aaaa"))
	var body string
	e.server.Intercept = func(r *http.Request) (int, http.Header, string) {
		if r.Method == http.MethodPatch {
			b, _ := io.ReadAll(r.Body)
			body = string(b)
			r.Body = io.NopCloser(bytes.NewReader(b))
		}
		return 0, nil, ""
	}

	code, out, errs := e.runAt("set", id[:5], "--rm-after", "3d", "--mv", "b.txt", "--uses", "2")

	if code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	if out != e.server.URL+"/file/"+id+"/b.txt\n" {
		t.Fatalf("stdout %q", out)
	}
	for _, want := range []string{`"name":"b.txt"`, `"rm_after":"3d"`, `"uses":2`} {
		if !strings.Contains(body, want) {
			t.Errorf("patch %s lacks %s", body, want)
		}
	}

	if code, _, _ := e.runAt("set", id, "--uses", "0"); code != 0 || body != `{"uses":null}` {
		t.Errorf("uses 0 must clear: code %d body %s", code, body)
	}
}

func TestSetUsageErrors(t *testing.T) {
	e := newEnv(t)
	id := e.server.Add("a.txt", []byte("aaaa"))
	for _, args := range [][]string{
		{"set", id},
		{"set"},
		{"set", id, "--rm-after", "soon"},
		{"set", id, "--mv", ""},
		{"set", id, "--uses", "-1"},
	} {
		if code, _, errs := e.runAt(args...); code != ExitUsage {
			t.Errorf("%v: code %d, %q", args, code, errs)
		}
	}
}

func TestRm(t *testing.T) {
	e := newEnv(t)
	a := e.server.Add("a.txt", []byte("a"))
	b := e.server.Add("b.txt", []byte("b"))
	keep := e.server.Add("c.txt", []byte("c"))

	code, out, errs := e.runAt("rm", a, b[:5], "zzzz-unknown")

	if code != ExitError || out != "" {
		t.Fatalf("code %d, stdout %q", code, out)
	}
	if !strings.Contains(errs, "zzzz-unknown: no file") {
		t.Errorf("stderr %q", errs)
	}
	if e.server.Has(a) || e.server.Has(b) || !e.server.Has(keep) {
		t.Fatal("wrong set of files deleted: the failure of one id must not stop the others")
	}
	if code, _, _ := e.runAt("rm", keep); code != 0 || e.server.Has(keep) {
		t.Fatal("rm of a single id")
	}
	if code, _, _ := e.runAt("rm"); code != ExitUsage {
		t.Fatal("rm without ids is a usage error")
	}
}

func TestCleanup(t *testing.T) {
	e := newEnv(t)
	code, out, _ := e.runAt("cleanup")
	if code != 0 || out != "removed 0 database entries and 0 files\n" {
		t.Fatalf("code %d out %q", code, out)
	}
}

func TestRemaining(t *testing.T) {
	now := time.Unix(1000, 0)
	cases := map[int64]string{
		1000:                       "expired",
		500:                        "expired",
		1000 + 59*60:               "<1h",
		1000 + 5*3600 + 100:        "5h",
		1000 + 24*3600:             "1d",
		1000 + 2*86400 + 3*3600:    "2d 3h",
		1000 + 365*86400 + 86400/2: "365d 12h",
	}
	for at, want := range cases {
		if got := remaining(at, now); got != want {
			t.Errorf("%d: got %q want %q", at, got, want)
		}
	}
}
