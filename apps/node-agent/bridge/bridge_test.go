package bridge

import (
	"bytes"
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/binary"
	"encoding/pem"
	"io"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"sync/atomic"
	"testing"
	"time"

	agent "xlb/node-agent"
)

func TestSOCKSBridgeKeepsSessionForActiveTunnelsAndReleasesIdleSession(t *testing.T) {
	store, err := agent.OpenPeerStore(filepath.Join(t.TempDir(), "agent-state.json"), "node-bridge-test", 2)
	if err != nil {
		t.Fatal(err)
	}
	deviceKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	publicDER, err := x509.MarshalPKIXPublicKey(&deviceKey.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	request := agent.EnsurePeerRequest{
		NodeID: "node-bridge-test", PeerID: "peer-bridge-test", EntitlementID: "entitlement-bridge-test",
		RequestID: "ensure-bridge-test", Generation: 1,
		DeviceID: "device-bridge-test", DeviceName: "Isolated bridge test",
		DevicePublicKey: base64.RawURLEncoding.EncodeToString(publicDER), DeviceKeyFingerprint: fingerprint(publicDER),
		DeviceBindingGeneration: 2, PeerGeneration: 1, DeviceGeneration: 2,
		SubscriptionID: "subscription-bridge-test", SubscriptionToken: "test-token",
		SubscriptionGeneration: 1, ExpiresAt: time.Now().Add(time.Hour).UTC(), Credential: "test-credential",
	}
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}
	dialer := newLocalEchoDialer(t)
	plane, err := agent.NewDataPlane(store, dialer)
	if err != nil {
		t.Fatal(err)
	}
	serverCertificate, roots := newBridgeTestServerCertificate(t)
	var heartbeatCount atomic.Int32
	var rejectHeartbeats atomic.Bool
	var nextHeartbeatStatus atomic.Int32
	planeHandler := plane.Handler()
	serverHandler := http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.URL.Path == "/v1/tunnel/heartbeat" {
			heartbeatCount.Add(1)
			if rejectHeartbeats.Load() {
				writer.WriteHeader(http.StatusForbidden)
				return
			}
			if status := nextHeartbeatStatus.Swap(0); status != 0 {
				writer.WriteHeader(int(status))
				return
			}
		}
		planeHandler.ServeHTTP(writer, request)
	})
	server := httptest.NewUnstartedServer(serverHandler)
	server.EnableHTTP2 = true
	server.Config.ConnContext = plane.ConnContext
	server.Config.ConnState = plane.ConnState
	server.TLS = &tls.Config{MinVersion: tls.VersionTLS13, Certificates: []tls.Certificate{serverCertificate}, ClientAuth: tls.RequireAnyClientCert}
	server.StartTLS()
	defer server.Close()

	deviceSigner := nonExportableSigner{Signer: deviceKey}
	deviceCertificate, err := agent.NewDeviceCertificate(agent.DeviceCertificateIdentity{
		NodeID: request.NodeID, PeerID: request.PeerID, DeviceID: request.DeviceID,
		SubscriptionID: request.SubscriptionID, SubscriptionGeneration: request.SubscriptionGeneration,
		PeerGeneration: request.PeerGeneration, DeviceGeneration: request.DeviceGeneration,
	}, deviceSigner)
	if err != nil {
		t.Fatal(err)
	}
	proxy, err := newWithHeartbeatInterval(Config{
		ListenAddress: "127.0.0.1:0", RemoteURL: server.URL, ServerRoots: roots, DeviceCertificate: deviceCertificate,
	}, 10*time.Millisecond)
	if err != nil {
		t.Fatal(err)
	}
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	serveResult := make(chan error, 1)
	go func() { serveResult <- proxy.Serve(listener) }()
	t.Cleanup(func() {
		_ = proxy.Close()
		select {
		case err := <-serveResult:
			if err != nil {
				t.Errorf("bridge server returned an error: %v", err)
			}
		case <-time.After(time.Second):
			t.Error("bridge server did not stop")
		}
	})

	first, connectReply := openSOCKSTunnel(t, listener.Addr().String(), "first bridge stream")
	second, _ := openSOCKSTunnel(t, listener.Addr().String(), "second bridge stream")
	if count := binary.BigEndian.Uint16(connectReply[8:]); count != 0 {
		t.Fatalf("SOCKS bound port=%d; want zero placeholder", count)
	}
	waitForHeartbeatCount(t, &heartbeatCount, 0)
	if err := first.Close(); err != nil {
		t.Fatal(err)
	}
	heartbeatsBeforeClose := heartbeatCount.Load()
	waitForHeartbeatCount(t, &heartbeatCount, heartbeatsBeforeClose)
	waitForActiveSessions(t, store, 1)

	if err := second.Close(); err != nil {
		t.Fatal(err)
	}
	waitForNoActiveSessions(t, store)
	heartbeatsWhenIdle := heartbeatCount.Load()
	time.Sleep(60 * time.Millisecond)
	if got := heartbeatCount.Load(); got != heartbeatsWhenIdle {
		t.Fatalf("bridge sent %d heartbeats after its last local tunnel closed", got-heartbeatsWhenIdle)
	}

	reconnected, _ := openSOCKSTunnel(t, listener.Addr().String(), "reconnected bridge stream")
	waitForActiveSessions(t, store, 1)
	if err := reconnected.Close(); err != nil {
		t.Fatal(err)
	}
	waitForNoActiveSessions(t, store)

	revokedTunnel, _ := openSOCKSTunnel(t, listener.Addr().String(), "revoked bridge stream")
	waitForActiveSessions(t, store, 1)
	transientFailureBaseline := heartbeatCount.Load()
	nextHeartbeatStatus.Store(http.StatusServiceUnavailable)
	waitForHeartbeatCount(t, &heartbeatCount, transientFailureBaseline)
	transientFailureObserved := heartbeatCount.Load()
	waitForHeartbeatCount(t, &heartbeatCount, transientFailureObserved)
	waitForActiveSessions(t, store, 1)
	probePayload := []byte("tunnel remains usable after transient heartbeat failure")
	if _, err := revokedTunnel.Write(probePayload); err != nil {
		t.Fatal(err)
	}
	probeReply := make([]byte, len(probePayload))
	if _, err := io.ReadFull(revokedTunnel, probeReply); err != nil || !bytes.Equal(probeReply, probePayload) {
		t.Fatalf("transient heartbeat failure interrupted active tunnel: reply=%q err=%v", probeReply, err)
	}

	rejectHeartbeats.Store(true)
	lastSuccessfulHeartbeat := heartbeatCount.Load()
	waitForHeartbeatCount(t, &heartbeatCount, lastSuccessfulHeartbeat)
	waitForNoActiveSessions(t, store)
	var probe [1]byte
	_ = revokedTunnel.SetReadDeadline(time.Now().Add(time.Second))
	if _, err := revokedTunnel.Read(probe[:]); err == nil {
		t.Fatal("bridge kept an active SOCKS tunnel after the node rejected its heartbeat")
	}
	heartbeatsAfterRejection := heartbeatCount.Load()
	time.Sleep(60 * time.Millisecond)
	if got := heartbeatCount.Load(); got != heartbeatsAfterRejection {
		t.Fatalf("bridge continued sending %d heartbeats after authorization was rejected", got-heartbeatsAfterRejection)
	}
	if err := proxy.Close(); err != nil {
		t.Fatal(err)
	}
}

func openSOCKSTunnel(t *testing.T, address, payload string) (net.Conn, [10]byte) {
	t.Helper()
	local, err := net.DialTimeout("tcp", address, time.Second)
	if err != nil {
		t.Fatal(err)
	}
	_ = local.SetDeadline(time.Now().Add(5 * time.Second))
	if _, err := local.Write([]byte{5, 1, 0}); err != nil {
		local.Close()
		t.Fatal(err)
	}
	var methodReply [2]byte
	if _, err := io.ReadFull(local, methodReply[:]); err != nil || methodReply != [2]byte{5, 0} {
		local.Close()
		t.Fatalf("SOCKS method reply=%v err=%v", methodReply, err)
	}
	host := []byte("echo.test")
	connect := append([]byte{5, 1, 0, 3, byte(len(host))}, host...)
	connect = append(connect, 1, 187)
	if _, err := local.Write(connect); err != nil {
		local.Close()
		t.Fatal(err)
	}
	var connectReply [10]byte
	if _, err := io.ReadFull(local, connectReply[:]); err != nil {
		local.Close()
		t.Fatal(err)
	}
	if connectReply[1] != 0 {
		local.Close()
		t.Fatalf("SOCKS CONNECT failed with reply code %d", connectReply[1])
	}
	payloadBytes := []byte(payload)
	if _, err := local.Write(payloadBytes); err != nil {
		local.Close()
		t.Fatal(err)
	}
	received := make([]byte, len(payloadBytes))
	if _, err := io.ReadFull(local, received); err != nil {
		local.Close()
		t.Fatal(err)
	}
	if !bytes.Equal(received, payloadBytes) {
		local.Close()
		t.Fatalf("bridged response=%q; want %q", received, payloadBytes)
	}
	_ = local.SetDeadline(time.Time{})
	return local, connectReply
}

func waitForHeartbeatCount(t *testing.T, count *atomic.Int32, before int32) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if count.Load() > before {
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatalf("bridge heartbeat count did not exceed %d", before)
}

func waitForActiveSessions(t *testing.T, store *agent.PeerStore, want int) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		snapshot, err := store.RuntimeSnapshot()
		if err != nil {
			t.Fatal(err)
		}
		if snapshot.ActiveSessions == want {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	snapshot, err := store.RuntimeSnapshot()
	if err != nil {
		t.Fatal(err)
	}
	t.Fatalf("active sessions=%d; want %d", snapshot.ActiveSessions, want)
}

func waitForNoActiveSessions(t *testing.T, store *agent.PeerStore) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		snapshot, err := store.RuntimeSnapshot()
		if err != nil {
			t.Fatal(err)
		}
		if snapshot.ActiveSessions == 0 {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	snapshot, err := store.RuntimeSnapshot()
	if err != nil {
		t.Fatal(err)
	}
	t.Fatalf("closing the bridge left %d active peer sessions", snapshot.ActiveSessions)
}

func TestSOCKSBridgeRequiresConfiguredUserPassword(t *testing.T) {
	_, roots := newBridgeTestServerCertificate(t)
	deviceKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	deviceCertificate, err := agent.NewDeviceCertificate(agent.DeviceCertificateIdentity{
		NodeID: "node-auth-test", PeerID: "peer-auth-test", DeviceID: "device-auth-test",
		PeerGeneration: 1, DeviceGeneration: 1,
	}, deviceKey)
	if err != nil {
		t.Fatal(err)
	}
	proxy, err := New(Config{
		ListenAddress: "127.0.0.1:0", RemoteURL: "https://127.0.0.1:1", ServerRoots: roots,
		DeviceCertificate: deviceCertificate, LocalUsername: "test-user", LocalPassword: "test-password",
	})
	if err != nil {
		t.Fatal(err)
	}
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	serveResult := make(chan error, 1)
	go func() { serveResult <- proxy.Serve(listener) }()
	t.Cleanup(func() {
		_ = proxy.Close()
		select {
		case <-serveResult:
		case <-time.After(time.Second):
			t.Error("bridge listener did not stop")
		}
	})

	unauthenticated := dialSOCKSAuthTest(t, listener.Addr().String(), []byte{0})
	var methodReply [2]byte
	if _, err := io.ReadFull(unauthenticated, methodReply[:]); err != nil ||
		methodReply != [2]byte{5, 0xff} {
		t.Fatalf("no-auth method reply=%v err=%v", methodReply, err)
	}
	_ = unauthenticated.Close()

	wrongPassword := dialSOCKSAuthTest(t, listener.Addr().String(), []byte{2})
	if _, err := io.ReadFull(wrongPassword, methodReply[:]); err != nil ||
		methodReply != [2]byte{5, 2} {
		t.Fatalf("username/password method reply=%v err=%v", methodReply, err)
	}
	writeSOCKSAuthTest(t, wrongPassword, "test-user", "wrong-password")
	var authReply [2]byte
	if _, err := io.ReadFull(wrongPassword, authReply[:]); err != nil || authReply != [2]byte{1, 1} {
		t.Fatalf("wrong-password reply=%v err=%v", authReply, err)
	}
	_ = wrongPassword.Close()

	valid := dialSOCKSAuthTest(t, listener.Addr().String(), []byte{2})
	if _, err := io.ReadFull(valid, methodReply[:]); err != nil ||
		methodReply != [2]byte{5, 2} {
		t.Fatalf("username/password method reply=%v err=%v", methodReply, err)
	}
	writeSOCKSAuthTest(t, valid, "test-user", "test-password")
	if _, err := io.ReadFull(valid, authReply[:]); err != nil || authReply != [2]byte{1, 0} {
		t.Fatalf("valid credentials reply=%v err=%v", authReply, err)
	}
	_ = valid.Close()
}

func dialSOCKSAuthTest(t *testing.T, address string, methods []byte) net.Conn {
	t.Helper()
	connection, err := net.DialTimeout("tcp", address, time.Second)
	if err != nil {
		t.Fatal(err)
	}
	_ = connection.SetDeadline(time.Now().Add(time.Second))
	greeting := append([]byte{5, byte(len(methods))}, methods...)
	if _, err := connection.Write(greeting); err != nil {
		t.Fatal(err)
	}
	return connection
}

func writeSOCKSAuthTest(t *testing.T, connection net.Conn, username, password string) {
	t.Helper()
	request := []byte{1, byte(len(username))}
	request = append(request, username...)
	request = append(request, byte(len(password)))
	request = append(request, password...)
	if _, err := connection.Write(request); err != nil {
		t.Fatal(err)
	}
}

type nonExportableSigner struct {
	crypto.Signer
}

func (signer nonExportableSigner) Sign(random io.Reader, digest []byte, opts crypto.SignerOpts) ([]byte, error) {
	return signer.Signer.Sign(random, digest, opts)
}

type localEchoDialer struct {
	address string
}

func newLocalEchoDialer(t *testing.T) localEchoDialer {
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
	return localEchoDialer{address: listener.Addr().String()}
}

func (dialer localEchoDialer) DialTarget(ctx context.Context, _ string, _ uint16) (net.Conn, error) {
	return (&net.Dialer{}).DialContext(ctx, "tcp", dialer.address)
}

func newBridgeTestServerCertificate(t *testing.T) (tls.Certificate, *x509.CertPool) {
	t.Helper()
	now := time.Now()
	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	caTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "bridge isolated test CA"},
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
	serverKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	serverTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: "127.0.0.1"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}, BasicConstraintsValid: true,
	}
	serverDER, err := x509.CreateCertificate(rand.Reader, serverTemplate, caCertificate, &serverKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	privateDER, err := x509.MarshalPKCS8PrivateKey(serverKey)
	if err != nil {
		t.Fatal(err)
	}
	certificate, err := tls.X509KeyPair(
		pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverDER}),
		pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateDER}),
	)
	if err != nil {
		t.Fatal(err)
	}
	certificate.Leaf, err = x509.ParseCertificate(serverDER)
	if err != nil {
		t.Fatal(err)
	}
	roots := x509.NewCertPool()
	roots.AddCert(caCertificate)
	return certificate, roots
}
