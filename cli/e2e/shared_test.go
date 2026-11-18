package e2e

import (
	"bytes"
	"encoding/json"
	"fmt"
	"html"
	"io"
	"net/http"
	"net/http/cookiejar"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
	"time"
)

// The shared hosting archive (make -C server shared), set up through its web setup the way a user does it, with the
// probes of setup.js done here instead of in a browser, then the whole lifecycle against it.
//
//   - LINKROT_E2E_SHARED: the unpacked archive. php -S runs a copy of it without APCu, behind a router that does what
//     its .htaccess does.
//   - LINKROT_E2E_SHARED_URL, LINKROT_E2E_SHARED_ROOT and LINKROT_E2E_SHARED_CODE: a real web server (CI: Apache).
//     ROOT is the folder above the document root, as the test sees it; CODE a command that prints setup-code.php.
func TestEndToEndShared(t *testing.T) {
	var s *stack
	switch {
	case os.Getenv("LINKROT_E2E_SHARED_URL") != "":
		s = sharedRemote(t)
	case os.Getenv("LINKROT_E2E_SHARED") != "":
		s = sharedLocal(t, os.Getenv("LINKROT_E2E_SHARED"), false)
	default:
		t.Skip("LINKROT_E2E_SHARED (the unpacked archive) or LINKROT_E2E_SHARED_URL is not set")
	}
	lifecycle(t, s)
}

// The same where PHP may not create a folder next to the document root: the data ends up below it, locked.
func TestEndToEndSharedDataInTheDocumentRoot(t *testing.T) {
	archive := os.Getenv("LINKROT_E2E_SHARED")
	if archive == "" {
		t.Skip("LINKROT_E2E_SHARED (the unpacked archive) is not set")
	}
	s := sharedLocal(t, archive, true)
	if !strings.Contains(s.dataDir, filepath.Join("public_html", "linkrot-data")) {
		t.Fatalf("data is not below the document root: %s", s.dataDir)
	}
	lifecycle(t, s)
}

// The router for php -S: what the .htaccess of the archive does on Apache.
const sharedRouter = `<?php
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = $_SERVER['DOCUMENT_ROOT'];
if ($path === '/linkrot-setup-probe' || $path === '/setup.php') {
    $_SERVER['SCRIPT_NAME'] = '/setup.php';
    require $root . '/setup.php';
    return;
}
if (preg_match('~^/(linkrot-app|linkrot-data)(/|$)|^/(setup-code|linkrot-paths)\.php$~', $path)) {
    http_response_code(403);
    return;
}
if ($path !== '/' && is_file($root . $path) && !str_ends_with($path, '.php')) {
    return false;
}
require $root . '/index.php';
`

func copyTree(t *testing.T, from, to string) {
	t.Helper()
	err := filepath.Walk(from, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(from, path)
		target := filepath.Join(to, rel)
		if info.IsDir() {
			return os.MkdirAll(target, 0o750)
		}
		data, err := os.ReadFile(path) //nolint:gosec // test input
		if err != nil {
			return err
		}
		return os.WriteFile(target, data, info.Mode().Perm())
	})
	if err != nil {
		t.Fatal(err)
	}
}

func sharedLocal(t *testing.T, archive string, inside bool) *stack {
	t.Helper()
	if _, err := exec.LookPath("php"); err != nil {
		skip(t, "php not available")
	}
	base := t.TempDir()
	docroot := filepath.Join(base, "public_html")
	copyTree(t, archive, docroot)
	router := filepath.Join(base, "router.php")
	if err := os.WriteFile(router, []byte(sharedRouter), 0o600); err != nil {
		t.Fatal(err)
	}
	data := filepath.Join(base, "linkrot-data", "127.0.0.1")
	if inside {
		// nothing may be created next to the document root
		if err := os.Chmod(base, 0o555); err != nil { //nolint:gosec // a test folder
			t.Fatal(err)
		}
		t.Cleanup(func() { _ = os.Chmod(base, 0o750) }) //nolint:gosec // a test folder, to remove it again
		data = filepath.Join(docroot, "linkrot-data", "127.0.0.1")
	}
	port := freePort(t)
	// no APCu, as on most shared hosting: the limiter falls back to SQLite
	start(t, "php", "-d", "apc.enabled=0", "-S", fmt.Sprintf("127.0.0.1:%d", port), "-t", docroot, router)
	waitFor(t, port)
	url := fmt.Sprintf("http://127.0.0.1:%d", port)
	code := func() string {
		data, err := os.ReadFile(filepath.Join(docroot, "setup-code.php")) //nolint:gosec // test path
		if err != nil {
			t.Fatal(err)
		}
		return string(data)
	}
	token := setUpShared(t, url, code)
	return &stack{baseURL: url, token: token, dataDir: data}
}

func sharedRemote(t *testing.T) *stack {
	t.Helper()
	url := os.Getenv("LINKROT_E2E_SHARED_URL")
	root := os.Getenv("LINKROT_E2E_SHARED_ROOT")
	command := strings.Fields(os.Getenv("LINKROT_E2E_SHARED_CODE"))
	if root == "" || len(command) == 0 {
		t.Fatal("LINKROT_E2E_SHARED_URL needs LINKROT_E2E_SHARED_ROOT and LINKROT_E2E_SHARED_CODE")
	}
	code := func() string {
		out, err := exec.CommandContext(t.Context(), command[0], command[1:]...).Output() //nolint:gosec // from the test environment
		if err != nil {
			t.Fatalf("%v: %v", command, err)
		}
		return string(out)
	}
	token := setUpShared(t, url, code)
	// the setup puts the data next to the document root if it may, below it otherwise
	data := filepath.Join(root, "linkrot-data", "127.0.0.1")
	if _, err := os.Stat(data); err != nil {
		outside := err
		matches, _ := filepath.Glob(filepath.Join(root, "*", "linkrot-data", "127.0.0.1"))
		if len(matches) != 1 {
			listing, _ := exec.CommandContext(t.Context(), "ls", "-laR", root).CombinedOutput() //nolint:gosec // test path
			t.Fatalf("no data folder next to or below the document root (%v); %s:\n%s", outside, root, listing)
		}
		data = matches[0]
		rel, _ := filepath.Rel(root, data)
		public := "/" + strings.Join(strings.Split(filepath.ToSlash(rel), "/")[1:], "/")
		if r, _ := get(t, url+public+"/linkrot.sqlite", nil); r.StatusCode == 200 {
			t.Fatalf("the database below the document root can be downloaded: %s", public)
		}
	}
	return &stack{baseURL: url, token: token, dataDir: data}
}

// setup is a client of setup.php with its cookie.
type setup struct {
	t      *testing.T
	client *http.Client
	url    string
}

func (s *setup) post(data map[string]any) map[string]any {
	s.t.Helper()
	body, _ := json.Marshal(data)
	req, _ := http.NewRequestWithContext(s.t.Context(), http.MethodPost, s.url+"/setup.php", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Linkrot-Setup", "1")
	resp, err := s.client.Do(req)
	if err != nil {
		s.t.Fatal(err)
	}
	defer func() { _ = resp.Body.Close() }()
	raw, _ := io.ReadAll(resp.Body)
	var result map[string]any
	if err := json.Unmarshal(raw, &result); err != nil {
		s.t.Fatalf("%v: %d %s", data["action"], resp.StatusCode, raw)
	}
	if result["ok"] != true {
		s.t.Fatalf("%v: %s", data["action"], raw)
	}
	return result
}

func (s *setup) request(method, url string, header http.Header, body []byte) (int, string) {
	s.t.Helper()
	req, _ := http.NewRequestWithContext(s.t.Context(), method, url, bytes.NewReader(body))
	for k, v := range header {
		req.Header[k] = v
	}
	resp, err := s.client.Do(req)
	if err != nil {
		return 0, ""
	}
	defer func() { _ = resp.Body.Close() }()
	raw, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, string(raw)
}

var (
	codeLine      = regexp.MustCompile(`(?m)^//\s*CODE:\s*(\S+)\s*$`)
	dataAttribute = regexp.MustCompile(`data-([a-z-]+)="([^"]*)"`)
)

// setUpShared logs in, runs the probes, adds the host and returns a token for it.
func setUpShared(t *testing.T, url string, readCode func() string) string {
	t.Helper()
	jar, _ := cookiejar.New(nil)
	s := &setup{t: t, client: &http.Client{Jar: jar, Timeout: 60 * time.Second}, url: url}

	if status, body := s.request(http.MethodGet, url+"/setup.php", nil, nil); status != 200 || !strings.Contains(body, "setup-code.php") {
		t.Fatalf("login page: %d %s", status, body)
	}
	if status, _ := s.request(http.MethodGet, url+"/setup.php?probe=echo", nil, nil); status != 403 {
		t.Fatalf("the probes answer without a login: %d", status)
	}
	s.post(map[string]any{"action": "code"})
	m := codeLine.FindStringSubmatch(readCode())
	if m == nil {
		t.Fatal("no code in setup-code.php")
	}
	if status, body := s.request(http.MethodGet, url+"/setup-code.php", nil, nil); status == 200 && strings.Contains(body, m[1]) {
		t.Fatal("the code can be read over the web")
	}
	s.post(map[string]any{"action": "login", "code": m[1]})

	status, page := s.request(http.MethodGet, url+"/setup.php", nil, nil)
	if status != 200 || !strings.Contains(page, "Requirements") {
		t.Fatalf("setup page: %d %s", status, page)
	}
	attributes := map[string]string{}
	bodyTag := page[strings.Index(page, "<body"):]
	bodyTag = bodyTag[:strings.Index(bodyTag, ">")]
	for _, a := range dataAttribute.FindAllStringSubmatch(bodyTag, -1) {
		attributes[a[1]] = html.UnescapeString(a[2])
	}

	// what setup.js does
	probe := url + attributes["probe"]
	results := map[string]any{}
	methods := map[string]bool{}
	for _, method := range []string{"GET", "POST", "PUT", "PATCH", "DELETE"} {
		var body []byte
		if method != "GET" {
			body = []byte("x")
		}
		status, reply := s.request(method, probe+"?probe=echo", http.Header{"Authorization": {"Bearer linkrot-probe"}, "X-Linkrot-Setup": {"1"}}, body)
		var answer map[string]any
		_ = json.Unmarshal([]byte(reply), &answer)
		methods[method] = status == 200 && answer["method"] == method
		if method == "GET" {
			results["authorization"] = answer["authorization"] == "Bearer linkrot-probe"
		}
	}
	results["methods"] = methods
	for _, size := range strings.Split(attributes["sizes"], ",") {
		var n int
		_, _ = fmt.Sscan(size, &n)
		status, reply := s.request(http.MethodPatch, probe+"?probe=echo", http.Header{"X-Linkrot-Setup": {"1"}}, make([]byte, n))
		var answer map[string]any
		_ = json.Unmarshal([]byte(reply), &answer)
		if status == 200 && answer["length"] == float64(n) {
			results["largest"] = n
			break
		}
	}
	reachable := map[string]bool{}
	if u := attributes["data-url"]; u != "" {
		status, body := s.request(http.MethodGet, url+u, nil, nil)
		reachable["data"] = status == 200 && strings.Contains(body, attributes["expected"])
	}
	if u := attributes["app-url"]; u != "" {
		status, body := s.request(http.MethodGet, url+u, nil, nil)
		reachable["app"] = status == 200 && strings.Contains(body, `"name"`)
	} else {
		reachable["app"] = false
	}
	results["reachable"] = reachable
	modes := map[string]bool{}
	for _, mode := range []string{"xsendfile", "litespeed", "php"} {
		if mode == "litespeed" && attributes["litespeed"] != "1" {
			continue
		}
		status, body := s.request(http.MethodGet, probe+"?probe=send&mode="+mode, nil, nil)
		modes[mode] = status == 200 && body == attributes["expected"]
	}
	results["modes"] = modes
	decision := s.post(map[string]any{"action": "apply", "results": results})
	t.Logf("the setup decided: %v", decision)

	s.post(map[string]any{"action": "add_host", "host": "127.0.0.1"})
	s.post(map[string]any{"action": "rewrite", "host": ""})
	token, _ := s.post(map[string]any{"action": "token_new", "host": "127.0.0.1", "name": "end-to-end"})["token"].(string)
	s.post(map[string]any{"action": "migrate"})
	s.post(map[string]any{"action": "finish"})
	if status, _ := s.request(http.MethodGet, probe+"?probe=echo", nil, nil); status != 403 {
		t.Fatalf("the probes still answer after finish: %d", status)
	}
	return token
}
