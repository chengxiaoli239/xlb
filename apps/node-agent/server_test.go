package agent

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

type probeExecutorFunc func(context.Context, []byte) ([]byte, error)

func (executor probeExecutorFunc) Exchange(ctx context.Context, challenge []byte) ([]byte, error) {
	return executor(ctx, challenge)
}

func TestMTLSHTTPAPIEnforcesCapacityForConcurrentAuthorizations(t *testing.T) {
	store, err := OpenPeerStore(filepath.Join(t.TempDir(), "agent-state.json"), "node-test", 3)
	if err != nil {
		t.Fatal(err)
	}
	client, server, _ := newMTLSTestClient(t, NewHTTPHandler(store))
	defer server.Close()

	const attempts = 12
	authorizations := make([]AuthorizeRequest, attempts)
	for index := range attempts {
		peerID := fmt.Sprintf("peer-http-%02d", index)
		ensure, privateKey := testEnsureRequest(t, peerID, "ensure-"+peerID)
		postJSON(t, client, server.URL+"/v1/member-peers/ensure", ensure, http.StatusOK, nil)

		challengePayload := challengeRequest{
			ChallengeRequest: challengeFor(ensure), Credential: ensure.Credential,
			SubscriptionToken: ensure.SubscriptionToken,
		}
		var challenge Challenge
		postJSON(t, client, server.URL+"/v1/member-peers/challenge", challengePayload, http.StatusOK, &challenge)
		authorization := signedAuthorization(t, privateKey, challenge, ensure)
		authorization.SessionID = "session-" + peerID
		authorizations[index] = authorization
	}

	start := make(chan struct{})
	statuses := make(chan int, attempts)
	var wait sync.WaitGroup
	for _, authorization := range authorizations {
		wait.Add(1)
		go func(payload AuthorizeRequest) {
			defer wait.Done()
			<-start
			body, err := json.Marshal(payload)
			if err != nil {
				statuses <- 0
				return
			}
			request, err := http.NewRequest(http.MethodPost, server.URL+"/v1/member-peers/authorize", bytes.NewReader(body))
			if err != nil {
				statuses <- 0
				return
			}
			request.Header.Set("Content-Type", "application/json")
			response, err := client.Do(request)
			if err != nil {
				statuses <- 0
				return
			}
			defer response.Body.Close()
			statuses <- response.StatusCode
		}(authorization)
	}
	close(start)
	wait.Wait()
	close(statuses)

	accepted := 0
	rejectedForCapacity := 0
	for status := range statuses {
		switch status {
		case http.StatusOK:
			accepted++
		case http.StatusConflict:
			rejectedForCapacity++
		default:
			t.Fatalf("unexpected authorization status %d", status)
		}
	}
	if accepted != 3 || rejectedForCapacity != attempts-3 {
		t.Fatalf("concurrent API authorizations accepted=%d capacity_rejected=%d; want 3 and %d", accepted, rejectedForCapacity, attempts-3)
	}
}

func TestMTLSHTTPAPIRejectsUnknownClientAndMalformedPayload(t *testing.T) {
	store, err := OpenPeerStore(filepath.Join(t.TempDir(), "agent-state.json"), "node-test", 1)
	if err != nil {
		t.Fatal(err)
	}
	client, server, caPEM := newMTLSTestClient(t, NewHTTPHandler(store))
	defer server.Close()

	response, err := client.Get(server.URL + "/healthz")
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != http.StatusOK {
		t.Fatalf("health status=%d; want %d", response.StatusCode, http.StatusOK)
	}

	request, err := http.NewRequest(http.MethodPost, server.URL+"/v1/member-peers/ensure", bytes.NewBufferString(`{"node_id":"node-test","unknown":"field"}`))
	if err != nil {
		t.Fatal(err)
	}
	request.Header.Set("Content-Type", "application/json")
	response, err = client.Do(request)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != http.StatusBadRequest {
		t.Fatalf("malformed payload status=%d; want %d", response.StatusCode, http.StatusBadRequest)
	}

	noCertificateClient := &http.Client{Transport: &http.Transport{TLSClientConfig: &tls.Config{RootCAs: testRootPool(t, caPEM), MinVersion: tls.VersionTLS13}}}
	if _, err := noCertificateClient.Get(server.URL + "/healthz"); err == nil {
		t.Fatal("health endpoint accepted a client without a trusted certificate")
	}
}

func TestMTLSHTTPAPIRuntimeStatusReportsOnlyCapacityCounts(t *testing.T) {
	store, err := OpenPeerStore(filepath.Join(t.TempDir(), "agent-state.json"), "node-test", 3)
	if err != nil {
		t.Fatal(err)
	}
	if err := store.update(func(state *agentState) error {
		state.Sessions["active-session"] = sessionRecord{PeerID: "private-peer-id", ExpiresAt: time.Now().Add(time.Minute)}
		state.Sessions["expired-session"] = sessionRecord{PeerID: "expired-peer-id", ExpiresAt: time.Now().Add(-time.Minute)}
		return nil
	}); err != nil {
		t.Fatal(err)
	}
	client, server, _ := newMTLSTestClient(t, NewHTTPHandler(store))
	defer server.Close()

	response, err := client.Get(server.URL + "/v1/test/status")
	if err != nil {
		t.Fatal(err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		t.Fatalf("runtime status=%d; want %d", response.StatusCode, http.StatusOK)
	}
	body, err := io.ReadAll(response.Body)
	if err != nil {
		t.Fatal(err)
	}
	var envelope struct {
		Data struct {
			ActiveSessions int `json:"active_sessions"`
			Capacity       int `json:"capacity"`
		} `json:"data"`
	}
	if err := json.Unmarshal(body, &envelope); err != nil {
		t.Fatal(err)
	}
	if envelope.Data.ActiveSessions != 1 || envelope.Data.Capacity != 3 {
		t.Fatalf("runtime status=%+v; want one active session and capacity three", envelope.Data)
	}
	if strings.Contains(string(body), "private-peer-id") || strings.Contains(string(body), "expired-peer-id") {
		t.Fatalf("runtime status exposed peer identifiers: %s", body)
	}
	expiredSessionExists := true
	if err := store.update(func(state *agentState) error {
		_, expiredSessionExists = state.Sessions["expired-session"]
		return nil
	}); err != nil {
		t.Fatal(err)
	}
	if expiredSessionExists {
		t.Fatal("runtime status did not discard an expired session")
	}
}

func TestMTLSHTTPAPIReportsCumulativePeerUsage(t *testing.T) {
	store, err := OpenPeerStore(filepath.Join(t.TempDir(), "agent-state.json"), "node-test", 3)
	if err != nil {
		t.Fatal(err)
	}
	ensure, _ := testEnsureRequest(t, "peer-usage-http", "ensure-usage-http")
	if _, err := store.EnsurePeer(ensure); err != nil {
		t.Fatal(err)
	}
	if err := store.AddTraffic(ensure.PeerID, 17, 29); err != nil {
		t.Fatal(err)
	}
	client, server, _ := newMTLSTestClient(t, NewHTTPHandler(store))
	defer server.Close()

	response, err := client.Get(server.URL + "/v1/member-peers/" + ensure.PeerID + "/usage")
	if err != nil {
		t.Fatal(err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		t.Fatalf("usage status=%d; want %d", response.StatusCode, http.StatusOK)
	}
	var envelope struct {
		Data PeerUsageSnapshot `json:"data"`
	}
	if err := json.NewDecoder(response.Body).Decode(&envelope); err != nil {
		t.Fatal(err)
	}
	if envelope.Data.NodeID != ensure.NodeID || envelope.Data.PeerID != ensure.PeerID ||
		envelope.Data.UploadBytes != 17 || envelope.Data.DownloadBytes != 29 {
		t.Fatalf("usage response=%+v; identity or counters did not match", envelope.Data)
	}
}

func TestHTTPHandlerRejectsPlainHTTP(t *testing.T) {
	store, err := OpenPeerStore(filepath.Join(t.TempDir(), "agent-state.json"), "node-test", 1)
	if err != nil {
		t.Fatal(err)
	}
	server := httptest.NewServer(NewHTTPHandler(store))
	defer server.Close()

	response, err := http.Get(server.URL + "/healthz")
	if err != nil {
		t.Fatal(err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusUnauthorized {
		t.Fatalf("plain HTTP status=%d; want %d", response.StatusCode, http.StatusUnauthorized)
	}
}

func TestNodeProbeDefaultsToUnavailableWithoutExecutor(t *testing.T) {
	for name, handler := range map[string]http.Handler{
		"executor missing": NewDataPlaneProbeHandler("node-test", nil),
		"node identity missing": NewDataPlaneProbeHandler("", probeExecutorFunc(func(context.Context, []byte) ([]byte, error) {
			return nil, errors.New("executor must not run without node identity")
		})),
	} {
		t.Run(name, func(t *testing.T) {
			client, server, _ := newMTLSTestClient(t, handler)
			defer server.Close()

			response := postRawJSON(t, client, server.URL+nodeProbePath, `{"challenge":"0000000000000000000000000000000000000000000000000000000000000000"}`)
			defer response.Body.Close()
			if response.StatusCode != http.StatusServiceUnavailable {
				t.Fatalf("unconfigured probe status=%d; want %d", response.StatusCode, http.StatusServiceUnavailable)
			}
			assertProbeError(t, response, "probe_unavailable")
		})
	}
}

func TestNodeProbeRequiresVerifiedClientCertificate(t *testing.T) {
	executor := probeExecutorFunc(func(context.Context, []byte) ([]byte, error) {
		return nil, errors.New("executor must not run without a verified client certificate")
	})
	handler := NewDataPlaneProbeHandler("node-test", executor)

	for name, state := range map[string]*tls.ConnectionState{
		"no TLS":             nil,
		"no client cert":     {},
		"no verified chains": {PeerCertificates: []*x509.Certificate{{}}, VerifiedChains: nil},
	} {
		t.Run(name, func(t *testing.T) {
			request := httptest.NewRequest(http.MethodPost, nodeProbePath, strings.NewReader(`{"challenge":"0000000000000000000000000000000000000000000000000000000000000000"}`))
			request.TLS = state
			recorder := httptest.NewRecorder()
			handler.ServeHTTP(recorder, request)
			if recorder.Code != http.StatusUnauthorized {
				t.Fatalf("unverified request status=%d; want %d", recorder.Code, http.StatusUnauthorized)
			}
		})
	}
}

func TestNodeProbeRejectsMalformedChallenge(t *testing.T) {
	executor := probeExecutorFunc(func(context.Context, []byte) ([]byte, error) {
		return nil, errors.New("executor must not run with a malformed challenge")
	})
	client, server, _ := newMTLSTestClient(t, NewDataPlaneProbeHandler("node-test", executor))
	defer server.Close()

	for _, challenge := range []string{"", "abcd", strings.Repeat("z", 64), strings.Repeat("0", 62)} {
		t.Run(fmt.Sprintf("length-%d", len(challenge)), func(t *testing.T) {
			body, err := json.Marshal(map[string]string{"challenge": challenge})
			if err != nil {
				t.Fatal(err)
			}
			response := postRawJSON(t, client, server.URL+nodeProbePath, string(body))
			defer response.Body.Close()
			if response.StatusCode != http.StatusBadRequest {
				t.Fatalf("malformed challenge status=%d; want %d", response.StatusCode, http.StatusBadRequest)
			}
			assertProbeError(t, response, "invalid_request")
		})
	}
}

func TestNodeProbeTimesOutWithoutReturningChallenge(t *testing.T) {
	executor := probeExecutorFunc(func(ctx context.Context, _ []byte) ([]byte, error) {
		<-ctx.Done()
		return nil, ctx.Err()
	})
	client, server, _ := newMTLSTestClient(t, NewDataPlaneProbeHandler("node-test", executor))
	defer server.Close()

	challenge := strings.Repeat("a", 64)
	response := postRawJSON(t, client, server.URL+nodeProbePath, fmt.Sprintf(`{"challenge":%q}`, challenge))
	defer response.Body.Close()
	if response.StatusCode != http.StatusGatewayTimeout {
		t.Fatalf("timed out probe status=%d; want %d", response.StatusCode, http.StatusGatewayTimeout)
	}
	assertProbeError(t, response, "probe_timeout")
}

func TestNodeProbeExecutorFailureDoesNotReturnChallenge(t *testing.T) {
	executor := probeExecutorFunc(func(context.Context, []byte) ([]byte, error) {
		return nil, errors.New("sensitive executor detail")
	})
	client, server, _ := newMTLSTestClient(t, NewDataPlaneProbeHandler("node-test", executor))
	defer server.Close()

	challenge := strings.Repeat("b", 64)
	response := postRawJSON(t, client, server.URL+nodeProbePath, fmt.Sprintf(`{"challenge":%q}`, challenge))
	defer response.Body.Close()
	if response.StatusCode != http.StatusServiceUnavailable {
		t.Fatalf("failed probe status=%d; want %d", response.StatusCode, http.StatusServiceUnavailable)
	}
	body, err := io.ReadAll(response.Body)
	if err != nil {
		t.Fatal(err)
	}
	var envelope struct {
		Error *apiError `json:"error"`
	}
	if err := json.Unmarshal(body, &envelope); err != nil {
		t.Fatal(err)
	}
	if envelope.Error == nil || envelope.Error.Code != "probe_failed" {
		t.Fatalf("probe error=%+v; want code probe_failed", envelope.Error)
	}
	if strings.Contains(string(body), "sensitive executor detail") || strings.Contains(string(body), challenge) {
		t.Fatalf("failed probe exposed executor details or challenge: %s", body)
	}
}

func TestNodeProbeReturnsNodeIdentityAndChallengeOnlyAfterExecutorEcho(t *testing.T) {
	var gotChallenge []byte
	executor := probeExecutorFunc(func(_ context.Context, challenge []byte) ([]byte, error) {
		gotChallenge = append([]byte(nil), challenge...)
		return append([]byte(nil), challenge...), nil
	})
	client, server, _ := newMTLSTestClient(t, NewDataPlaneProbeHandler("node-canary-17", executor))
	defer server.Close()

	challenge := strings.Repeat("c", 64)
	response := postRawJSON(t, client, server.URL+nodeProbePath, fmt.Sprintf(`{"challenge":%q}`, challenge))
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		t.Fatalf("successful fake probe status=%d; want %d", response.StatusCode, http.StatusOK)
	}
	var envelope struct {
		Data struct {
			NodeID    string `json:"node_id"`
			Challenge string `json:"challenge"`
		} `json:"data"`
	}
	if err := json.NewDecoder(response.Body).Decode(&envelope); err != nil {
		t.Fatal(err)
	}
	if envelope.Data.NodeID != "node-canary-17" || envelope.Data.Challenge != challenge || hex.EncodeToString(gotChallenge) != challenge {
		t.Fatalf("probe response=%+v executorChallenge=%x; expected configured identity and exact challenge", envelope.Data, gotChallenge)
	}
}

func TestNodeProbeRejectsExecutorEchoMismatch(t *testing.T) {
	executor := probeExecutorFunc(func(context.Context, []byte) ([]byte, error) {
		return bytes.Repeat([]byte{0xff}, nodeProbeChallengeBytes), nil
	})
	client, server, _ := newMTLSTestClient(t, NewDataPlaneProbeHandler("node-test", executor))
	defer server.Close()

	response := postRawJSON(t, client, server.URL+nodeProbePath, fmt.Sprintf(`{"challenge":%q}`, strings.Repeat("d", 64)))
	defer response.Body.Close()
	if response.StatusCode != http.StatusServiceUnavailable {
		t.Fatalf("mismatched echo status=%d; want %d", response.StatusCode, http.StatusServiceUnavailable)
	}
	assertProbeError(t, response, "probe_failed")
}

func postRawJSON(t *testing.T, client *http.Client, endpoint, body string) *http.Response {
	t.Helper()
	request, err := http.NewRequest(http.MethodPost, endpoint, strings.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	request.Header.Set("Content-Type", "application/json")
	response, err := client.Do(request)
	if err != nil {
		t.Fatal(err)
	}
	return response
}

func assertProbeError(t *testing.T, response *http.Response, expected string) {
	t.Helper()
	var envelope struct {
		Error *apiError `json:"error"`
	}
	if err := json.NewDecoder(response.Body).Decode(&envelope); err != nil {
		t.Fatal(err)
	}
	if envelope.Error == nil || envelope.Error.Code != expected {
		t.Fatalf("probe error=%+v; want code %q", envelope.Error, expected)
	}
}

func postJSON(t *testing.T, client *http.Client, endpoint string, payload any, expectedStatus int, target any) {
	t.Helper()
	body, err := json.Marshal(payload)
	if err != nil {
		t.Fatal(err)
	}
	request, err := http.NewRequest(http.MethodPost, endpoint, bytes.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	request.Header.Set("Content-Type", "application/json")
	response, err := client.Do(request)
	if err != nil {
		t.Fatal(err)
	}
	defer response.Body.Close()
	if response.StatusCode != expectedStatus {
		t.Fatalf("POST %s status=%d; want %d", endpoint, response.StatusCode, expectedStatus)
	}
	if target == nil {
		return
	}
	var envelope struct {
		Data json.RawMessage `json:"data"`
	}
	if err := json.NewDecoder(response.Body).Decode(&envelope); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(envelope.Data, target); err != nil {
		t.Fatal(err)
	}
}

func newMTLSTestClient(t *testing.T, handler http.Handler) (*http.Client, *httptest.Server, []byte) {
	t.Helper()
	serverTLS, clientTLS, caPEM := createTestCertificates(t)
	certificatePath := filepath.Join(t.TempDir(), "server.crt")
	privateKeyPath := filepath.Join(filepath.Dir(certificatePath), "server.key")
	clientCAPath := filepath.Join(filepath.Dir(certificatePath), "client-ca.crt")
	if err := os.WriteFile(certificatePath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverTLS.Certificate[0]}), 0o600); err != nil {
		t.Fatal(err)
	}
	keyDER, err := x509.MarshalPKCS8PrivateKey(serverTLS.PrivateKey)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(privateKeyPath, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER}), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(clientCAPath, caPEM, 0o600); err != nil {
		t.Fatal(err)
	}
	configured, err := NewMTLSServer("127.0.0.1:9443", certificatePath, privateKeyPath, clientCAPath, handler)
	if err != nil {
		t.Fatal(err)
	}
	server := httptest.NewUnstartedServer(handler)
	server.TLS = configured.TLSConfig
	server.StartTLS()
	transport := &http.Transport{TLSClientConfig: clientTLS}
	t.Cleanup(transport.CloseIdleConnections)
	return &http.Client{Transport: transport, Timeout: 5 * time.Second}, server, caPEM
}

func createTestCertificates(t *testing.T) (tls.Certificate, *tls.Config, []byte) {
	t.Helper()
	now := time.Now()
	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	caTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "XLB test CA"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), IsCA: true,
		BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature,
	}
	caDER, err := x509.CreateCertificate(rand.Reader, caTemplate, caTemplate, &caKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	caCertificate, err := x509.ParseCertificate(caDER)
	if err != nil {
		t.Fatal(err)
	}
	issue := func(serial int64, name string, usage x509.ExtKeyUsage) tls.Certificate {
		key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		if err != nil {
			t.Fatal(err)
		}
		template := &x509.Certificate{
			SerialNumber: big.NewInt(serial), Subject: pkix.Name{CommonName: name},
			NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
			KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{usage},
			DNSNames: []string{"localhost"}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")},
		}
		der, err := x509.CreateCertificate(rand.Reader, template, caCertificate, &key.PublicKey, caKey)
		if err != nil {
			t.Fatal(err)
		}
		certificateDER, err := x509.MarshalPKCS8PrivateKey(key)
		if err != nil {
			t.Fatal(err)
		}
		certificate, err := tls.X509KeyPair(
			pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}),
			pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: certificateDER}),
		)
		if err != nil {
			t.Fatal(err)
		}
		return certificate
	}
	serverCertificate := issue(2, "localhost", x509.ExtKeyUsageServerAuth)
	clientCertificate := issue(3, "test client", x509.ExtKeyUsageClientAuth)
	serverCertificate.Certificate = append(serverCertificate.Certificate, caDER)
	clientCertificate.Certificate = append(clientCertificate.Certificate, caDER)
	roots := x509.NewCertPool()
	roots.AddCert(caCertificate)
	clientTLS := &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: roots, Certificates: []tls.Certificate{clientCertificate}}
	return serverCertificate, clientTLS, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: caDER})
}

func testRootPool(t *testing.T, caPEM []byte) *x509.CertPool {
	t.Helper()
	pool := x509.NewCertPool()
	if !pool.AppendCertsFromPEM(caPEM) {
		t.Fatal("failed to add test root CA")
	}
	return pool
}
