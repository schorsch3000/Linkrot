// Package e2e runs the real CLI code against the real PHP server (php -S), once directly and once behind
// nginx, which exercises the X-Accel-Redirect path. Tests are skipped if php, the server dependencies,
// APCu or nginx are not available.
package e2e

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/cli"
	"github.com/schorsch3000/Linkrot/internal/config"
)

const fileSize = 25_000_000 // three chunks with the minimum chunk size of 10 MB

// stack is a running server and how to reach it.
type stack struct {
	baseURL string // what the CLI talks to
	token   string
	dataDir string
	nginx   bool
}

// skip skips the test, unless LINKROT_E2E_REQUIRED is set (CI): then a missing prerequisite is a failure.
func skip(t *testing.T, reason string) {
	t.Helper()
	if os.Getenv("LINKROT_E2E_REQUIRED") != "" {
		t.Fatalf("end-to-end prerequisite missing: %s", reason)
	}
	t.Skip(reason)
}

func serverDir(t *testing.T) string {
	t.Helper()
	dir, err := filepath.Abs(filepath.Join("..", "..", "server"))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(dir, "app", "vendor", "autoload.php")); err != nil {
		skip(t, "server dependencies are not installed (make -C server install)")
	}
	if _, err := exec.LookPath("php"); err != nil {
		skip(t, "php not available")
	}
	return dir
}

func freePort(t *testing.T) int {
	t.Helper()
	l, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = l.Close() }()
	return l.Addr().(*net.TCPAddr).Port
}

func waitFor(t *testing.T, port int) {
	t.Helper()
	for range 100 {
		d := net.Dialer{Timeout: 100 * time.Millisecond}
		if c, err := d.DialContext(t.Context(), "tcp", fmt.Sprintf("127.0.0.1:%d", port)); err == nil {
			_ = c.Close()
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatalf("nothing is listening on port %d", port)
}

func start(t *testing.T, name string, args ...string) {
	t.Helper()
	cmd := exec.CommandContext(context.Background(), name, args...) //nolint:gosec // test helper with fixed programs
	var out bytes.Buffer
	cmd.Stdout, cmd.Stderr = &out, &out
	cmd.WaitDelay = 2 * time.Second // nginx workers keep the output pipe open after the master is gone
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_ = cmd.Process.Signal(syscall.SIGTERM) // nginx stops its workers on TERM, a kill would orphan them
		_ = cmd.Wait()
		if t.Failed() {
			t.Logf("%s output:\n%s", name, out.String())
		}
	})
}

// newStack starts php -S (and nginx in front of it if withNginx).
func newStack(t *testing.T, withNginx bool) *stack {
	t.Helper()
	server := serverDir(t)
	if withNginx {
		if _, err := exec.LookPath("nginx"); err != nil {
			skip(t, "nginx not available")
		}
	}
	tmp := t.TempDir()
	data := filepath.Join(tmp, "data")
	if err := os.MkdirAll(filepath.Join(data, "files"), 0o750); err != nil {
		t.Fatal(err)
	}
	phpPort := freePort(t)
	publicPort := phpPort
	if withNginx {
		publicPort = freePort(t)
	}
	baseURL := fmt.Sprintf("http://127.0.0.1:%d", publicPort)
	mode := "php"
	if withNginx {
		mode = "xaccel"
	}
	phpConfig := fmt.Sprintf("<?php return ['base_url' => %q, 'download_mode' => %q];\n", baseURL, mode)
	if err := os.WriteFile(filepath.Join(data, "config.php"), []byte(phpConfig), 0o600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("LINKROT_DATA", data)

	tokenCmd := exec.CommandContext(context.Background(), "php", "-d", "apc.enable_cli=1", filepath.Join(server, "app", "bin", "lr-auth"), "get", "end-to-end") //nolint:gosec // fixed program
	tokenOut, err := tokenCmd.Output()
	if err != nil {
		t.Fatalf("lr-auth: %v", err)
	}

	start(t, "php", "-d", "apc.enable_cli=1", "-S", fmt.Sprintf("127.0.0.1:%d", phpPort), "-t", filepath.Join(server, "htdocs"), filepath.Join(server, "htdocs", "index.php"))
	waitFor(t, phpPort)
	if withNginx {
		conf := filepath.Join(tmp, "nginx.conf")
		nginxConf := fmt.Sprintf(`pid %[1]s/nginx.pid;
daemon off;
events {}
http {
  access_log off;
  client_body_temp_path %[1]s/body; proxy_temp_path %[1]s/proxy; fastcgi_temp_path %[1]s/fcgi; uwsgi_temp_path %[1]s/uwsgi; scgi_temp_path %[1]s/scgi;
  server {
    listen 127.0.0.1:%[2]d;
    client_max_body_size 40m;
    location / { proxy_pass http://127.0.0.1:%[3]d; proxy_request_buffering on; }
    location /_files/ {
      internal;
      add_header X-Content-Type-Options nosniff always;
      add_header Referrer-Policy no-referrer always;
      add_header X-Rot $upstream_http_x_rot always;
      alias %[4]s/files/;
    }
  }
}
`, tmp, publicPort, phpPort, data)
		if err := os.WriteFile(conf, []byte(nginxConf), 0o600); err != nil {
			t.Fatal(err)
		}
		start(t, "nginx", "-c", conf, "-e", filepath.Join(tmp, "nginx-error.log"))
		waitFor(t, publicPort)
	}
	return &stack{baseURL: baseURL, token: strings.TrimSpace(string(tokenOut)), dataDir: data, nginx: withNginx}
}

// run executes the CLI and returns exit code, stdout and stderr.
func (s *stack) run(t *testing.T, args ...string) (int, string, string) {
	t.Helper()
	var out, errb bytes.Buffer
	app := &cli.App{
		Name: "lr", Out: &out, Err: &errb, ConfigPath: config.Path,
		Copy: func(string, string) error { return nil },
	}
	code := app.Run(args)
	return code, out.String(), errb.String()
}

func (s *stack) must(t *testing.T, args ...string) string {
	t.Helper()
	code, out, errs := s.run(t, args...)
	if code != 0 {
		t.Fatalf("%v: exit %d\nstdout: %s\nstderr: %s", args, code, out, errs)
	}
	return out
}

func sha(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

// reply is what get returns of a response; the body is read and closed already.
type reply struct {
	StatusCode int
	Header     http.Header
}

func get(t *testing.T, url string, header http.Header) (reply, []byte) {
	t.Helper()
	req, err := http.NewRequestWithContext(t.Context(), http.MethodGet, url, nil)
	if err != nil {
		t.Fatal(err)
	}
	for k, v := range header {
		req.Header[k] = v
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = resp.Body.Close() }()
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		t.Fatal(err)
	}
	return reply{resp.StatusCode, resp.Header}, body
}

func TestEndToEndDirect(t *testing.T) { lifecycle(t, newStack(t, false)) }

func TestEndToEndBehindNginx(t *testing.T) { lifecycle(t, newStack(t, true)) }

func lifecycle(t *testing.T, s *stack) {
	cfg := filepath.Join(t.TempDir(), "config.toml")
	t.Setenv(config.EnvPath, cfg)
	s.must(t, "config", "--server", s.baseURL, "--token", s.token)

	big := make([]byte, fileSize)
	if _, err := rand.Read(big); err != nil {
		t.Fatal(err)
	}
	src := filepath.Join(t.TempDir(), "big.bin")
	if err := os.WriteFile(src, big, 0o600); err != nil {
		t.Fatal(err)
	}

	// up: three chunks, JSON result
	var file api.File
	if err := json.Unmarshal([]byte(s.must(t, "up", src, "--rm-after", "2d", "--uses", "5", "--json")), &file); err != nil {
		t.Fatal(err)
	}
	if file.Status != "ready" || file.Size != fileSize || file.UsesLeft == nil || *file.UsesLeft != 5 || file.ExpiresAt == nil {
		t.Fatalf("file %+v", file)
	}
	if len(file.ID) != 16 || file.SHA256 != sha(big) {
		t.Fatalf("id %q sha %q", file.ID, file.SHA256)
	}
	onDisk, err := os.ReadFile(filepath.Join(s.dataDir, "files", file.ID)) //nolint:gosec // test path
	if err != nil || sha(onDisk) != sha(big) {
		t.Fatalf("stored file differs: %v", err)
	}

	// the same file again is refused, the existing URL is printed
	code, out, errs := s.run(t, "up", src)
	if code != cli.ExitDuplicate || strings.TrimSpace(out) != file.URL || !strings.Contains(errs, file.ID) {
		t.Fatalf("duplicate: exit %d stdout %q stderr %q", code, out, errs)
	}

	// ls, info, prefix resolution
	if out := s.must(t, "ls"); !strings.Contains(out, file.ID) || !strings.Contains(out, "big.bin") || !strings.Contains(out, "ready") {
		t.Fatalf("ls:\n%s", out)
	}
	if out := s.must(t, "info", file.ID[:5]); !strings.Contains(out, file.SHA256) || !strings.Contains(out, "(1d 23h)") && !strings.Contains(out, "(2d)") {
		t.Fatalf("info:\n%s", out)
	}

	// set: rename, new expiry from now, 3 uses
	newURL := strings.TrimSpace(s.must(t, "set", file.ID, "--mv", "renamed.bin", "--rm-after", "3d", "--uses", "3"))
	if newURL != s.baseURL+"/file/"+file.ID+"/renamed.bin" {
		t.Fatalf("new url %q", newURL)
	}

	// public pages: the page shows the new name, a stale name redirects
	resp, body := get(t, newURL, nil)
	if resp.StatusCode != 200 || !strings.Contains(string(body), "renamed.bin") || strings.Contains(string(body), "big.bin") {
		t.Fatalf("page: %d %s", resp.StatusCode, body)
	}
	client := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	redirect, err := client.Get(s.baseURL + "/file/" + file.ID + "/big.bin") //nolint:noctx // test
	if err != nil {
		t.Fatal(err)
	}
	_ = redirect.Body.Close()
	if redirect.StatusCode != 302 || redirect.Header.Get("Location") != "/file/"+file.ID+"/renamed.bin" {
		t.Fatalf("redirect: %d %q", redirect.StatusCode, redirect.Header.Get("Location"))
	}

	// the root answers with the about page, without a token
	if r, body := get(t, s.baseURL+"/", nil); r.StatusCode != 200 || !strings.Contains(string(body), "Links that rot.") {
		t.Fatalf("about page: %d %q", r.StatusCode, body)
	}

	// the favicon is a static file; nginx serves it in production, php -S does it here
	if r, body := get(t, s.baseURL+"/favicon.svg", nil); r.StatusCode != 200 || !strings.Contains(string(body), "<svg") || !strings.Contains(r.Header.Get("Content-Type"), "svg") {
		t.Fatalf("favicon: %d %q", r.StatusCode, r.Header.Get("Content-Type"))
	}

	// down: byte identical, never overwrites
	dir := t.TempDir()
	path := strings.TrimSpace(s.must(t, "down", file.ID[:6], dir))
	if path != filepath.Join(dir, "renamed.bin") {
		t.Fatalf("path %q", path)
	}
	if got, err := os.ReadFile(path); err != nil || sha(got) != sha(big) { //nolint:gosec // test path
		t.Fatalf("downloaded file differs: %v", err)
	}
	if code, _, errs := s.run(t, "down", file.ID, dir); code != cli.ExitError || !strings.Contains(errs, "not overwriting") {
		t.Fatalf("second down: %d %s", code, errs)
	}
	if _, err := os.Stat(path + ".part"); err == nil {
		t.Fatal(".part left behind")
	}

	// the download link itself: headers, ranges, the internal location is not reachable
	dl := s.baseURL + "/dl/" + file.ID + "/whatever"
	resp, _ = get(t, dl, http.Header{"Range": {"bytes=100-199"}})
	if resp.Header.Get("Content-Disposition") == "" || !strings.Contains(resp.Header.Get("Content-Disposition"), "renamed.bin") {
		t.Fatalf("content-disposition %q", resp.Header.Get("Content-Disposition"))
	}
	if s.nginx {
		if resp.StatusCode != 206 || resp.Header.Get("Content-Range") != fmt.Sprintf("bytes 100-199/%d", fileSize) {
			t.Fatalf("range through nginx: %d %q", resp.StatusCode, resp.Header.Get("Content-Range"))
		}
		if resp.Header.Get("X-Content-Type-Options") != "nosniff" {
			t.Fatal("nosniff must be set by nginx for X-Accel responses")
		}
		if resp.Header.Get("X-Rot") == "" {
			t.Fatal("the X-Rot tagline must get through nginx")
		}
		if r, _ := get(t, s.baseURL+"/_files/"+file.ID, nil); r.StatusCode != 404 {
			t.Fatalf("internal location reachable: %d", r.StatusCode)
		}
	}

	// uses: one left, the last download works, then the link is dead (resumed ranges would not count)
	s.must(t, "set", file.ID, "--uses", "1")
	if r, _ := get(t, dl, http.Header{"Range": {"bytes=10-"}}); r.StatusCode != 200 && r.StatusCode != 206 {
		t.Fatalf("ranged download: %d", r.StatusCode)
	}
	if r, _ := get(t, dl, nil); r.StatusCode != 200 {
		t.Fatalf("last use: %d", r.StatusCode)
	}
	if r, _ := get(t, dl, nil); r.StatusCode != 404 {
		t.Fatalf("exhausted file still served: %d", r.StatusCode)
	}
	if out := s.must(t, "ls"); strings.Contains(out, file.ID) {
		t.Fatalf("exhausted file still listed:\n%s", out)
	}

	// the request that found it exhausted already removed it; cleanup has nothing left to do
	if out := s.must(t, "cleanup"); !strings.HasPrefix(out, "removed ") {
		t.Fatalf("cleanup: %q", out)
	}
	if _, err := os.Stat(filepath.Join(s.dataDir, "files", file.ID)); err == nil {
		t.Fatal("exhausted file still on disk")
	}
	small := filepath.Join(t.TempDir(), "small.txt")
	if err := os.WriteFile(small, []byte("small"), 0o600); err != nil {
		t.Fatal(err)
	}
	smallURL := strings.TrimSpace(s.must(t, "up", small))
	smallID := strings.Split(smallURL, "/")[4]
	s.must(t, "rm", smallID[:4])
	if r, _ := get(t, s.baseURL+"/dl/"+smallID+"/small.txt", nil); r.StatusCode != 404 {
		t.Fatalf("removed file still served: %d", r.StatusCode)
	}

	// a wrong token is refused and, after ten failures, locked out for everybody on this address
	if err := config.Save(cfg, config.Config{Server: s.baseURL, Token: "lr_wrong"}); err != nil {
		t.Fatal(err)
	}
	if code, _, errs := s.run(t, "ls"); code != cli.ExitError || !strings.Contains(errs, "invalid token") {
		t.Fatalf("wrong token: %d %s", code, errs)
	}
	for range 10 {
		_, _, _ = s.run(t, "ls")
	}
	if err := config.Save(cfg, config.Config{Server: s.baseURL, Token: s.token}); err != nil {
		t.Fatal(err)
	}
	if code, _, errs := s.run(t, "ls"); code != cli.ExitError || !strings.Contains(errs, "too many failed attempts") {
		t.Fatalf("hard limit: %d %s", code, errs)
	}
}
