package main

import (
	"context"
	"crypto/sha256"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"tailscale.com/client/tailscale/apitype"
	"tailscale.com/tailcfg"
)

func TestDisabled(t *testing.T) {
	if err := run(nil, func(_, _, _ string) error {
		t.Fatal("disabled executable attempted startup")
		return nil
	}); err != nil {
		t.Fatal(err)
	}
}

type noRead struct{ t *testing.T }

func (r noRead) Read([]byte) (int, error) {
	r.t.Fatal("unauthorized request body read")
	return 0, nil
}

func TestUploadEntry(t *testing.T) {
	for _, tc := range []struct {
		name string
		tags []string
		cap, socket, fail bool
		status int
	}{
		{"accepted", []string{senderTag}, true, true, false, 201},
		{"wrong-tag", []string{"tag:other"}, true, true, false, 403},
		{"absent-tag", nil, true, true, false, 403},
		{"additional-tag", []string{senderTag, "tag:other"}, true, true, false, 403},
		{"missing-capability", []string{senderTag}, false, true, false, 403},
		{"auth-error", []string{senderTag}, true, true, true, 403},
		{"spoofed-headers-no-socket", []string{senderTag}, true, false, false, 403},
	} {
		t.Run(tc.name, func(t *testing.T) {
			dir := t.TempDir()
			if err := os.Chmod(dir, 0700); err != nil { t.Fatal(err) }
			data := "synthetic"
			inbox := server(dir, fmt.Sprintf("%x", sha256.Sum256([]byte(data))),
				func(_ context.Context, addr string) (*apitype.WhoIsResponse, error) {
					if addr != "pipe" { t.Fatalf("wrong identity input: %q", addr) }
					if tc.fail { return nil, errors.New("lookup failed") }
					caps := tailcfg.PeerCapMap{}
					if tc.cap { caps[capability] = nil } else { caps["https://invalid.example/cap"] = nil }
					return &apitype.WhoIsResponse{Node: &tailcfg.Node{Tags: tc.tags}, CapMap: caps}, nil
				})
			var reader io.Reader = strings.NewReader(data)
			if tc.status != 201 {
				reader = noRead{t}
			}
			req := httptest.NewRequest(http.MethodPut, "/v1/image", reader)
			req.ContentLength = int64(len(data))
			req.RemoteAddr = "pipe"
			req.Header.Set("X-Forwarded-For", "100.64.0.1")
			req.Header.Set("Tailscale-User-Login", senderTag)
			if tc.socket {
				c, other := net.Pipe()
				defer c.Close()
				defer other.Close()
				req = req.WithContext(inbox.ConnContext(req.Context(), c))
			}
			out := httptest.NewRecorder()
			inbox.Handler.ServeHTTP(out, req)
			if out.Code != tc.status { t.Fatalf("got %d, want %d", out.Code, tc.status) }
			sealed, err := os.ReadFile(filepath.Join(dir, "sealed.oci.tar"))
			if tc.status == 201 && (err != nil || string(sealed) != data) { t.Fatalf("seal failed: %v", err) }
			if tc.status != 201 && !errors.Is(err, os.ErrNotExist) { t.Fatal("unauthorized slot created") }
		})
	}
}
