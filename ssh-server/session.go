package main

import (
	"errors"
	"fmt"
	"io"
	"log"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/ssh"
)

// Session is one SSH session: the prompt asking someone to confirm.
type Session struct {
	API     *API
	Channel ssh.Channel
	User    string // what they ssh'd in as: a domain or a connect code
	Key     string // the key that authenticated, in authorized_keys form
	Address string

	pty     bool
	started sync.Once
}

var errCancelled = errors.New("cancelled")

// Run answers the client's requests on this session. Only a terminal and a
// shell are allowed; a command gets an explanation; anything else, from
// subsystems like sftp to agent and X11 forwarding, is refused.
func (s *Session) Run(requests <-chan *ssh.Request) {
	for req := range requests {
		switch req.Type {
		case "pty-req":
			s.pty = true
			req.Reply(true, nil)
		case "window-change":
			req.Reply(true, nil)
		case "shell":
			req.Reply(true, nil)
			s.start(s.prompt)
		case "exec":
			req.Reply(true, nil)
			s.start(func() int {
				s.print("This server only confirms IndieLogin sign-ins, and runs no commands.\nRun ssh without a command to sign in.\n")
				return 2
			})
		default:
			req.Reply(false, nil)
		}
	}
}

func (s *Session) start(run func() int) {
	s.started.Do(func() {
		go func() {
			status := run()
			s.Channel.SendRequest("exit-status", false, ssh.Marshal(&struct{ Status uint32 }{uint32(status)}))
			s.Channel.Close()
		}()
	})
}

// prompt shows which sign-in this is and asks for Enter. When the domain
// matches more than one waiting sign-in, it first asks for the code the
// browser shows, and never picks one itself.
func (s *Session) prompt() int {
	s.print("\nIndieLogin.com\n")

	code := ""
	result, err := s.API.Check(s.User, s.Key, "")
	if err != nil {
		return s.fail("IndieLogin.com could not be reached just now. Try again in a moment.", err)
	}

	for attempt := 0; ; attempt++ {
		if !result.OK {
			return s.refused(result)
		}
		if result.Matches == 1 {
			break
		}
		if attempt >= 3 {
			s.print("\nThat code does not match a sign-in waiting for " + s.User + ".\n")
			return 1
		}

		if attempt == 0 {
			s.print(fmt.Sprintf("\nMore than one sign-in is waiting for %s.\n", s.User))
		} else {
			s.print("That is not the code for any of them. Try again.\n")
		}
		s.print("Enter the code shown in your browser: ")

		code, err = s.readLine(true)
		if err != nil {
			return s.cancelled()
		}
		result, err = s.API.Check(s.User, s.Key, code)
		if err != nil {
			return s.fail("IndieLogin.com could not be reached just now. Try again in a moment.", err)
		}
		if !result.OK && result.Reason == "bad_code" {
			result = &Result{OK: true, Matches: 2}
		}
	}

	for {
		s.print("\nSign in to " + result.ClientID + "\n")
		s.print("as " + result.Me + "\n")
		s.print(startedLine(result) + "\n")
		s.print("with your key " + result.Fingerprint + "\n\n")
		s.print("Press Enter to confirm, or Ctrl-C to cancel. ")

		if _, err := s.readLine(false); err != nil {
			return s.cancelled()
		}

		approved, err := s.API.Approve(s.User, s.Key, code)
		if err != nil {
			return s.fail("IndieLogin.com could not be reached just now. Try again in a moment.", err)
		}
		if approved.OK {
			log.Printf("%s confirmed %s for %s with %s", s.Address, approved.Me, approved.ClientID, approved.Fingerprint)
			s.print("\nConfirmed. Go back to your browser to finish signing in.\n")
			return 0
		}

		// Another sign-in for the same domain appeared since the check: which
		// one is meant has to come from the code now
		if approved.Reason == "ambiguous" && code == "" {
			s.print(fmt.Sprintf("\nAnother sign-in for %s has just started. Enter the code shown in your browser: ", s.User))
			if code, err = s.readLine(true); err != nil {
				return s.cancelled()
			}
			if result, err = s.API.Check(s.User, s.Key, code); err != nil {
				return s.fail("IndieLogin.com could not be reached just now. Try again in a moment.", err)
			}
			if !result.OK {
				return s.refused(result)
			}
			continue
		}
		return s.refused(approved)
	}
}

func startedLine(r *Result) string {
	line := "started "
	if r.Started > 0 {
		line += ago(time.Since(time.Unix(r.Started, 0)))
	} else {
		line += "just now"
	}
	if r.IP != "" {
		line += " from " + r.IP
	}
	return line
}

func ago(d time.Duration) string {
	switch seconds := int(d.Seconds()); {
	case seconds < 5:
		return "just now"
	case seconds < 90:
		return fmt.Sprintf("%d seconds ago", seconds)
	default:
		return fmt.Sprintf("%d minutes ago", (seconds+30)/60)
	}
}

func (s *Session) refused(r *Result) int {
	switch r.Reason {
	case "none_waiting":
		s.print("\nThis sign-in is no longer waiting. It may have expired, or been finished another way.\nStart again on the website.\n")
	case "unlisted":
		s.print("\nYour key is no longer listed by the website.\n")
	case "bad_code":
		s.print("\nThat code does not match a sign-in waiting for " + s.User + ".\n")
	default:
		s.print("\nThe sign-in could not be confirmed.\n")
	}
	return 1
}

func (s *Session) cancelled() int {
	s.print("\nCancelled. Nothing was confirmed.\n")
	return 1
}

func (s *Session) fail(message string, err error) int {
	log.Printf("%s: %v", s.Address, err)
	s.print("\n" + message + "\n")
	return 1
}

// print writes to the client, with the line endings a terminal needs when
// one was asked for.
func (s *Session) print(text string) {
	if s.pty {
		text = strings.ReplaceAll(text, "\n", "\r\n")
	}
	io.WriteString(s.Channel, text)
}

// readLine reads up to Enter. With a terminal, the client sends every key as
// it is pressed and shows nothing itself, so typed characters are echoed
// here (when echo is set) and backspace is handled. Ctrl-C, Ctrl-D and the
// connection closing all cancel.
func (s *Session) readLine(echo bool) (string, error) {
	var line []byte
	buf := make([]byte, 1)
	escape := 0

	for {
		if _, err := s.Channel.Read(buf); err != nil {
			return "", errCancelled
		}
		b := buf[0]

		// Skip the escape sequences arrow and function keys send
		if escape == 1 {
			escape = 0
			if b == '[' || b == 'O' {
				escape = 2
			}
			continue
		}
		if escape == 2 {
			if b >= 0x40 && b <= 0x7e {
				escape = 0
			}
			continue
		}

		switch {
		case b == 0x1b:
			escape = 1
		case b == '\r' || b == '\n':
			if s.pty {
				io.WriteString(s.Channel, "\r\n")
			}
			return string(line), nil
		case b == 0x03 || b == 0x04:
			return "", errCancelled
		case b == 0x7f || b == 0x08:
			if len(line) > 0 {
				line = line[:len(line)-1]
				if s.pty && echo {
					io.WriteString(s.Channel, "\b \b")
				}
			}
		case b >= 0x20 && b < 0x7f:
			if len(line) < 64 {
				line = append(line, b)
				if s.pty && echo {
					s.Channel.Write([]byte{b})
				}
			}
		}
	}
}
