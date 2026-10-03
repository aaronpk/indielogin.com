// The IndieLogin SSH sign-in server.
//
// People signing in with an SSH key can run `ssh <their domain>@<this host>`
// instead of pasting a signature. This server checks with indielogin.com
// which keys the domain's website lists, lets SSH public-key authentication
// prove the person holds one of them, shows what they are signing in to, and
// tells indielogin.com when they press Enter. Their browser, waiting on
// indielogin.com, then finishes the sign-in.
//
// It does nothing else: no shell, no commands, no forwarding, no files. It
// runs on a machine of its own and reaches indielogin.com only through its
// private API (see app/SSHServerApi.php).
package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/pem"
	"errors"
	"fmt"
	"log"
	"net"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/ssh"
)

const (
	authTimeout    = 30 * time.Second
	sessionTimeout = 2 * time.Minute
	maxAuthTries   = 20
	maxConnections = 200
	maxPerAddress  = 10
)

func main() {
	listen := env("SSH_SERVER_LISTEN", ":22")
	hostKeyPath := env("SSH_SERVER_HOST_KEY", "/var/lib/indielogin-ssh/host_ed25519_key")
	baseURL := env("INDIELOGIN_URL", "")
	apiKey := env("SSH_SERVER_API_KEY", "")

	if baseURL == "" || apiKey == "" {
		log.Fatal("INDIELOGIN_URL and SSH_SERVER_API_KEY must both be set")
	}

	hostKey, err := loadOrCreateHostKey(hostKeyPath)
	if err != nil {
		log.Fatalf("host key: %v", err)
	}
	log.Printf("host key %s %s", hostKey.PublicKey().Type(), ssh.FingerprintSHA256(hostKey.PublicKey()))

	listener, err := net.Listen("tcp", listen)
	if err != nil {
		log.Fatalf("listen: %v", err)
	}
	log.Printf("listening on %s", listener.Addr())

	srv := &Server{API: NewAPI(baseURL, apiKey), HostKey: hostKey}
	log.Fatal(srv.Serve(listener))
}

func env(name, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(name)); value != "" {
		return value
	}
	return fallback
}

// Server accepts SSH connections, within limits on how many there can be in
// all and from any one address.
type Server struct {
	API     *API
	HostKey ssh.Signer

	mu        sync.Mutex
	total     int
	byAddress map[string]int
}

func (s *Server) Serve(listener net.Listener) error {
	for {
		conn, err := listener.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) {
				return err
			}
			log.Printf("accept: %v", err)
			time.Sleep(100 * time.Millisecond)
			continue
		}

		address := remoteHost(conn)
		if !s.admit(address) {
			log.Printf("%s refused: too many connections", address)
			conn.Close()
			continue
		}

		go func() {
			defer s.release(address)
			defer conn.Close()
			s.handle(conn, address)
		}()
	}
}

func (s *Server) admit(address string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.byAddress == nil {
		s.byAddress = map[string]int{}
	}
	if s.total >= maxConnections || s.byAddress[address] >= maxPerAddress {
		return false
	}
	s.total++
	s.byAddress[address]++
	return true
}

func (s *Server) release(address string) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.total--
	if s.byAddress[address]--; s.byAddress[address] <= 0 {
		delete(s.byAddress, address)
	}
}

// What one connection's authentication learned, kept for the message shown
// if it fails and for the session if it succeeds.
type authState struct {
	mu        sync.Mutex
	offered   []string // fingerprints of the keys the client offered
	reason    string   // why the last key was refused
	keysURLs  []string
	apiFailed bool
	explained bool
}

func (s *Server) handle(conn net.Conn, address string) {
	conn.SetDeadline(time.Now().Add(authTimeout))

	state := &authState{}
	config := s.config(state)

	sconn, channels, requests, err := ssh.NewServerConn(conn, config)
	if err != nil {
		state.mu.Lock()
		log.Printf("%s auth failed: %v (offered %d keys, reason %q)", address, err, len(state.offered), state.reason)
		state.mu.Unlock()
		return
	}
	defer sconn.Close()

	// Authenticated. The whole session gets a little longer than the
	// handshake did, enough to read and confirm, and no more.
	conn.SetDeadline(time.Now().Add(sessionTimeout))

	// The key that actually authenticated, which since x/crypto 0.31 is
	// guaranteed to be the one these permissions were returned for, not
	// merely the last one the client asked about
	key := sconn.Permissions.Extensions["key"]
	fingerprint := sconn.Permissions.Extensions["fingerprint"]
	log.Printf("%s authenticated as %q with %s", address, logUser(sconn.User()), fingerprint)

	// Port forwarding and every other global request is refused
	go ssh.DiscardRequests(requests)

	for newChannel := range channels {
		if newChannel.ChannelType() != "session" {
			newChannel.Reject(ssh.Prohibited, "only interactive sessions are allowed here")
			continue
		}
		channel, channelRequests, err := newChannel.Accept()
		if err != nil {
			continue
		}
		session := &Session{
			API:     s.API,
			Channel: channel,
			User:    sconn.User(),
			Key:     key,
			Address: address,
		}
		go session.Run(channelRequests)
	}
}

func (s *Server) config(state *authState) *ssh.ServerConfig {
	config := &ssh.ServerConfig{
		ServerVersion: "SSH-2.0-IndieLogin",
		MaxAuthTries:  maxAuthTries,

		PublicKeyCallback: func(meta ssh.ConnMetadata, key ssh.PublicKey) (*ssh.Permissions, error) {
			line := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(key)))
			fingerprint := ssh.FingerprintSHA256(key)

			state.mu.Lock()
			state.offered = append(state.offered, fingerprint)
			state.mu.Unlock()

			result, err := s.API.Check(meta.User(), line, "")
			if err != nil {
				log.Printf("check failed: %v", err)
				state.mu.Lock()
				state.apiFailed = true
				state.mu.Unlock()
				return nil, errors.New("indielogin.com could not be reached")
			}

			if !result.OK {
				state.mu.Lock()
				state.reason = result.Reason
				if len(result.KeysURLs) > 0 {
					state.keysURLs = result.KeysURLs
				}
				state.mu.Unlock()
				return nil, fmt.Errorf("refused: %s", result.Reason)
			}

			return &ssh.Permissions{Extensions: map[string]string{
				"key":         line,
				"fingerprint": fingerprint,
			}}, nil
		},

		// Only reached once no key was accepted. It asks nothing, and is only
		// there to tell the person why, which a plain "Permission denied"
		// does not.
		KeyboardInteractiveCallback: func(meta ssh.ConnMetadata, challenge ssh.KeyboardInteractiveChallenge) (*ssh.Permissions, error) {
			state.mu.Lock()
			message := ""
			if !state.explained {
				message = explainRefusal(meta.User(), state)
				state.explained = true
			}
			state.mu.Unlock()

			if message != "" {
				challenge("IndieLogin.com", message, nil, nil)
			}
			return nil, errors.New("no key was accepted")
		},
	}
	config.AddHostKey(s.HostKey)
	return config
}

func explainRefusal(user string, state *authState) string {
	switch {
	case state.apiFailed:
		return "IndieLogin.com could not be reached just now. Try again in a moment.\n"
	case len(state.offered) == 0:
		return "Your ssh client did not offer any keys. Run it with -i and the key your website lists.\n"
	case state.reason == "none_waiting":
		return fmt.Sprintf("No sign-in is waiting for %s.\nStart signing in on the website first, then run the command it shows.\n", user)
	case state.reason == "unlisted":
		message := "None of the keys your ssh client offered are listed"
		if len(state.keysURLs) > 0 {
			message += " at " + strings.Join(state.keysURLs, ", ")
		}
		message += ":\n"
		for _, fingerprint := range state.offered {
			message += "  " + fingerprint + "\n"
		}
		return message + "Add one of them there, or run ssh with -i and the key that is listed.\n"
	case state.reason == "unsupported_key":
		return "That kind of SSH key is not supported. Use an Ed25519, ECDSA or RSA key.\n"
	}
	return "Your key was not accepted.\n"
}

// loadOrCreateHostKey reads the server's host key, or makes a new Ed25519
// one the first time, so that its fingerprint stays the same across
// restarts. The public half is written next to it for ssh-keygen -lf.
func loadOrCreateHostKey(path string) (ssh.Signer, error) {
	if data, err := os.ReadFile(path); err == nil {
		return ssh.ParsePrivateKey(data)
	} else if !errors.Is(err, os.ErrNotExist) {
		return nil, err
	}

	_, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, err
	}
	block, err := ssh.MarshalPrivateKey(private, "IndieLogin SSH sign-in server")
	if err != nil {
		return nil, err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return nil, err
	}
	if err := os.WriteFile(path, pem.EncodeToMemory(block), 0600); err != nil {
		return nil, err
	}

	signer, err := ssh.NewSignerFromKey(private)
	if err != nil {
		return nil, err
	}
	if err := os.WriteFile(path+".pub", ssh.MarshalAuthorizedKey(signer.PublicKey()), 0644); err != nil {
		return nil, err
	}
	log.Printf("created a new host key at %s", path)
	return signer, nil
}

func remoteHost(conn net.Conn) string {
	host, _, err := net.SplitHostPort(conn.RemoteAddr().String())
	if err != nil {
		return conn.RemoteAddr().String()
	}
	return host
}

// A connect code is only logged in part, since it is good for a few
// minutes; a domain is logged as it is.
func logUser(user string) string {
	if strings.Contains(user, ".") || len(user) < 4 {
		return user
	}
	return user[:4] + "…"
}
