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
	"net/http/httptest"
	"path/filepath"
	"testing"
	"time"

	agent "xlb/node-agent"
)

func TestSOCKSBridgeUsesDeviceProofAndForwardsTCPOverHTTP2(t *testing.T) {
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
	server := httptest.NewUnstartedServer(plane.Handler())
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
	proxy, err := New(Config{
		ListenAddress: "127.0.0.1:0", RemoteURL: server.URL, ServerRoots: roots, DeviceCertificate: deviceCertificate,
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
		case err := <-serveResult:
			if err != nil {
				t.Errorf("bridge server returned an error: %v", err)
			}
		case <-time.After(time.Second):
			t.Error("bridge server did not stop")
		}
	})

	local, err := net.DialTimeout("tcp", listener.Addr().String(), time.Second)
	if err != nil {
		t.Fatal(err)
	}
	defer local.Close()
	_ = local.SetDeadline(time.Now().Add(5 * time.Second))
	if _, err := local.Write([]byte{5, 1, 0}); err != nil {
		t.Fatal(err)
	}
	var methodReply [2]byte
	if _, err := io.ReadFull(local, methodReply[:]); err != nil || methodReply != [2]byte{5, 0} {
		t.Fatalf("SOCKS method reply=%v err=%v", methodReply, err)
	}
	host := []byte("echo.test")
	connect := append([]byte{5, 1, 0, 3, byte(len(host))}, host...)
	connect = append(connect, 1, 187)
	if _, err := local.Write(connect); err != nil {
		t.Fatal(err)
	}
	var connectReply [10]byte
	if _, err := io.ReadFull(local, connectReply[:]); err != nil {
		t.Fatal(err)
	}
	if connectReply[1] != 0 {
		t.Fatalf("SOCKS CONNECT failed with reply code %d", connectReply[1])
	}
	payload := []byte("proof-bound local bridge test")
	if _, err := local.Write(payload); err != nil {
		t.Fatal(err)
	}
	received := make([]byte, len(payload))
	if _, err := io.ReadFull(local, received); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(received, payload) {
		t.Fatalf("bridged response=%q; want %q", received, payload)
	}
	if count := binary.BigEndian.Uint16(connectReply[8:]); count != 0 {
		t.Fatalf("SOCKS bound port=%d; want zero placeholder", count)
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
