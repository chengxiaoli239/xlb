package agent

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"sync"
	"testing"
	"time"
)

func TestDataPlaneReservesCapacityForConcurrentDeviceSessions(t *testing.T) {
	const (
		capacity = 3
		attempts = 12
	)
	store := openTestStore(t, capacity)
	dialer := newEchoTargetDialer(t)
	plane, err := NewDataPlane(store, dialer)
	if err != nil {
		t.Fatal(err)
	}
	server, roots := newDataPlaneTestServer(t, plane)
	defer server.Close()

	type attemptResult struct {
		status int
		proto  int
		err    error
	}
	clients := make([]*http.Client, attempts)
	transports := make([]*http.Transport, attempts)
	for index := range attempts {
		peerSuffix := fmt.Sprintf("%02d", index)
		request, privateKey := testEnsureRequest(t, "peer-capacity-"+peerSuffix, "ensure-capacity-"+peerSuffix)
		if _, err := store.EnsurePeer(request); err != nil {
			t.Fatal(err)
		}
		certificate := deviceTestCertificate(t, request, privateKey)
		clients[index], transports[index] = newDataPlaneClient(t, server.URL, certificate, roots)
	}
	defer func() {
		for _, transport := range transports {
			transport.CloseIdleConnections()
		}
	}()

	start := make(chan struct{})
	results := make(chan attemptResult, attempts)
	var wait sync.WaitGroup
	for index, client := range clients {
		wait.Add(1)
		go func(index int, client *http.Client) {
			defer wait.Done()
			<-start
			query := url.Values{"host": []string{"echo.test"}, "port": []string{"443"}}
			requestURL := server.URL + "/v1/tunnel/connect?" + query.Encode()
			response, err := client.Post(requestURL, "application/octet-stream", bytes.NewReader([]byte{byte(index + 1)}))
			if err != nil {
				results <- attemptResult{err: err}
				return
			}
			defer response.Body.Close()
			_, bodyErr := io.Copy(io.Discard, response.Body)
			results <- attemptResult{status: response.StatusCode, proto: response.ProtoMajor, err: bodyErr}
		}(index, client)
	}
	close(start)
	wait.Wait()
	close(results)

	accepted, capacityRejected := 0, 0
	for result := range results {
		if result.err != nil {
			t.Fatalf("tunnel request failed: %v", result.err)
		}
		switch result.status {
		case http.StatusOK:
			accepted++
			if result.proto != 2 {
				t.Fatalf("data tunnel used HTTP/%d; want HTTP/2 multiplexing", result.proto)
			}
		case http.StatusConflict:
			capacityRejected++
		default:
			t.Fatalf("unexpected tunnel status %d", result.status)
		}
	}
	if accepted != capacity || capacityRejected != attempts-capacity {
		t.Fatalf("accepted=%d capacity_rejected=%d; want %d and %d", accepted, capacityRejected, capacity, attempts-capacity)
	}
}

func TestDeviceCertificateLifetimeIsBounded(t *testing.T) {
	request, key := testEnsureRequest(t, "peer-certificate-lifetime", "ensure-certificate-lifetime")
	lifetime := 10 * time.Minute
	certificate, err := NewDeviceCertificateWithLifetime(deviceIdentityFor(request), key, lifetime)
	if err != nil {
		t.Fatal(err)
	}
	if remaining := time.Until(certificate.Leaf.NotAfter); remaining <= 0 || remaining > lifetime {
		t.Fatalf("certificate remaining lifetime = %s; want positive and at most %s", remaining, lifetime)
	}

	for _, invalidLifetime := range []time.Duration{0, -time.Minute, 12*time.Hour + time.Second} {
		if _, err := NewDeviceCertificateWithLifetime(deviceIdentityFor(request), key, invalidLifetime); !errors.Is(err, ErrInvalid) {
			t.Errorf("lifetime %s error = %v; want ErrInvalid", invalidLifetime, err)
		}
	}
}

func TestQuotaEnforcedDataPlaneRequiresAQuotaProvider(t *testing.T) {
	store := openTestStore(t, 1)
	plane, err := NewQuotaEnforcedDataPlane(store, newEchoTargetDialer(t), nil)
	if !errors.Is(err, ErrInvalid) {
		t.Fatalf("NewQuotaEnforcedDataPlane error = %v; want missing provider rejected", err)
	}
	if plane != nil {
		t.Fatal("quota-enforced data plane was created without an authority provider")
	}
}

func TestRecoveredQuotaDataPlaneResolvesBothOutboxesBeforeCreation(t *testing.T) {
	store := openTestStore(t, 1)
	authority := newQuotaAuthorityFake(20)
	unknownReservation := QuotaReservationRequest{
		EntitlementID: "entitlement-recovery", NodeID: "node-test", PeerID: "peer-reservation-unknown",
		Generation: 1, RequestID: "reservation-unknown", GrantBytes: 10,
	}
	authority.failNextReserveAfterExecution = true
	if _, err := OpenQuotaLease(context.Background(), authority, store, unknownReservation); !errors.Is(err, ErrQuotaUnavailable) {
		t.Fatalf("reservation with lost response error = %v; want authority outcome unknown", err)
	}
	knownReservation := QuotaReservationRequest{
		EntitlementID: "entitlement-recovery", NodeID: "node-test", PeerID: "peer-settlement-unknown",
		Generation: 1, RequestID: "settlement-unknown", GrantBytes: 10,
	}
	lease, err := OpenQuotaLease(context.Background(), authority, store, knownReservation)
	if err != nil {
		t.Fatal(err)
	}
	permit, err := lease.Permit("download", 4)
	if err != nil {
		t.Fatal(err)
	}
	if err := permit.Commit(4); err != nil {
		t.Fatal(err)
	}
	authority.failNextFinal = true
	if err := lease.Finalize(context.Background(), "settlement-ack-recovery"); !errors.Is(err, ErrQuotaPending) {
		t.Fatalf("settlement with lost response error = %v; want pending", err)
	}

	plane, err := NewRecoveredQuotaAuthorityDataPlane(
		context.Background(), store, newEchoTargetDialer(t), authority, 8, 10,
	)
	if err != nil {
		t.Fatal(err)
	}
	if plane == nil {
		t.Fatal("recovered quota data plane was not created")
	}
	reservation, err := store.quotaLease(unknownReservation.RequestID)
	if err != nil || !reservation.Granted {
		t.Fatalf("unknown reservation was not replayed before startup: snapshot=%+v err=%v", reservation, err)
	}
	settlement, err := store.quotaLease(knownReservation.RequestID)
	if err != nil || !settlement.Settled {
		t.Fatalf("unknown settlement was not replayed before startup: snapshot=%+v err=%v", settlement, err)
	}
}

func TestRecoveredQuotaDataPlaneRefusesStartupWhenOutboxReplayFails(t *testing.T) {
	store := openTestStore(t, 1)
	authority := newQuotaAuthorityFake(10)
	request := QuotaReservationRequest{
		EntitlementID: "entitlement-recovery", NodeID: "node-test", PeerID: "peer-reservation-pending",
		Generation: 1, RequestID: "reservation-pending", GrantBytes: 10,
	}
	authority.failNextReserveAfterExecution = true
	if _, err := OpenQuotaLease(context.Background(), authority, store, request); !errors.Is(err, ErrQuotaUnavailable) {
		t.Fatalf("initial reserve error = %v; want unknown outcome", err)
	}
	authority.unavailable = true
	plane, err := NewRecoveredQuotaAuthorityDataPlane(
		context.Background(), store, newEchoTargetDialer(t), authority, 10, 10,
	)
	if !errors.Is(err, ErrQuotaPending) {
		t.Fatalf("startup recovery error = %v; want pending fail-closed result", err)
	}
	if plane != nil {
		t.Fatal("data plane was created while quota outbox recovery was unresolved")
	}
}

func TestQuotaEnforcedDataPlaneReservesAndSettlesBytesBeforeForwarding(t *testing.T) {
	store := openTestStore(t, 2)
	request, key := testEnsureRequest(t, "peer-quota-plane", "ensure-quota-plane")
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}
	authority := newQuotaAuthorityFake(8)
	plane, err := NewRecoveredQuotaAuthorityDataPlane(
		context.Background(), store, newEchoTargetDialer(t), authority, 8, 10,
	)
	if err != nil {
		t.Fatal(err)
	}
	server, roots := newDataPlaneTestServer(t, plane)
	defer server.Close()
	client, transport := newDataPlaneClient(t, server.URL, deviceTestCertificate(t, request, key), roots)
	defer transport.CloseIdleConnections()
	query := url.Values{"host": []string{"echo.test"}, "port": []string{"443"}}
	response, err := client.Post(
		server.URL+"/v1/tunnel/connect?"+query.Encode(),
		"application/octet-stream",
		bytes.NewReader([]byte("data")),
	)
	if err != nil {
		t.Fatal(err)
	}
	body, bodyErr := io.ReadAll(response.Body)
	response.Body.Close()
	if bodyErr != nil {
		t.Fatal(bodyErr)
	}
	if response.StatusCode != http.StatusOK || string(body) != "data" {
		t.Fatalf("first stream status=%d body=%q; want 200 and echoed data", response.StatusCode, body)
	}
	if authority.used != 8 || authority.reserved != 0 {
		t.Fatalf("first stream was not fully settled: used=%d reserved=%d", authority.used, authority.reserved)
	}

	response, err = client.Post(
		server.URL+"/v1/tunnel/connect?"+query.Encode(),
		"application/octet-stream",
		bytes.NewReader([]byte("x")),
	)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != http.StatusTooManyRequests {
		t.Fatalf("second stream status=%d; want quota exhausted 429", response.StatusCode)
	}
}

func TestDataPlaneRejectsCopiedPeerIdentityWhileAndAfterOriginalIsDisconnected(t *testing.T) {
	store := openTestStore(t, 2)
	dialer := newEchoTargetDialer(t)
	plane, err := NewDataPlane(store, dialer)
	if err != nil {
		t.Fatal(err)
	}
	server, roots := newDataPlaneTestServer(t, plane)
	defer server.Close()

	firstRequest, firstKey := testEnsureRequest(t, "peer-copy-source", "ensure-copy-source")
	secondRequest, secondKey := testEnsureRequest(t, "peer-copy-target", "ensure-copy-target")
	if _, err := store.EnsurePeer(firstRequest); err != nil {
		t.Fatal(err)
	}
	if _, err := store.EnsurePeer(secondRequest); err != nil {
		t.Fatal(err)
	}
	firstClient, firstTransport := newDataPlaneClient(t, server.URL, deviceTestCertificate(t, firstRequest, firstKey), roots)
	defer firstTransport.CloseIdleConnections()

	copyIdentity := deviceIdentityFor(firstRequest)
	copiedCertificate, err := NewDeviceCertificate(copyIdentity, secondKey)
	if err != nil {
		t.Fatal(err)
	}
	secondClient, secondTransport := newDataPlaneClient(t, server.URL, copiedCertificate, roots)
	defer secondTransport.CloseIdleConnections()

	response, bodyWriter := openStreamingTunnel(t, firstClient, server.URL)
	if response.StatusCode != http.StatusOK || response.ProtoMajor != 2 {
		t.Fatalf("source tunnel status=%d HTTP/%d; want 200 over HTTP/2", response.StatusCode, response.ProtoMajor)
	}

	assertCopiedCredentialRejected(t, secondClient, server.URL)
	_ = response.Body.Close()
	_ = bodyWriter.Close()
	firstTransport.CloseIdleConnections()
	waitForSessionCount(t, store, 0)
	assertCopiedCredentialRejected(t, secondClient, server.URL)
}

func TestDataPlaneRevocationClosesExistingFlowAndReturnsFinalTraffic(t *testing.T) {
	store := openTestStore(t, 1)
	plane, err := NewDataPlane(store, newEchoTargetDialer(t))
	if err != nil {
		t.Fatal(err)
	}
	server, roots := newDataPlaneTestServer(t, plane)
	defer server.Close()

	request, privateKey := testEnsureRequest(t, "peer-data-revocation", "ensure-data-revocation")
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}
	client, transport := newDataPlaneClient(t, server.URL, deviceTestCertificate(t, request, privateKey), roots)
	defer transport.CloseIdleConnections()
	response, bodyWriter := openStreamingTunnel(t, client, server.URL)
	payload := []byte("final-accounted-test-bytes")
	if _, err := bodyWriter.Write(payload); err != nil {
		t.Fatal(err)
	}
	received := make([]byte, len(payload))
	if _, err := io.ReadFull(response.Body, received); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(received, payload) {
		t.Fatal("echo data differs from the sent payload")
	}

	revocation := RevokePeerRequest{
		NodeID: request.NodeID, PeerID: request.PeerID, RequestID: "revoke-data-revocation", Generation: request.Generation + 1,
	}
	receipt, err := plane.RevokePeer(revocation)
	if err != nil {
		t.Fatal(err)
	}
	if receipt.ConfirmationStatus != "confirmed_test" || receipt.UploadBytes < int64(len(payload)) ||
		receipt.DownloadBytes < int64(len(payload)) || receipt.NodeID != request.NodeID || receipt.PeerID != request.PeerID ||
		receipt.RequestID != revocation.RequestID {
		t.Fatalf("revocation receipt did not contain final identity/traffic: %+v", receipt)
	}
	_ = response.Body.Close()
	_ = bodyWriter.Close()
	transport.CloseIdleConnections()
	waitForSessionCount(t, store, 0)

	query := url.Values{"host": []string{"echo.test"}, "port": []string{"443"}}
	blocked, err := client.Post(server.URL+"/v1/tunnel/connect?"+query.Encode(), "application/octet-stream", bytes.NewReader([]byte("after revoke")))
	if err != nil {
		t.Fatal(err)
	}
	blocked.Body.Close()
	if blocked.StatusCode != http.StatusForbidden {
		t.Fatalf("revoked device reconnect status=%d; want %d", blocked.StatusCode, http.StatusForbidden)
	}
}

func TestRevocationWaitsForTrafficAccountingAfterHTTPConnectionCloses(t *testing.T) {
	store := openTestStore(t, 1)
	plane, err := NewDataPlane(store, newEchoTargetDialer(t))
	if err != nil {
		t.Fatal(err)
	}
	request, _ := testEnsureRequest(t, "peer-closed-flow", "ensure-closed-flow")
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}

	connection, remote := net.Pipe()
	defer remote.Close()
	tracked := &trackedDataConnection{
		connection: connection, peerID: request.PeerID, sessionID: "", active: 1,
		done: make(chan struct{}),
	}
	tracked.streams.Add(1)
	plane.mu.Lock()
	plane.connections[connection] = tracked
	plane.activeStreams[request.PeerID] = map[*trackedDataConnection]struct{}{tracked: {}}
	plane.mu.Unlock()
	plane.ConnState(connection, http.StateClosed)

	revocation := RevokePeerRequest{
		NodeID: request.NodeID, PeerID: request.PeerID, RequestID: "revoke-closed-flow", Generation: request.Generation + 1,
	}
	type revokeResult struct {
		receipt RevocationReceipt
		err     error
	}
	result := make(chan revokeResult, 1)
	go func() {
		receipt, revokeErr := plane.RevokePeer(revocation)
		result <- revokeResult{receipt: receipt, err: revokeErr}
	}()
	waitForPeerStatus(t, store, request.PeerID, "revocation_pending")
	select {
	case <-result:
		t.Fatal("revocation returned before the active HTTP stream was accounted")
	case <-time.After(25 * time.Millisecond):
	}

	if err := store.AddTraffic(request.PeerID, 31, 47); err != nil {
		t.Fatal(err)
	}
	plane.endStream(tracked)
	revoked := <-result
	if revoked.err != nil {
		t.Fatal(revoked.err)
	}
	if revoked.receipt.UploadBytes != 31 || revoked.receipt.DownloadBytes != 47 {
		t.Fatalf("revocation omitted traffic accounted after the connection closed: %+v", revoked.receipt)
	}
}

func waitForPeerStatus(t *testing.T, store *PeerStore, peerID, expected string) {
	t.Helper()
	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		var status string
		err := store.read(func(state *agentState) error {
			status = state.Peers[peerID].Status
			return nil
		})
		if err != nil {
			t.Fatal(err)
		}
		if status == expected {
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatalf("peer %s did not reach status %s", peerID, expected)
}

func TestPublicTargetDialerRejectsPrivateAndNonWebTargets(t *testing.T) {
	dialer := NewPublicTargetDialer()
	for _, test := range []struct {
		host string
		port uint16
	}{
		{host: "127.0.0.1", port: 443},
		{host: "169.254.169.254", port: 80},
		{host: "192.0.2.1", port: 443},
		{host: "example.com", port: 22},
	} {
		if _, err := dialer.DialTarget(context.Background(), test.host, test.port); !errors.Is(err, ErrTargetDenied) {
			t.Fatalf("target %s:%d was not denied: %v", test.host, test.port, err)
		}
	}
	for _, address := range []string{"127.0.0.1", "10.0.0.1", "169.254.1.1", "100.64.0.1", "192.0.2.10", "2001:db8::1", "::1"} {
		if isPublicTargetIP(net.ParseIP(address)) {
			t.Fatalf("non-public address %q was accepted", address)
		}
	}
}

func TestDataPlaneServerUsesTLS13AndHTTP2(t *testing.T) {
	store := openTestStore(t, 1)
	plane, err := NewDataPlane(store, newEchoTargetDialer(t))
	if err != nil {
		t.Fatal(err)
	}
	serverCertificate, clientTLS, _ := createTestCertificates(t)
	certificatePath := filepath.Join(t.TempDir(), "node.crt")
	privateKeyPath := filepath.Join(filepath.Dir(certificatePath), "node.key")
	if err := os.WriteFile(certificatePath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverCertificate.Certificate[0]}), 0o600); err != nil {
		t.Fatal(err)
	}
	privateDER, err := x509.MarshalPKCS8PrivateKey(serverCertificate.PrivateKey)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(privateKeyPath, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateDER}), 0o600); err != nil {
		t.Fatal(err)
	}
	server, err := NewDataPlaneServer("127.0.0.1:9444", certificatePath, privateKeyPath, plane)
	if err != nil {
		t.Fatal(err)
	}
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	serveResult := make(chan error, 1)
	go func() { serveResult <- server.ServeTLS(listener, "", "") }()
	t.Cleanup(func() {
		_ = server.Close()
		<-serveResult
	})
	transport := &http.Transport{TLSClientConfig: clientTLS, ForceAttemptHTTP2: true}
	client := &http.Client{Transport: transport, Timeout: 5 * time.Second}
	defer transport.CloseIdleConnections()
	response, err := client.Get("https://localhost:" + strconv.Itoa(listener.Addr().(*net.TCPAddr).Port) + "/healthz")
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.ProtoMajor != 2 || response.StatusCode != http.StatusOK {
		t.Fatalf("data-plane server protocol/status HTTP/%d %d; want HTTP/2 200", response.ProtoMajor, response.StatusCode)
	}
}

func TestKMSNodeTLSIdentityServesTLS13HTTP2DataPlane(t *testing.T) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	serverCertificate, roots := nodeTLSCertificateForKey(t, key)
	publicKeyDER, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	identitySource, err := NewKMSNodeTLSIdentitySource(
		fakeKMSClient{publicKeyDER: publicKeyDER, privateKey: key},
		fakeNodeTLSCertificateProvider{identity: NodeTLSIdentity{Certificate: serverCertificate, ClientCAs: roots}},
		map[string]string{"node-kms-data-plane": "test-key"},
		time.Second,
	)
	if err != nil {
		t.Fatal(err)
	}
	identity, err := LoadNodeTLSIdentity(context.Background(), "node-kms-data-plane", identitySource)
	if err != nil {
		t.Fatal(err)
	}
	store := openTestStore(t, 1)
	plane, err := NewDataPlane(store, newEchoTargetDialer(t))
	if err != nil {
		t.Fatal(err)
	}
	serverConfig, err := NewDataPlaneServerWithNodeTLSIdentity("127.0.0.1:0", identity.Certificate, plane)
	if err != nil {
		t.Fatal(err)
	}
	server := httptest.NewUnstartedServer(serverConfig.Handler)
	server.EnableHTTP2 = true
	server.Config.ConnContext = serverConfig.ConnContext
	server.Config.ConnState = serverConfig.ConnState
	server.TLS = serverConfig.TLSConfig
	server.StartTLS()
	defer server.Close()

	clientCertificate, _, _ := createTestCertificates(t)
	clientTLS := &tls.Config{
		MinVersion: tls.VersionTLS13, RootCAs: roots,
		Certificates: []tls.Certificate{clientCertificate}, ServerName: "localhost",
	}
	client := &http.Client{Transport: &http.Transport{TLSClientConfig: clientTLS, ForceAttemptHTTP2: true}}
	defer client.CloseIdleConnections()
	response, err := client.Get(server.URL + "/healthz")
	if err != nil {
		t.Fatalf("HTTP/2 request through KMS-backed NodeTLSIdentity failed: %v", err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK || response.ProtoMajor != 2 {
		t.Fatalf("data-plane response status/protocol=%d/HTTP%d; want 200/HTTP2", response.StatusCode, response.ProtoMajor)
	}
}

func assertCopiedCredentialRejected(t *testing.T, client *http.Client, serverURL string) {
	t.Helper()
	query := url.Values{"host": []string{"echo.test"}, "port": []string{"443"}}
	response, err := client.Post(serverURL+"/v1/tunnel/connect?"+query.Encode(), "application/octet-stream", bytes.NewReader([]byte("copied")))
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != http.StatusForbidden {
		t.Fatalf("copied peer proof status=%d; want %d", response.StatusCode, http.StatusForbidden)
	}
}

func openStreamingTunnel(t *testing.T, client *http.Client, serverURL string) (*http.Response, *io.PipeWriter) {
	t.Helper()
	reader, writer := io.Pipe()
	query := url.Values{"host": []string{"echo.test"}, "port": []string{"443"}}
	request, err := http.NewRequest(http.MethodPost, serverURL+"/v1/tunnel/connect?"+query.Encode(), reader)
	if err != nil {
		t.Fatal(err)
	}
	request.ContentLength = -1
	responseChannel := make(chan struct {
		response *http.Response
		err      error
	}, 1)
	go func() {
		response, err := client.Do(request)
		responseChannel <- struct {
			response *http.Response
			err      error
		}{response, err}
	}()
	select {
	case result := <-responseChannel:
		if result.err != nil {
			writer.Close()
			t.Fatal(result.err)
		}
		return result.response, writer
	case <-time.After(5 * time.Second):
		writer.Close()
		t.Fatal("timed out waiting for the tunneled response headers")
		return nil, nil
	}
}

func deviceTestCertificate(t *testing.T, request EnsurePeerRequest, key *ecdsa.PrivateKey) tls.Certificate {
	t.Helper()
	certificate, err := NewDeviceCertificate(deviceIdentityFor(request), key)
	if err != nil {
		t.Fatal(err)
	}
	return certificate
}

func deviceIdentityFor(request EnsurePeerRequest) DeviceCertificateIdentity {
	return DeviceCertificateIdentity{
		NodeID: request.NodeID, PeerID: request.PeerID, DeviceID: request.DeviceID,
		SubscriptionID: request.SubscriptionID, SubscriptionGeneration: request.SubscriptionGeneration,
		PeerGeneration: request.PeerGeneration, DeviceGeneration: request.DeviceGeneration,
	}
}

func newDataPlaneClient(t *testing.T, serverURL string, certificate tls.Certificate, roots *x509.CertPool) (*http.Client, *http.Transport) {
	t.Helper()
	transport := &http.Transport{
		TLSClientConfig:   &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: roots, Certificates: []tls.Certificate{certificate}},
		ForceAttemptHTTP2: true, MaxConnsPerHost: 1,
	}
	t.Cleanup(transport.CloseIdleConnections)
	return &http.Client{Transport: transport}, transport
}

func newDataPlaneTestServer(t *testing.T, plane *DataPlane) (*httptest.Server, *x509.CertPool) {
	t.Helper()
	serverCertificate, _, caPEM := createTestCertificates(t)
	roots := testRootPool(t, caPEM)
	server := httptest.NewUnstartedServer(plane.Handler())
	server.EnableHTTP2 = true
	server.Config.ConnContext = plane.ConnContext
	server.Config.ConnState = plane.ConnState
	server.TLS = &tls.Config{
		MinVersion: tls.VersionTLS13, Certificates: []tls.Certificate{serverCertificate},
		ClientAuth: tls.RequireAnyClientCert,
	}
	server.StartTLS()
	return server, roots
}

type echoTargetDialer struct {
	address string
}

func newEchoTargetDialer(t *testing.T) echoTargetDialer {
	t.Helper()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = listener.Close() })
	go func() {
		for {
			connection, err := listener.Accept()
			if err != nil {
				return
			}
			go func() {
				defer connection.Close()
				_, _ = io.Copy(connection, connection)
			}()
		}
	}()
	return echoTargetDialer{address: listener.Addr().String()}
}

func (dialer echoTargetDialer) DialTarget(ctx context.Context, _ string, _ uint16) (net.Conn, error) {
	return (&net.Dialer{}).DialContext(ctx, "tcp", dialer.address)
}

func waitForSessionCount(t *testing.T, store *PeerStore, expected int) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		state, err := loadState(store.path)
		if err != nil {
			t.Fatal(err)
		}
		if len(state.Sessions) == expected {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	state, err := loadState(store.path)
	if err != nil {
		t.Fatal(err)
	}
	t.Fatalf("session count=%d; want %d", len(state.Sessions), expected)
}
