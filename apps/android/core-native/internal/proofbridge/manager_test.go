package proofbridge

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"net"
	"testing"
	"time"

	agent "xlb/node-agent"
)

func TestManagerRequiresDeviceCertificateAndTrustedNodeCA(t *testing.T) {
	manager := NewManager()
	certificate := testDeviceCertificate(t)
	if err := manager.Start("127.0.0.1:0", "https://node.test", testCARoot(t), "", "", certificate); err == nil {
		t.Fatal("expected proof bridge without local SOCKS credentials to fail")
	}

	if err := manager.Start("127.0.0.1:0", "https://node.test", testCARoot(t), "test-user", "test-password", tls.Certificate{}); err == nil {
		t.Fatal("expected bridge without a device certificate to fail")
	}
	if manager.IsRunning() {
		t.Fatal("bridge became active without a device certificate")
	}
	if err := manager.Start("127.0.0.1:0", "https://node.test", "not a PEM certificate", "test-user", "test-password", certificate); err == nil {
		t.Fatal("expected an invalid CA bundle to fail")
	}
	if manager.IsRunning() {
		t.Fatal("bridge became active with an invalid CA bundle")
	}
}

func TestManagerServesSOCKSOnlyOnLoopbackAndStopsListener(t *testing.T) {
	manager := NewManager()
	certificate := testDeviceCertificate(t)
	if err := manager.Start("0.0.0.0:17890", "https://node.test", testCARoot(t), "test-user", "test-password", certificate); err == nil {
		t.Fatal("expected non-loopback listener to fail closed")
	}

	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	address := listener.Addr().String()
	_ = listener.Close()
	if err := manager.Start(address, "https://node.test", testCARoot(t), "test-user", "test-password", certificate); err != nil {
		t.Fatalf("start loopback proof bridge: %v", err)
	}
	if !manager.IsRunning() {
		t.Fatal("loopback proof bridge was not reported as running")
	}
	operationRan := false
	if err := manager.WithActiveCredentials("wrong-user", "test-password", func() error {
		operationRan = true
		return nil
	}); err == nil || operationRan {
		t.Fatal("wrong local credentials authorized member profile loading")
	}
	if err := manager.WithActiveCredentials("test-user", "test-password", func() error {
		operationRan = true
		return nil
	}); err != nil || !operationRan {
		t.Fatalf("active local credentials were rejected: %v", err)
	}
	unauthenticated, err := net.DialTimeout("tcp", manager.Address(), time.Second)
	if err != nil {
		t.Fatalf("connect without local credentials: %v", err)
	}
	if _, err := unauthenticated.Write([]byte{5, 1, 0}); err != nil {
		t.Fatal(err)
	}
	unauthenticatedReply := make([]byte, 2)
	if _, err := unauthenticated.Read(unauthenticatedReply); err != nil ||
		unauthenticatedReply[0] != 5 || unauthenticatedReply[1] != 0xff {
		t.Fatalf("unauthenticated SOCKS method reply=%v err=%v", unauthenticatedReply, err)
	}
	_ = unauthenticated.Close()

	connection, err := net.DialTimeout("tcp", manager.Address(), time.Second)
	if err != nil {
		t.Fatalf("connect to loopback listener: %v", err)
	}
	if _, err := connection.Write([]byte{5, 1, 2}); err != nil {
		t.Fatal(err)
	}
	methodReply := make([]byte, 2)
	if _, err := connection.Read(methodReply); err != nil || methodReply[0] != 5 || methodReply[1] != 2 {
		t.Fatalf("SOCKS greeting reply=%v err=%v", methodReply, err)
	}
	if _, err := connection.Write([]byte{1, 9, 't', 'e', 's', 't', '-', 'u', 's', 'e', 'r', 13,
		't', 'e', 's', 't', '-', 'p', 'a', 's', 's', 'w', 'o', 'r', 'd'}); err != nil {
		t.Fatal(err)
	}
	authReply := make([]byte, 2)
	if _, err := connection.Read(authReply); err != nil || authReply[0] != 1 || authReply[1] != 0 {
		t.Fatalf("SOCKS auth reply=%v err=%v", authReply, err)
	}
	_ = connection.Close()

	manager.Stop()
	if manager.IsRunning() {
		t.Fatal("proof bridge remained active after stop")
	}
	if err := manager.WithActiveCredentials("test-user", "test-password", func() error { return nil }); err == nil {
		t.Fatal("bridge credentials remained valid after stop")
	}
	if _, err := net.DialTimeout("tcp", address, 100*time.Millisecond); err == nil {
		t.Fatal("loopback listener remained available after stop")
	}
}

func TestManagerRejectsNonHTTPSNodeEndpoint(t *testing.T) {
	manager := NewManager()
	if err := manager.Start("127.0.0.1:0", "http://node.test", testCARoot(t), "test-user", "test-password", testDeviceCertificate(t)); err == nil {
		t.Fatal("expected non-HTTPS node endpoint to fail")
	}
}

func TestManagerRejectsPathAndInvalidPortInNodeEndpoint(t *testing.T) {
	for _, endpoint := range []string{
		"https://node.test/v1/member-bridge",
		"https://node.test:0",
		"https://node.test:65536",
	} {
		t.Run(endpoint, func(t *testing.T) {
			manager := NewManager()
			if err := manager.Start("127.0.0.1:0", endpoint, testCARoot(t), "test-user", "test-password", testDeviceCertificate(t)); err == nil {
				t.Fatal("expected invalid data-plane origin to fail")
			}
		})
	}
}

func testDeviceCertificate(t *testing.T) tls.Certificate {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	certificate, err := agent.NewDeviceCertificateWithLifetime(agent.DeviceCertificateIdentity{
		NodeID: "node-test", PeerID: "peer-test", DeviceID: "device-test", PeerGeneration: 1, DeviceGeneration: 1,
	}, key, time.Minute)
	if err != nil {
		t.Fatal(err)
	}
	return certificate
}

func testCARoot(t *testing.T) string {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	rootTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "XLB Android bridge test CA"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Minute), IsCA: true,
		BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign,
	}
	rootDER, err := x509.CreateCertificate(rand.Reader, rootTemplate, rootTemplate, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: rootDER}))
}
