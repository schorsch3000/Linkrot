package e2e

import (
	"bytes"
	"context"
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// The container image (docker build -t linkrot-e2e .) as a user of compose.yaml gets it: LINKROT_TLS=off, the data
// folder bind-mounted, the host enrolled with linkrot-admin. Runs only if LINKROT_E2E_IMAGE names the image; CI builds
// it and sets it.
func TestEndToEndContainer(t *testing.T) {
	image := os.Getenv("LINKROT_E2E_IMAGE")
	if image == "" {
		t.Skip("LINKROT_E2E_IMAGE is not set (the image to test, built with docker build)")
	}
	s, id := newContainerStack(t, image)

	// the healthcheck of the image: php-fpm's ping through nginx, from inside only
	if out := docker(t, "exec", id, "wget", "-q", "-O", "-", "http://127.0.0.1:8080/_ping"); strings.TrimSpace(out) != "pong" {
		t.Fatalf("ping from inside: %q", out)
	}
	if r, _ := get(t, s.baseURL+"/_ping", nil); r.StatusCode != http.StatusForbidden {
		t.Fatalf("ping from outside: %d, want 403", r.StatusCode)
	}

	lifecycle(t, s)
}

func docker(t *testing.T, args ...string) string {
	t.Helper()
	cmd := exec.CommandContext(t.Context(), "docker", args...) //nolint:gosec // test helper with fixed program
	var out, errb bytes.Buffer
	cmd.Stdout, cmd.Stderr = &out, &errb
	if err := cmd.Run(); err != nil {
		t.Fatalf("docker %s: %v\n%s%s", strings.Join(args, " "), err, out.String(), errb.String())
	}
	return out.String()
}

func newContainerStack(t *testing.T, image string) (*stack, string) {
	t.Helper()
	if _, err := exec.LookPath("docker"); err != nil {
		skip(t, "docker not available")
	}
	// the folder of the bind mount belongs to the user the container runs as, as compose.yaml asks for
	root := filepath.Join(t.TempDir(), "app_data")
	if err := os.Mkdir(root, 0o750); err != nil {
		t.Fatal(err)
	}
	port := freePort(t)
	id := strings.TrimSpace(docker(t, "run", "-d",
		"--user", fmt.Sprintf("%d:%d", os.Getuid(), os.Getgid()),
		"-p", fmt.Sprintf("127.0.0.1:%d:8080", port),
		"-v", root+":/srv/linkrot/app_data",
		"-e", "LINKROT_TLS=off",
		image))
	t.Cleanup(func() {
		if t.Failed() {
			logs, _ := exec.CommandContext(context.Background(), "docker", "logs", id).CombinedOutput() //nolint:gosec // fixed program
			t.Logf("container log:\n%s", logs)
		}
		_ = exec.CommandContext(context.Background(), "docker", "rm", "-f", id).Run() //nolint:gosec // fixed program
	})

	// one tenant, enrolled the way the documentation says; its default config.php hands the files to nginx
	docker(t, "exec", id, "linkrot-admin", "hosts", "add", "127.0.0.1")
	token := strings.TrimSpace(docker(t, "exec", id, "linkrot-admin", "lr-auth", "get", "end-to-end"))

	baseURL := fmt.Sprintf("http://127.0.0.1:%d", port)
	deadline := time.Now().Add(60 * time.Second)
	for {
		req, err := http.NewRequestWithContext(t.Context(), http.MethodGet, baseURL+"/health", nil)
		if err != nil {
			t.Fatal(err)
		}
		req.Header.Set("Authorization", "Bearer "+token)
		resp, err := http.DefaultClient.Do(req)
		if err == nil {
			_ = resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				break
			}
		}
		if time.Now().After(deadline) {
			t.Fatalf("the container does not answer /health: %v", err)
		}
		time.Sleep(250 * time.Millisecond)
	}
	return &stack{baseURL: baseURL, token: token, dataDir: filepath.Join(root, "127.0.0.1"), nginx: true}, id
}
