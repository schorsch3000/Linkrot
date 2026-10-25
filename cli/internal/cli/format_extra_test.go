package cli

import (
	"bytes"
	"errors"
	"net/http"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/color"
)

func TestUsesAndStatusTexts(t *testing.T) {
	two := int64(2)
	if usesText(api.File{}) != "-" || usesText(api.File{UsesLeft: &two}) != "2" {
		t.Error("usesText")
	}
	if usesLabel(api.File{}) != "unlimited" || usesLabel(api.File{UsesLeft: &two}) != "2" {
		t.Error("usesLabel")
	}
	if got := statusText(api.File{Status: api.StatusUploading, Size: 200, Offset: 50}); got != "uploading 25%" {
		t.Errorf("statusText: %q", got)
	}
	if got := statusText(api.File{Status: api.StatusUploading}); got != "uploading" {
		t.Errorf("an upload of unknown size shows no percentage: %q", got)
	}
}

func TestStatusStyles(t *testing.T) {
	cases := map[string]color.Style{api.StatusReady: color.Green, api.StatusUploading: color.Yellow, "weird": color.Red}
	for status, want := range cases {
		if got := statusStyle(api.File{Status: status}); got != want {
			t.Errorf("%s: %q, want %q", status, got, want)
		}
	}
}

func TestOnlyAnExpiredFileIsStyledAsExpired(t *testing.T) {
	now := time.Unix(1000, 0)
	past, future := int64(999), int64(1001)

	if expiryStyle(api.File{ExpiresAt: &past}, now) != color.Red {
		t.Error("expired must be red")
	}
	if expiryStyle(api.File{ExpiresAt: &future}, now) != "" || expiryStyle(api.File{}, now) != "" {
		t.Error("live and endless files have no style")
	}
}

func TestWithCodeAndExitCode(t *testing.T) {
	if withCode(ExitUsage, nil) != nil {
		t.Error("no error stays no error")
	}
	base := errors.New("boom")
	wrapped := withCode(ExitDuplicate, base)
	if exitCode(wrapped) != ExitDuplicate || !errors.Is(wrapped, base) || wrapped.Error() != "boom" {
		t.Errorf("%v / %d", wrapped, exitCode(wrapped))
	}
	if exitCode(nil) != ExitOK || exitCode(base) != ExitError {
		t.Error("exit codes of plain errors")
	}
}

func TestTerminalOrNilOnlyPassesTerminals(t *testing.T) {
	if terminalOrNil(&bytes.Buffer{}) != nil {
		t.Error("a buffer is no terminal")
	}
	f, err := os.CreateTemp(t.TempDir(), "x")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = f.Close() }()
	if terminalOrNil(f) != nil {
		t.Error("a regular file is no terminal")
	}
}

func TestCleanupSaysWhenAnotherCleanupIsRunning(t *testing.T) {
	e := newEnv(t)
	e.server.Intercept = func(r *http.Request) (int, http.Header, string) {
		if r.URL.Path == "/api/v1/cleanup" {
			return 200, nil, `{"ran":false}`
		}
		return 0, nil, ""
	}

	code, out, errs := e.run("cleanup")

	if code != 0 || errs != "" || !strings.Contains(out, "another cleanup is running") {
		t.Errorf("code %d, stdout %q, stderr %q", code, out, errs)
	}
}
