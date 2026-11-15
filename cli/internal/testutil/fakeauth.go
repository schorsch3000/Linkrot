package testutil

import (
	"encoding/json"
	"net/http"
	"sort"
	"strings"

	"github.com/schorsch3000/Linkrot/internal/api"
)

// SessionToken is the management session the fake server hands out for the right code.
const SessionToken = "lm_testsession"

// SelfService is the state behind the mailed codes of the fake server: the address of the host, the code a mail would
// carry, who got one, and the tokens made through a session. Like the real server it answers alike for every address.
type SelfService struct {
	Email string // the address of the host; empty: the host has none
	Code  string // default ABCD-EFGH
	// Mailed counts the addresses a code was "sent" to (only the right one is).
	Mailed int
	// Tokens are the tokens of the host by name, with their secret; the created ones are added.
	Tokens map[string]string
	// Starts counts requests for a code.
	Starts int
	// Expired makes the session end: the token routes answer 401.
	Expired bool
}

func (s *FakeServer) selfService(w http.ResponseWriter, r *http.Request, path string) {
	ss := &s.Auth
	if ss.Code == "" {
		ss.Code = "ABCD-EFGH"
	}
	if ss.Tokens == nil {
		ss.Tokens = map[string]string{}
	}
	switch {
	case path == "/api/v1/auth/start" && r.Method == http.MethodPost:
		var in struct {
			Email string `json:"email"`
		}
		_ = json.NewDecoder(r.Body).Decode(&in)
		ss.Starts++
		if ss.Email != "" && strings.EqualFold(in.Email, ss.Email) {
			ss.Mailed++
		}
		writeJSON(w, 202, map[string]any{"challenge": "challenge-1", "expires_in": 600})
	case path == "/api/v1/auth/verify" && r.Method == http.MethodPost:
		var in struct{ Challenge, Code string }
		_ = json.NewDecoder(r.Body).Decode(&in)
		typed := strings.ToUpper(strings.NewReplacer(" ", "", "-", "").Replace(in.Code))
		if ss.Mailed == 0 || in.Challenge != "challenge-1" || typed != strings.ReplaceAll(ss.Code, "-", "") {
			writeError(w, 401, api.CodeInvalidCode, "wrong or expired code", nil)
			return
		}
		ss.Mailed = 0
		writeJSON(w, 200, map[string]any{"session": SessionToken, "expires_in": 600})
	case strings.HasPrefix(path, "/api/v1/tokens"):
		if ss.Expired || r.Header.Get("Authorization") != "Bearer "+SessionToken {
			writeError(w, 401, api.CodeUnauthorized, "missing or invalid token", nil)
			return
		}
		s.tokens(w, r, strings.TrimPrefix(strings.TrimPrefix(path, "/api/v1/tokens"), "/"))
	default:
		writeError(w, 404, api.CodeNotFound, "not found", nil)
	}
}

func (s *FakeServer) tokens(w http.ResponseWriter, r *http.Request, name string) {
	ss := &s.Auth
	switch {
	case name == "" && r.Method == http.MethodGet:
		names := make([]string, 0, len(ss.Tokens))
		for n := range ss.Tokens {
			names = append(names, n)
		}
		sort.Strings(names)
		list := []map[string]any{}
		for _, n := range names {
			list = append(list, map[string]any{"name": n, "last_used_at": nil})
		}
		writeJSON(w, 200, map[string]any{"tokens": list})
	case name == "" && r.Method == http.MethodPost:
		var in struct{ Name string }
		_ = json.NewDecoder(r.Body).Decode(&in)
		if _, taken := ss.Tokens[in.Name]; taken {
			writeError(w, 409, api.CodeDuplicate, "a token named '"+in.Name+"' already exists", nil)
			return
		}
		token := "lr_made_" + in.Name
		ss.Tokens[in.Name] = token
		writeJSON(w, 201, map[string]any{"name": in.Name, "token": token})
	case name != "" && r.Method == http.MethodDelete:
		if _, ok := ss.Tokens[name]; !ok {
			writeError(w, 404, api.CodeNotFound, "no such token", nil)
			return
		}
		delete(ss.Tokens, name)
		w.WriteHeader(204)
	default:
		writeError(w, 405, "invalid_request", "method not allowed", nil)
	}
}
