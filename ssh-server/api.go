package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"
)

// API talks to indielogin.com's private SSH server API. This server has no
// access to indielogin.com's storage; everything it knows about a sign-in
// comes from here.
type API struct {
	BaseURL string
	Key     string
	Client  *http.Client
}

// Result is what both API calls answer with.
type Result struct {
	OK          bool     `json:"ok"`
	Reason      string   `json:"reason"`
	Message     string   `json:"message"`
	Matches     int      `json:"matches"`
	ClientID    string   `json:"client_id"`
	Me          string   `json:"me"`
	Started     int64    `json:"started"`
	IP          string   `json:"ip"`
	Fingerprint string   `json:"fingerprint"`
	Type        string   `json:"type"`
	KeysURLs    []string `json:"keys_urls"`
}

func NewAPI(baseURL, key string) *API {
	return &API{
		BaseURL: strings.TrimRight(baseURL, "/"),
		Key:     key,
		Client:  &http.Client{Timeout: 5 * time.Second},
	}
}

// Check asks whether key may sign in for user, a domain or a connect code,
// and which waiting sign-in that would be. code, if given, picks one when
// the domain alone matches more than one.
func (a *API) Check(user, key, code string) (*Result, error) {
	return a.call("/ssh-server/check", user, key, code)
}

// Approve records that the person holding key confirmed the sign-in.
func (a *API) Approve(user, key, code string) (*Result, error) {
	return a.call("/ssh-server/approve", user, key, code)
}

func (a *API) call(path, user, key, code string) (*Result, error) {
	body, err := json.Marshal(map[string]string{"user": user, "key": key, "code": code})
	if err != nil {
		return nil, err
	}

	req, err := http.NewRequest("POST", a.BaseURL+path, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+a.Key)

	res, err := a.Client.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()

	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s returned HTTP %d", path, res.StatusCode)
	}

	var result Result
	if err := json.NewDecoder(res.Body).Decode(&result); err != nil {
		return nil, fmt.Errorf("%s returned something that is not JSON: %w", path, err)
	}
	return &result, nil
}
