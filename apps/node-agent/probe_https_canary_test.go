package agent

import (
	"context"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

type canaryTestDialer func(context.Context, string, uint16) (net.Conn, error)

func (dialer canaryTestDialer) DialTarget(ctx context.Context, host string, port uint16) (net.Conn, error) {
	return dialer(ctx, host, port)
}

func TestHTTPSCanaryProbeExecutorEchoesChallengeOverVerifiedTLS(t *testing.T) {
	serverCertificate, _, caPEM := createTestCertificates(t)
	var received httpsCanaryProbeRequest
	server := newProbeCanaryTLSServer(t, serverCertificate, http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != http.MethodPost || request.URL.Path != nodeProbeCanaryPath {
			t.Errorf("canary request = %s %s; want POST %s", request.Method, request.URL.Path, nodeProbeCanaryPath)
		}
		if request.Header.Get("Content-Type") != "application/json" || request.Header.Get("Accept") != "application/json" {
			t.Errorf("canary request headers = %+v; want JSON headers", request.Header)
		}
		if err := json.NewDecoder(request.Body).Decode(&received); err != nil {
			t.Errorf("decode canary request: %v", err)
			writer.WriteHeader(http.StatusBadRequest)
			return
		}
		writer.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(writer).Encode(map[string]any{"data": map[string]string{"challenge": received.Challenge}})
	}))

	executor := newTestHTTPSCanaryExecutor(t, caPEM, server)
	challenge := bytesOfLength(nodeProbeChallengeBytes, 0x5a)
	echo, err := executor.Exchange(context.Background(), challenge)
	if err != nil {
		t.Fatalf("Exchange error = %v", err)
	}
	if !equalProbeChallenge(challenge, echo) {
		t.Fatalf("echo = %x; want original challenge", echo)
	}
	if received.Challenge != hex.EncodeToString(challenge) {
		t.Fatalf("canary received challenge %q; want %q", received.Challenge, hex.EncodeToString(challenge))
	}
}

func TestMTLSProbeListenerExecutesHTTPSCanaryExchange(t *testing.T) {
	serverCertificate, _, caPEM := createTestCertificates(t)
	canary := newProbeCanaryTLSServer(t, serverCertificate, http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		var payload httpsCanaryProbeRequest
		if err := json.NewDecoder(request.Body).Decode(&payload); err != nil {
			t.Errorf("decode canary challenge: %v", err)
			writer.WriteHeader(http.StatusBadRequest)
			return
		}
		writer.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(writer).Encode(map[string]any{"data": map[string]string{"challenge": payload.Challenge}})
	}))
	executor := newTestHTTPSCanaryExecutor(t, caPEM, canary)
	client, probeServer, _ := newMTLSTestClient(t, NewDataPlaneProbeHandler("node-canary", executor))
	defer probeServer.Close()
	challenge := bytesOfLength(nodeProbeChallengeBytes, 0x3c)
	var response NodeDataPlaneProbeResponse
	postJSON(t, client, probeServer.URL+nodeProbePath,
		NodeDataPlaneProbeRequest{Challenge: hex.EncodeToString(challenge)}, http.StatusOK, &response)
	echoedChallenge, err := decodeNodeProbeChallenge(response.Challenge)
	if err != nil {
		t.Fatalf("decode probe response challenge: %v", err)
	}
	if response.NodeID != "node-canary" || !equalProbeChallenge(challenge, echoedChallenge) {
		t.Fatalf("probe response = %+v; want configured node and exact challenge echo", response)
	}
}

func TestHTTPSCanaryProbeExecutorRejectsUntrustedCertificate(t *testing.T) {
	serverCertificate, _, _ := createTestCertificates(t)
	_, _, unrelatedCAPEM := createTestCertificates(t)
	server := newProbeCanaryTLSServer(t, serverCertificate, http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		writer.Header().Set("Content-Type", "application/json")
		_, _ = writer.Write([]byte(`{"data":{"challenge":"` + strings.Repeat("5a", nodeProbeChallengeBytes) + `"}}`))
	}))

	executor := newTestHTTPSCanaryExecutor(t, unrelatedCAPEM, server)
	if _, err := executor.Exchange(context.Background(), bytesOfLength(nodeProbeChallengeBytes, 0x5a)); err == nil {
		t.Fatal("canary with an untrusted TLS certificate was accepted")
	}
}

func TestHTTPSCanaryProbeExecutorRejectsChallengeMismatchAndRedirect(t *testing.T) {
	serverCertificate, _, caPEM := createTestCertificates(t)
	tests := []struct {
		name    string
		handler http.Handler
	}{
		{
			name: "challenge mismatch",
			handler: http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
				writer.Header().Set("Content-Type", "application/json")
				_, _ = writer.Write([]byte(`{"data":{"challenge":"` + strings.Repeat("00", nodeProbeChallengeBytes) + `"}}`))
			}),
		},
		{
			name: "challenge encoding mismatch",
			handler: http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
				writer.Header().Set("Content-Type", "application/json")
				_, _ = writer.Write([]byte(`{"data":{"challenge":"` + strings.Repeat("5A", nodeProbeChallengeBytes) + `"}}`))
			}),
		},
		{
			name: "redirect",
			handler: http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
				http.Redirect(writer, request, "https://canary.example.invalid/other", http.StatusFound)
			}),
		},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			server := newProbeCanaryTLSServer(t, serverCertificate, test.handler)
			executor := newTestHTTPSCanaryExecutor(t, caPEM, server)
			if _, err := executor.Exchange(context.Background(), bytesOfLength(nodeProbeChallengeBytes, 0x5a)); err == nil {
				t.Fatal("invalid canary response was accepted")
			}
		})
	}
}

func TestHTTPSCanaryProbeExecutorRejectsMalformedAndOversizedResponses(t *testing.T) {
	serverCertificate, _, caPEM := createTestCertificates(t)
	tests := []struct {
		name string
		body string
	}{
		{name: "invalid JSON", body: `not-json`},
		{name: "unknown response field", body: `{"data":{"challenge":"` + strings.Repeat("5a", nodeProbeChallengeBytes) + `","peer_id":"private"}}`},
		{name: "oversized body", body: strings.Repeat("x", maxCanaryResponseSize+1)},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			server := newProbeCanaryTLSServer(t, serverCertificate, http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
				writer.Header().Set("Content-Type", "application/json")
				_, _ = writer.Write([]byte(test.body))
			}))
			executor := newTestHTTPSCanaryExecutor(t, caPEM, server)
			if _, err := executor.Exchange(context.Background(), bytesOfLength(nodeProbeChallengeBytes, 0x5a)); err == nil {
				t.Fatal("malformed canary response was accepted")
			}
		})
	}
}

func TestHTTPSCanaryProbeExecutorRequiresBoundedChallengeAndHonorsContext(t *testing.T) {
	serverCertificate, _, caPEM := createTestCertificates(t)
	started := make(chan struct{})
	release := make(chan struct{})
	server := newProbeCanaryTLSServer(t, serverCertificate, http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		close(started)
		<-release
	}))
	t.Cleanup(func() { close(release) })
	executor := newTestHTTPSCanaryExecutor(t, caPEM, server)
	if _, err := executor.Exchange(context.Background(), []byte("short")); !errors.Is(err, ErrInvalid) {
		t.Fatalf("short challenge error = %v; want ErrInvalid", err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()
	if _, err := executor.Exchange(ctx, bytesOfLength(nodeProbeChallengeBytes, 0x5a)); err == nil {
		t.Fatal("timed-out probe unexpectedly succeeded")
	}
	select {
	case <-started:
	case <-time.After(time.Second):
		t.Fatal("canary did not receive the bounded probe request")
	}
}

func newProbeCanaryTLSServer(t *testing.T, certificate tls.Certificate, handler http.Handler) *httptest.Server {
	t.Helper()
	server := httptest.NewUnstartedServer(handler)
	server.TLS = &tls.Config{MinVersion: tls.VersionTLS13, Certificates: []tls.Certificate{certificate}}
	server.StartTLS()
	t.Cleanup(server.Close)
	return server
}

func newTestHTTPSCanaryExecutor(t *testing.T, caPEM []byte, server *httptest.Server) DataPlaneProbeExecutor {
	t.Helper()
	portValue := strings.TrimPrefix(server.Listener.Addr().String(), "127.0.0.1:")
	port, err := strconv.ParseUint(portValue, 10, 16)
	if err != nil {
		t.Fatalf("parse test canary port: %v", err)
	}
	dialer := canaryTestDialer(func(ctx context.Context, host string, requestedPort uint16) (net.Conn, error) {
		if host != "localhost" || requestedPort != 443 {
			return nil, errInvalidProbeCanary
		}
		return (&net.Dialer{Timeout: time.Second}).DialContext(ctx, "tcp", net.JoinHostPort("127.0.0.1", strconv.FormatUint(port, 10)))
	})
	config := DataPlaneProbeRuntimeConfig{
		Status:         RuntimeProbeHTTPSCanary,
		CanaryEndpoint: "https://localhost" + nodeProbeCanaryPath,
		ServerCAPEM:    string(caPEM),
	}
	executor, err := NewHTTPSCanaryProbeExecutor(config, dialer)
	if err != nil {
		t.Fatalf("NewHTTPSCanaryProbeExecutor error = %v", err)
	}
	return executor
}

func bytesOfLength(length int, value byte) []byte {
	bytes := make([]byte, length)
	for index := range bytes {
		bytes[index] = value
	}
	return bytes
}
