package cli

import (
	"strings"
	"testing"
)

func TestConfigGetShowsAllSettingsOnByDefault(t *testing.T) {
	e := newEnv(t)
	code, out, errs := e.run("config", "get")
	if code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	for _, want := range []string{
		"show_taglines      on\n", "show_startpage     on\n", "show_title         on\n",
		"show_linkrot_line  on\n", "show_curl_line     on\n", "show_hash_line     on\n",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestConfigSetChangesSeveralSettingsAndAcceptsShortNames(t *testing.T) {
	e := newEnv(t)
	code, out, errs := e.run("config", "set", "taglines", "off", "curl-line", "no", "show_hash_line", "FALSE")
	if code != 0 {
		t.Fatalf("code %d: %s", code, errs)
	}
	for _, want := range []string{"show_taglines      off\n", "show_curl_line     off\n", "show_hash_line     off\n", "show_title         on\n"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if e.server.Settings["show_taglines"] || !e.server.Settings["show_startpage"] {
		t.Errorf("server has %v", e.server.Settings)
	}
	if code, _, _ := e.run("config", "set", "taglines", "on"); code != 0 || !e.server.Settings["show_taglines"] {
		t.Errorf("switching back on failed: %v", e.server.Settings)
	}
}

func TestConfigSetRefusesBadInputWithoutAskingTheServer(t *testing.T) {
	e := newEnv(t)
	for _, args := range [][]string{
		{"config", "set"},
		{"config", "set", "taglines"},
		{"config", "set", "taglines", "maybe"},
		{"config", "set", "colour", "on"},
		{"config", "set", "taglines", "on", "title"},
	} {
		if code, _, _ := e.run(args...); code != ExitUsage {
			t.Errorf("%v: code %d, want %d", args, code, ExitUsage)
		}
	}
	if e.server.Settings != nil || e.server.HealthCalls != 0 {
		t.Errorf("the server was asked: %v, %d", e.server.Settings, e.server.HealthCalls)
	}
}

func TestConfigGetJSON(t *testing.T) {
	e := newEnv(t)
	code, out, errs := e.run("config", "get", "--json")
	if code != 0 || !strings.Contains(out, `"show_curl_line": true`) {
		t.Fatalf("code %d: %s\n%s", code, out, errs)
	}
}
