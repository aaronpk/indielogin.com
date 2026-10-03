package main

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"golang.org/x/crypto/ssh"
)

// stubAPI stands in for indielogin.com. listed maps a username to the key
// lines its website lists; ambiguous usernames match two sign-ins until the
// right code is given.
type stubAPI struct {
	mu        sync.Mutex
	listed    map[string][]string
	ambiguous map[string]string // username => the code that picks one
	approvals []map[string]string
}

func (a *stubAPI) handler(t *testing.T) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer test-key" {
			http.NotFound(w, r)
			return
		}
		var p map[string]string
		json.NewDecoder(r.Body).Decode(&p)

		a.mu.Lock()
		defer a.mu.Unlock()

		keys, waiting := a.listed[p["user"]]
		result := Result{}
		switch {
		case !waiting:
			result = Result{OK: false, Reason: "none_waiting"}
		case !contains(keys, p["key"]):
			result = Result{OK: false, Reason: "unlisted", KeysURLs: []string{"https://" + p["user"] + "/ssh.pub"}}
		case a.ambiguous[p["user"]] != "" && p["code"] == "":
			if r.URL.Path == "/ssh-server/approve" {
				result = Result{OK: false, Reason: "ambiguous", Matches: 2}
			} else {
				result = Result{OK: true, Matches: 2}
			}
		case a.ambiguous[p["user"]] != "" && p["code"] != a.ambiguous[p["user"]]:
			result = Result{OK: false, Reason: "bad_code"}
		default:
			result = Result{OK: true, Matches: 1, ClientID: "https://app.example/", Me: "https://" + p["user"] + "/",
				Started: time.Now().Add(-20 * time.Second).Unix(), IP: "203.0.113.7", Fingerprint: "SHA256:test", Type: "ssh-ed25519"}
			if r.URL.Path == "/ssh-server/approve" {
				a.approvals = append(a.approvals, p)
			}
		}
		json.NewEncoder(w).Encode(result)
	})
}

func contains(list []string, item string) bool {
	for _, x := range list {
		if x == item {
			return true
		}
	}
	return false
}

func newSigner(t *testing.T) ssh.Signer {
	_, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	signer, err := ssh.NewSignerFromKey(private)
	if err != nil {
		t.Fatal(err)
	}
	return signer
}

func keyLine(s ssh.Signer) string {
	return strings.TrimSpace(string(ssh.MarshalAuthorizedKey(s.PublicKey())))
}

// startServer runs the real server against the stub, on a free local port.
func startServer(t *testing.T, api *stubAPI) string {
	stub := httptest.NewServer(api.handler(t))
	t.Cleanup(stub.Close)

	srv := &Server{API: NewAPI(stub.URL, "test-key"), HostKey: newSigner(t)}
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { listener.Close() })
	go srv.Serve(listener)
	return listener.Addr().String()
}

func dial(t *testing.T, addr, user string, signers []ssh.Signer, instructions *[]string) (*ssh.Client, error) {
	auth := []ssh.AuthMethod{ssh.PublicKeys(signers...)}
	if instructions != nil {
		auth = append(auth, ssh.KeyboardInteractive(func(name, instruction string, questions []string, echos []bool) ([]string, error) {
			*instructions = append(*instructions, instruction)
			return nil, nil
		}))
	}
	return ssh.Dial("tcp", addr, &ssh.ClientConfig{
		User:            user,
		Auth:            auth,
		HostKeyCallback: ssh.InsecureIgnoreHostKey(),
		Timeout:         5 * time.Second,
	})
}

// interact opens a terminal session, types input a piece at a time as
// output arrives, and returns everything shown and the exit status.
func interact(t *testing.T, client *ssh.Client, steps []struct{ waitFor, send string }) (string, int) {
	session, err := client.NewSession()
	if err != nil {
		t.Fatal(err)
	}
	defer session.Close()

	if err := session.RequestPty("xterm", 24, 80, ssh.TerminalModes{}); err != nil {
		t.Fatal(err)
	}
	stdin, _ := session.StdinPipe()
	stdout, _ := session.StdoutPipe()
	if err := session.Shell(); err != nil {
		t.Fatal(err)
	}

	var out bytes.Buffer
	buf := make([]byte, 4096)
	for _, step := range steps {
		deadline := time.Now().Add(5 * time.Second)
		for !strings.Contains(out.String(), step.waitFor) {
			if time.Now().After(deadline) {
				t.Fatalf("never saw %q in:\n%s", step.waitFor, out.String())
			}
			n, err := stdout.Read(buf)
			out.Write(buf[:n])
			if err != nil {
				break
			}
		}
		io.WriteString(stdin, step.send)
	}
	rest, _ := io.ReadAll(stdout)
	out.Write(rest)

	status := 0
	if err := session.Wait(); err != nil {
		if exit, ok := err.(*ssh.ExitError); ok {
			status = exit.ExitStatus()
		} else {
			t.Fatalf("session: %v", err)
		}
	}
	return out.String(), status
}

func TestConfirm(t *testing.T) {
	key := newSigner(t)
	api := &stubAPI{listed: map[string][]string{"example.com": {keyLine(key)}}}
	addr := startServer(t, api)

	client, err := dial(t, addr, "example.com", []ssh.Signer{key}, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	out, status := interact(t, client, []struct{ waitFor, send string }{{"Press Enter", "\r"}})

	for _, want := range []string{"Sign in to https://app.example/", "as https://example.com/", "from 203.0.113.7", "Confirmed"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if status != 0 {
		t.Errorf("exit status %d, want 0", status)
	}
	if len(api.approvals) != 1 || api.approvals[0]["key"] != keyLine(key) || api.approvals[0]["user"] != "example.com" {
		t.Errorf("approvals = %v", api.approvals)
	}
}

func TestCancel(t *testing.T) {
	key := newSigner(t)
	api := &stubAPI{listed: map[string][]string{"example.com": {keyLine(key)}}}
	addr := startServer(t, api)

	client, err := dial(t, addr, "example.com", []ssh.Signer{key}, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	out, status := interact(t, client, []struct{ waitFor, send string }{{"Press Enter", "\x03"}})
	if !strings.Contains(out, "Cancelled") || status != 1 {
		t.Errorf("status %d, output:\n%s", status, out)
	}
	if len(api.approvals) != 0 {
		t.Errorf("approved after Ctrl-C: %v", api.approvals)
	}
}

func TestClientMovesOnToTheListedKey(t *testing.T) {
	unlisted, listed := newSigner(t), newSigner(t)
	api := &stubAPI{listed: map[string][]string{"example.com": {keyLine(listed)}}}
	addr := startServer(t, api)

	client, err := dial(t, addr, "example.com", []ssh.Signer{unlisted, listed}, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	interact(t, client, []struct{ waitFor, send string }{{"Press Enter", "\r"}})
	if len(api.approvals) != 1 || api.approvals[0]["key"] != keyLine(listed) {
		t.Errorf("approved with the wrong key: %v", api.approvals)
	}
}

func TestUnlistedKeyIsRefusedAndExplained(t *testing.T) {
	unlisted, listed := newSigner(t), newSigner(t)
	api := &stubAPI{listed: map[string][]string{"example.com": {keyLine(listed)}}}
	addr := startServer(t, api)

	var instructions []string
	if _, err := dial(t, addr, "example.com", []ssh.Signer{unlisted}, &instructions); err == nil {
		t.Fatal("an unlisted key was let in")
	}
	text := strings.Join(instructions, "")
	if !strings.Contains(text, "None of the keys") || !strings.Contains(text, ssh.FingerprintSHA256(unlisted.PublicKey())) || !strings.Contains(text, "https://example.com/ssh.pub") {
		t.Errorf("explanation was:\n%s", text)
	}
}

func TestNothingWaiting(t *testing.T) {
	key := newSigner(t)
	addr := startServer(t, &stubAPI{listed: map[string][]string{}})

	var instructions []string
	if _, err := dial(t, addr, "nobody.example", []ssh.Signer{key}, &instructions); err == nil {
		t.Fatal("let in with nothing waiting")
	}
	if text := strings.Join(instructions, ""); !strings.Contains(text, "No sign-in is waiting for nobody.example") {
		t.Errorf("explanation was:\n%s", text)
	}
}

func TestAmbiguousDomainAsksForTheCode(t *testing.T) {
	key := newSigner(t)
	api := &stubAPI{
		listed:    map[string][]string{"example.com": {keyLine(key)}},
		ambiguous: map[string]string{"example.com": "k7f2-9qxm"},
	}
	addr := startServer(t, api)

	client, err := dial(t, addr, "example.com", []ssh.Signer{key}, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	out, status := interact(t, client, []struct{ waitFor, send string }{
		{"Enter the code", "wrong-code\r"},
		{"Try again", "k7f2-9qxm\r"},
		{"Press Enter", "\r"},
	})
	if !strings.Contains(out, "More than one sign-in is waiting") || !strings.Contains(out, "Confirmed") || status != 0 {
		t.Errorf("status %d, output:\n%s", status, out)
	}
	if len(api.approvals) != 1 || api.approvals[0]["code"] != "k7f2-9qxm" {
		t.Errorf("approvals = %v", api.approvals)
	}
}

func TestCommandsAreNotRun(t *testing.T) {
	key := newSigner(t)
	addr := startServer(t, &stubAPI{listed: map[string][]string{"example.com": {keyLine(key)}}})

	client, err := dial(t, addr, "example.com", []ssh.Signer{key}, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	session, _ := client.NewSession()
	out, err := session.CombinedOutput("cat /etc/passwd")
	exit, ok := err.(*ssh.ExitError)
	if !ok || exit.ExitStatus() != 2 || !strings.Contains(string(out), "runs no commands") || strings.Contains(string(out), "root:") {
		t.Errorf("err %v, output:\n%s", err, out)
	}

	sftp, _ := client.NewSession()
	if err := sftp.RequestSubsystem("sftp"); err == nil {
		t.Error("the sftp subsystem was allowed")
	}
}

func TestForwardingIsRefused(t *testing.T) {
	key := newSigner(t)
	addr := startServer(t, &stubAPI{listed: map[string][]string{"example.com": {keyLine(key)}}})

	client, err := dial(t, addr, "example.com", []ssh.Signer{key}, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	if l, err := client.Listen("tcp", "127.0.0.1:0"); err == nil {
		l.Close()
		t.Error("remote port forwarding (ssh -R) was allowed")
	}
	if c, err := client.Dial("tcp", addr); err == nil {
		c.Close()
		t.Error("local port forwarding (ssh -L) was allowed")
	}
}
