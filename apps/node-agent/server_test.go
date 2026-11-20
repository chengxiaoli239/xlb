package agent

import (
	"bytes"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
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
