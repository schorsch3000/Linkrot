// Package testutil provides an in-memory Linkrot server for tests.
package testutil

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync"
	"testing"

	"github.com/schorsch3000/Linkrot/internal/api"
)

// FakeServer implements the documented API (docs/API.md) in memory.
type FakeServer struct {
	*httptest.Server
	Token     string
	ChunkSize int64

	// Intercept runs before every request. Returning a non-zero status answers the request with it
	// (body is sent as is) and skips the normal handling.
	Intercept func(r *http.Request) (status int, header http.Header, body string)

	mu    sync.Mutex
	files map[string]*fakeFile
	order []string
	seq   int
	// Chunks counts accepted chunk requests.
	Chunks int
}

type fakeFile struct {
	api.File
	RmAfter string `json:"-"`
	data    []byte
}

// NewFakeServer starts a server; it is closed when the test ends.
func NewFakeServer(t *testing.T) *FakeServer {
	t.Helper()
	s := &FakeServer{Token: "lr_testtoken", ChunkSize: 10, files: map[string]*fakeFile{}}
	s.Server = httptest.NewServer(http.HandlerFunc(s.serve))
	t.Cleanup(s.Close)
	return s
}

// Content returns the received bytes of a file.
func (s *FakeServer) Content(id string) []byte {
	s.mu.Lock()
	defer s.mu.Unlock()
	if f := s.files[id]; f != nil {
		return append([]byte(nil), f.data...)
	}
	return nil
}

// Add stores a ready file and returns its id.
func (s *FakeServer) Add(name string, content []byte) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.add(name, content).ID
}

func (s *FakeServer) add(name string, content []byte) *fakeFile {
	sum := sha256.Sum256(content)
	f := s.newFile(name, int64(len(content)), hex.EncodeToString(sum[:]))
	f.data = append([]byte(nil), content...)
	s.finish(f)
	return f
}

func (s *FakeServer) newFile(name string, size int64, sha string) *fakeFile {
	s.seq++
	id := fmt.Sprintf("tst%d%012d", s.seq%10, s.seq)
	f := &fakeFile{File: api.File{ID: id, Name: name, Size: size, SHA256: sha, Status: api.StatusUploading, CreatedAt: 1000 + int64(s.seq)}}
	s.files[id] = f
	s.order = append(s.order, id)
	return f
}

func (s *FakeServer) finish(f *fakeFile) {
	f.Status = api.StatusReady
	f.Offset = f.Size
	completed, expires := int64(2000), int64(2000+7*86400)
	f.CompletedAt, f.ExpiresAt = &completed, &expires
	f.URL = s.URL + "/file/" + f.ID + "/" + f.Name
}

func (s *FakeServer) serve(w http.ResponseWriter, r *http.Request) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.Intercept != nil {
		if status, header, body := s.Intercept(r); status != 0 {
			for k, v := range header {
				w.Header()[k] = v
			}
			w.WriteHeader(status)
			_, _ = io.WriteString(w, body)
			return
		}
	}
	path := r.URL.Path
	if strings.HasPrefix(path, "/dl/") {
		s.download(w, r)
		return
	}
	if r.Header.Get("Authorization") != "Bearer "+s.Token {
		writeError(w, 401, api.CodeUnauthorized, "missing or invalid token", nil)
		return
	}
	switch {
	case path == "/api/v1/files" && r.Method == http.MethodPost:
		s.create(w, r)
	case path == "/api/v1/files" && r.Method == http.MethodGet:
		list := []api.File{}
		for i := len(s.order) - 1; i >= 0; i-- {
			if f := s.files[s.order[i]]; f != nil {
				list = append(list, f.File)
			}
		}
		writeJSON(w, 200, map[string]any{"files": list})
	case path == "/api/v1/cleanup" && r.Method == http.MethodPost:
		writeJSON(w, 200, map[string]any{"ran": true, "rows_deleted": 0, "files_deleted": 0})
	case strings.HasPrefix(path, "/api/v1/files/"):
		s.item(w, r, strings.TrimPrefix(path, "/api/v1/files/"))
	default:
		writeError(w, 404, api.CodeNotFound, "not found", nil)
	}
}

func (s *FakeServer) create(w http.ResponseWriter, r *http.Request) {
	var req api.CreateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil || req.Name == "" || len(req.SHA256) != 64 {
		writeError(w, 400, "invalid_request", "bad create request", nil)
		return
	}
	for _, id := range s.order {
		f := s.files[id]
		if f == nil || f.SHA256 != req.SHA256 {
			continue
		}
		if f.Status == api.StatusReady {
			writeError(w, 409, api.CodeDuplicate, "this file already exists", map[string]any{"id": f.ID, "url": f.URL})
			return
		}
		f.Name = req.Name
		out := f.File
		out.ChunkSize = s.ChunkSize
		writeJSON(w, 200, out)
		return
	}
	f := s.newFile(req.Name, req.Size, req.SHA256)
	if req.Size == 0 {
		s.finish(f)
	}
	out := f.File
	out.ChunkSize = s.ChunkSize
	writeJSON(w, 201, out)
}

func (s *FakeServer) item(w http.ResponseWriter, r *http.Request, rest string) {
	id, suffix, _ := strings.Cut(rest, "/")
	f := s.files[id]
	if f == nil {
		writeError(w, 404, api.CodeNotFound, "not found", nil)
		return
	}
	switch {
	case suffix == "content" && r.Method == http.MethodPatch:
		s.chunk(w, r, f)
	case suffix == "" && r.Method == http.MethodGet:
		writeJSON(w, 200, f.File)
	case suffix == "" && r.Method == http.MethodDelete:
		delete(s.files, id)
		w.WriteHeader(204)
	case suffix == "" && r.Method == http.MethodPatch:
		var body map[string]any
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil || len(body) == 0 {
			writeError(w, 400, "invalid_request", "bad patch", nil)
			return
		}
		if v, ok := body["name"].(string); ok {
			f.Name = v
			if f.Status == api.StatusReady {
				f.URL = s.URL + "/file/" + f.ID + "/" + f.Name
			}
		}
		if v, ok := body["rm_after"].(string); ok {
			f.RmAfter = v
		}
		if v, ok := body["uses"]; ok {
			if n, isNum := v.(float64); isNum {
				u := int64(n)
				f.UsesLeft = &u
			} else {
				f.UsesLeft = nil
			}
		}
		writeJSON(w, 200, f.File)
	default:
		writeError(w, 405, "invalid_request", "method not allowed", nil)
	}
}

func (s *FakeServer) chunk(w http.ResponseWriter, r *http.Request, f *fakeFile) {
	if f.Status != api.StatusUploading {
		writeError(w, 404, api.CodeNotFound, "not found", nil)
		return
	}
	offset, err := strconv.ParseInt(r.Header.Get("Upload-Offset"), 10, 64)
	if err != nil || r.Header.Get("Content-Type") != "application/offset+octet-stream" {
		writeError(w, 400, "invalid_request", "bad headers", nil)
		return
	}
	if offset != f.Offset {
		writeError(w, 409, api.CodeOffsetMismatch, "Upload-Offset does not match the server", map[string]any{"offset": f.Offset})
		return
	}
	body, err := io.ReadAll(r.Body)
	want := min(s.ChunkSize, f.Size-offset)
	if err != nil || int64(len(body)) != want+api.DigestLength {
		writeError(w, 400, "invalid_request", "wrong chunk length", nil)
		return
	}
	chunk, digest := body[:want], body[want:]
	sum := sha256.Sum256(chunk)
	if !bytes.Equal(sum[:], digest) {
		writeError(w, 422, api.CodeChunkHashMismatch, "chunk does not match its sha256", map[string]any{"offset": f.Offset})
		return
	}
	s.Chunks++
	f.data = append(f.data, chunk...)
	f.Offset += want
	if f.Offset < f.Size {
		writeJSON(w, 200, map[string]any{"offset": f.Offset})
		return
	}
	whole := sha256.Sum256(f.data)
	if hex.EncodeToString(whole[:]) != f.SHA256 {
		delete(s.files, f.ID)
		writeError(w, 422, api.CodeFileHashMismatch, "file does not match the declared sha256", nil)
		return
	}
	s.finish(f)
	writeJSON(w, 200, f.File)
}

func (s *FakeServer) download(w http.ResponseWriter, r *http.Request) {
	parts := strings.SplitN(strings.TrimPrefix(r.URL.Path, "/dl/"), "/", 2)
	f := s.files[parts[0]]
	if f == nil || f.Status != api.StatusReady {
		http.Error(w, "Not found", 404)
		return
	}
	data := f.data
	if rng := r.Header.Get("Range"); strings.HasPrefix(rng, "bytes=") && strings.HasSuffix(rng, "-") {
		from, err := strconv.ParseInt(strings.TrimSuffix(strings.TrimPrefix(rng, "bytes="), "-"), 10, 64)
		if err == nil && from > 0 && from < int64(len(data)) {
			w.Header().Set("Content-Range", fmt.Sprintf("bytes %d-%d/%d", from, len(data)-1, len(data)))
			w.Header().Set("Content-Length", strconv.Itoa(len(data)-int(from)))
			w.WriteHeader(206)
			_, _ = w.Write(data[from:])
			return
		}
	}
	w.Header().Set("Content-Length", strconv.Itoa(len(data)))
	w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=%q", f.Name))
	_, _ = w.Write(data)
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func writeError(w http.ResponseWriter, status int, code, msg string, extra map[string]any) {
	e := map[string]any{"code": code, "message": msg}
	for k, v := range extra {
		e[k] = v
	}
	writeJSON(w, status, map[string]any{"error": e})
}
