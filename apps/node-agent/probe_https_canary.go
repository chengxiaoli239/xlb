package agent

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
)

const (
	nodeProbeCanaryPath   = "/.well-known/xlb-node-probe/v1"
	maxCanaryResponseSize = 8 * 1024
)

var errInvalidProbeCanary = errors.New("node data-plane canary configuration is invalid")

type httpsCanaryProbeRequest struct {
	Challenge string `json:"challenge"`
}

type httpsCanaryProbeResponse struct {
	Data struct {
		Challenge string `json:"challenge"`
	} `json:"data"`
}

type httpsCanaryProbeExecutor struct {
	client   *http.Client
	endpoint string
}

func validateDataPlaneProbeRuntimeConfig(environment string, config DataPlaneProbeRuntimeConfig) error {
	status := config.Status
	if status == "" {
		status = RuntimeProbeUnavailable
	}
	if status == RuntimeProbeUnavailable {
		if config.CanaryEndpoint != "" || config.ServerCAPEM != "" {
			return errInvalidProbeCanary
		}
		return nil
	}
	if status != RuntimeProbeHTTPSCanary || environment != RuntimeEnvironmentTest {
		return errInvalidProbeCanary
	}
	if err := validateProbeCanaryEndpoint(config.CanaryEndpoint, config.ServerCAPEM); err != nil {
		return errInvalidProbeCanary
	}
	return nil
}

func validateProbeCanaryEndpoint(endpoint, caBundle string) error {
	if len(endpoint) > 2048 || len(caBundle) == 0 || len(caBundle) > 64*1024 {
		return errInvalidProbeCanary
	}
	parsed, err := url.Parse(endpoint)
	if err != nil || parsed.Scheme != "https" || parsed.Hostname() == "" || parsed.User != nil ||
		parsed.RawQuery != "" || parsed.Fragment != "" || parsed.Path != nodeProbeCanaryPath ||
		parsed.RawPath != "" || !validTargetHost(parsed.Hostname()) {
		return errInvalidProbeCanary
	}
	if parsed.Port() != "" && parsed.Port() != "443" {
		return errInvalidProbeCanary
	}

	return validateMemberBridgeDescriptor("https://"+parsed.Host, caBundle)
}

func NewHTTPSCanaryProbeExecutor(config DataPlaneProbeRuntimeConfig, dialer TargetDialer) (DataPlaneProbeExecutor, error) {
	if validateDataPlaneProbeRuntimeConfig(RuntimeEnvironmentTest, config) != nil ||
		config.Status != RuntimeProbeHTTPSCanary || dialer == nil {
		return nil, errInvalidProbeCanary
	}
	parsed, err := url.Parse(config.CanaryEndpoint)
	if err != nil {
		return nil, errInvalidProbeCanary
	}
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM([]byte(config.ServerCAPEM)) {
		return nil, errInvalidProbeCanary
	}
	serverName := parsed.Hostname()
	transport := &http.Transport{
		Proxy: nil,
		TLSClientConfig: &tls.Config{
			MinVersion: tls.VersionTLS13,
			RootCAs:    roots,
			ServerName: serverName,
		},
		DialContext: func(ctx context.Context, network, address string) (net.Conn, error) {
			host, port, splitErr := net.SplitHostPort(address)
			if splitErr != nil || !strings.EqualFold(host, serverName) || port != "443" || network != "tcp" {
				return nil, errInvalidProbeCanary
			}
			return dialer.DialTarget(ctx, host, 443)
		},
		MaxResponseHeaderBytes: 16 * 1024,
	}
	return &httpsCanaryProbeExecutor{
		client: &http.Client{
			Transport: transport,
			CheckRedirect: func(*http.Request, []*http.Request) error {
				return http.ErrUseLastResponse
			},
		},
		endpoint: config.CanaryEndpoint,
	}, nil
}

func (executor *httpsCanaryProbeExecutor) Exchange(ctx context.Context, challenge []byte) ([]byte, error) {
	if executor == nil || executor.client == nil || len(challenge) != nodeProbeChallengeBytes {
		return nil, ErrInvalid
	}
	encodedChallenge := hex.EncodeToString(challenge)
	requestBody, err := json.Marshal(httpsCanaryProbeRequest{Challenge: encodedChallenge})
	if err != nil {
		return nil, err
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, executor.endpoint, bytes.NewReader(requestBody))
	if err != nil {
		return nil, errInvalidProbeCanary
	}
	request.Header.Set("Accept", "application/json")
	request.Header.Set("Content-Type", "application/json")
	response, err := executor.client.Do(request)
	if err != nil {
		return nil, err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return nil, errInvalidProbeCanary
	}
	body, err := io.ReadAll(io.LimitReader(response.Body, maxCanaryResponseSize+1))
	if err != nil || len(body) == 0 || len(body) > maxCanaryResponseSize {
		return nil, errInvalidProbeCanary
	}
	var payload httpsCanaryProbeResponse
	decoder := json.NewDecoder(bytes.NewReader(body))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&payload) != nil || payload.Data.Challenge == "" ||
		decoder.Decode(new(any)) != io.EOF {
		return nil, errInvalidProbeCanary
	}
	if payload.Data.Challenge != encodedChallenge {
		return nil, errInvalidProbeCanary
	}
	return append([]byte(nil), challenge...), nil
}
