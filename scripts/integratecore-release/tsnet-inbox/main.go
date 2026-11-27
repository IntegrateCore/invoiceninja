package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"sync/atomic"
	"syscall"
	"time"

	"tailscale.com/client/tailscale/apitype"
	"tailscale.com/tsnet"
)

const senderTag = "tag:invoiceninja-staging-image-sender"
const capability = "integratecore.net/cap/oci-image-upload"
const limit = 1 << 30

type peerKey struct{}
type lookup func(context.Context, string) (*apitype.WhoIsResponse, error)

func server(inbox, expected string, whois lookup) *http.Server {
	var busy atomic.Bool
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		r.Close = true
		w.Header().Set("Connection", "close")
		if r.Method != http.MethodPut || r.URL.Path != "/v1/image" {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		addr, ok := r.Context().Value(peerKey{}).(string)
		if !ok || addr != r.RemoteAddr {
			w.WriteHeader(http.StatusForbidden)
			return
		}
		if !busy.CompareAndSwap(false, true) {
			w.WriteHeader(http.StatusTooManyRequests)
			return
		}
		defer busy.Store(false)
		ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
		defer cancel()
		peer, err := whois(ctx, addr)
		if err != nil || peer == nil || peer.Node == nil ||
			len(peer.Node.Tags) != 1 || peer.Node.Tags[0] != senderTag {
			w.WriteHeader(http.StatusForbidden)
			return
		}
		if _, allowed := peer.CapMap[capability]; !allowed {
			w.WriteHeader(http.StatusForbidden)
			return
		}
		if len(r.TransferEncoding) != 0 || r.ContentLength <= 0 || r.ContentLength > limit {
			w.WriteHeader(http.StatusRequestEntityTooLarge)
			return
		}
		w.WriteHeader(store(inbox, expected, r.Body, r.ContentLength))
	})
	return &http.Server{
		Handler: handler, MaxHeaderBytes: 4096, ReadHeaderTimeout: 3*time.Second,
		ReadTimeout: 120*time.Second, WriteTimeout: 125*time.Second, IdleTimeout: 3*time.Second,
		ConnContext: func(ctx context.Context, c net.Conn) context.Context {
			return context.WithValue(ctx, peerKey{}, c.RemoteAddr().String())
		},
	}
}

func store(inbox, expected string, body io.Reader, length int64) int {
	fd, err := syscall.Open(inbox, syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return 503
	}
	dir := os.NewFile(uintptr(fd), inbox)
	defer dir.Close()
	info, err := dir.Stat()
	if err != nil || info.Mode().Perm() != 0700 || info.Sys().(*syscall.Stat_t).Uid != uint32(os.Geteuid()) {
		return 503
	}
	if err := syscall.Flock(fd, syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		return 429
	}
	if _, err := dir.Readdirnames(1); err != io.EOF {
		return 409
	}
	// Anchor all operations to the already verified directory, not a swapped path.
	root := fmt.Sprintf("/proc/self/fd/%d", fd)
	f, err := os.CreateTemp(root, ".incoming-")
	if err != nil {
		return 503
	}
	defer os.Remove(f.Name())
	defer f.Close()
	hash := sha256.New()
	if _, err := io.CopyN(io.MultiWriter(f, hash), body, length); err != nil {
		return 400
	}
	if hex.EncodeToString(hash.Sum(nil)) != expected {
		return 422
	}
	if f.Chmod(0400) != nil || f.Sync() != nil || f.Close() != nil {
		return 503
	}
	if err := os.Link(f.Name(), filepath.Join(root, "sealed.oci.tar")); err != nil {
		if errors.Is(err, os.ErrExist) {
			return 409
		}
		return 503
	}
	return 201
}

// run is the executable entry point. The startup seam prevents registration in tests.
func run(args []string, serve func(state, inbox, expected string) error) error {
	flags := flag.NewFlagSet("tsnet-inbox", flag.ContinueOnError)
	enabled := flags.Bool("enable", false, "explicitly enable the private receiver")
	state := flags.String("state", "", "dedicated tsnet state directory")
	inbox := flags.String("inbox", "", "private quota-limited inbox")
	expected := flags.String("sha256", "", "independently approved archive SHA-256")
	if err := flags.Parse(args); err != nil {
		return err
	}
	if !*enabled {
		return nil
	}
	if os.Geteuid() == 0 || flags.NArg() != 0 || !filepath.IsAbs(*state) || !filepath.IsAbs(*inbox) ||
		filepath.Clean(*state) == filepath.Clean(*inbox) || !regexp.MustCompile("^[0-9a-f]{64}$").MatchString(*expected) {
		return errors.New("non-root, separate absolute state/inbox and approved checksum required")
	}
	return serve(*state, *inbox, *expected)
}

func serve(state, inbox, expected string) error {
	info, err := os.Lstat(state)
	if err != nil || !info.IsDir() || info.Mode().Perm() != 0700 ||
		info.Sys().(*syscall.Stat_t).Uid != uint32(os.Geteuid()) {
		return errors.New("dedicated state directory must be owned by this user and mode 0700")
	}
	node := &tsnet.Server{Dir: state, Hostname: "private-inbox"}
	defer node.Close()
	client, err := node.LocalClient()
	if err != nil {
		return err
	}
	listener, err := node.Listen("tcp", ":9443")
	if err != nil {
		return err
	}
	defer listener.Close()
	srv := server(inbox, expected, func(ctx context.Context, remote string) (*apitype.WhoIsResponse, error) {
		address, err := netip.ParseAddrPort(remote)
		if err != nil || !address.Addr().IsValid() {
			return nil, errors.New("invalid socket peer")
		}
		return client.WhoIs(ctx, remote)
	})
	return srv.Serve(listener)
}

func main() {
	if err := run(os.Args[1:], serve); err != nil {
		log.Fatal(err)
	}
}
