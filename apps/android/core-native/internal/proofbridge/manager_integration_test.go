package proofbridge

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/hex"
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

func TestManagerAuthenticatesLocalSOCKSAndForwardsThroughAuthorizedNodePeer(t *testing.T) {
	store, err := agent.OpenPeerStore(filepath.Join(t.TempDir(), "agent-state.json"), "node-proofbridge-test", 2)
	if err != nil {
		t.Fatal(err)
	}
	deviceKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	publicKeyDER, err := x509.MarshalPKIXPublicKey(&deviceKey.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	publicKeyFingerprint := sha256.Sum256(publicKeyDER)
	peerRequest := agent.EnsurePeerRequest{
		NodeID: "node-proofbridge-test", PeerID: "peer-proofbridge-test", EntitlementID: "entitlement-proofbridge-test",
		RequestID: "ensure-proofbridge-test", Generation: 1,
		DeviceID: "device-proofbridge-test", DeviceName: "Isolated proof bridge test",
		DevicePublicKey:         base64.RawURLEncoding.EncodeToString(publicKeyDER),
		DeviceKeyFingerprint:    hex.EncodeToString(publicKeyFingerprint[:]),
		DeviceBindingGeneration: 2, PeerGeneration: 1, DeviceGeneration: 2,
		SubscriptionID: "subscription-proofbridge-test", SubscriptionToken: "test-token",
		SubscriptionGeneration: 1, ExpiresAt: time.Now().Add(time.Hour).UTC(), Credential: "test-credential",
	}
	if _, err := store.EnsurePeer(peerRequest); err != nil {
		t.Fatal(err)
	}
	dataPlane, err := agent.NewDataPlane(store, echoTargetDialer{})
	if err != nil {
		t.Fatal(err)
	}
	serverCertificate, trustedCAPEM := proofBridgeServerCertificate(t)
	server := httptest.NewUnstartedServer(dataPlane.Handler())
	server.EnableHTTP2 = true
	server.Config.ConnContext = dataPlane.ConnContext
	server.Config.ConnState = dataPlane.ConnState
	server.TLS = &tls.Config{
		MinVersion: tls.VersionTLS13, Certificates: []tls.Certificate{serverCertificate},
		ClientAuth: tls.RequireAnyClientCert,
	}
	server.StartTLS()
	defer server.Close()
	serverURL := "https://" + server.Listener.Addr().String()

	deviceCertificate, err := agent.NewDeviceCertificate(agent.DeviceCertificateIdentity{
		NodeID: peerRequest.NodeID, PeerID: peerRequest.PeerID, DeviceID: peerRequest.DeviceID,
		SubscriptionID: peerRequest.SubscriptionID, SubscriptionGeneration: peerRequest.SubscriptionGeneration,
		PeerGeneration: peerRequest.PeerGeneration, DeviceGeneration: peerRequest.DeviceGeneration,
	}, deviceKey)
	if err != nil {
		t.Fatal(err)
	}
	manager := NewManager()
	localUsername := "isolated-proofbridge-user"
	localPassword := "isolated-proofbridge-password"
	if err := manager.Start("127.0.0.1:0", serverURL, trustedCAPEM,
		localUsername, localPassword, deviceCertificate); err != nil {
		t.Fatalf("start isolated proof bridge: %v", err)
	}
	t.Cleanup(manager.Stop)

	connection, err := net.DialTimeout("tcp", manager.Address(), time.Second)
	if err != nil {
		t.Fatalf("connect to local proof bridge: %v", err)
	}
	defer connection.Close()
	if err := connection.SetDeadline(time.Now().Add(5 * time.Second)); err != nil {
		t.Fatal(err)
	}
	if _, err := connection.Write([]byte{5, 1, 2}); err != nil {
		t.Fatal(err)
	}
	var methodReply [2]byte
	if _, err := io.ReadFull(connection, methodReply[:]); err != nil || methodReply != [2]byte{5, 2} {
		t.Fatalf("SOCKS method reply=%v err=%v", methodReply, err)
	}
	if _, err := connection.Write(socksUserPasswordRequest(localUsername, localPassword)); err != nil {
		t.Fatal(err)
	}
	var authenticationReply [2]byte
	if _, err := io.ReadFull(connection, authenticationReply[:]); err != nil || authenticationReply != [2]byte{1, 0} {
		t.Fatalf("SOCKS authentication reply=%v err=%v", authenticationReply, err)
	}
	if _, err := connection.Write([]byte{5, 1, 0, 3, 4, 'e', 'c', 'h', 'o', 1, 187}); err != nil {
		t.Fatal(err)
	}
	var connectReply [10]byte
	if _, err := io.ReadFull(connection, connectReply[:]); err != nil || connectReply[1] != 0 {
		t.Fatalf("SOCKS connect reply=%v err=%v", connectReply, err)
	}
	payload := []byte("authorized device proof bridge payload")
	if _, err := connection.Write(payload); err != nil {
		t.Fatal(err)
	}
	response := make([]byte, len(payload))
	if _, err := io.ReadFull(connection, response); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(response, payload) {
		t.Fatalf("bridged response=%q, want %q", response, payload)
	}
	if !manager.IsRunning() {
		t.Fatal("proof bridge stopped after a successful authorized data-plane stream")
	}
	manager.Stop()
	if manager.IsRunning() {
		t.Fatal("proof bridge remained active after stop")
	}
	if _, err := net.DialTimeout("tcp", manager.Address(), 100*time.Millisecond); err == nil {
		t.Fatal("proof bridge listener remained available after stop")
	}
}

type echoTargetDialer struct{}

func (echoTargetDialer) DialTarget(_ context.Context, _ string, _ uint16) (net.Conn, error) {
	client, server := net.Pipe()
	go func() {
		defer server.Close()
		_, _ = io.Copy(server, server)
	}()
	return client, nil
}

func socksUserPasswordRequest(username, password string) []byte {
	request := []byte{1, byte(len(username))}
	request = append(request, username...)
	request = append(request, byte(len(password)))
	return append(request, password...)
}

func proofBridgeServerCertificate(t *testing.T) (tls.Certificate, string) {
	t.Helper()
	rootKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	rootTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "XLB isolated proof bridge test CA"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), IsCA: true,
		BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
	}
	rootDER, err := x509.CreateCertificate(rand.Reader, rootTemplate, rootTemplate, &rootKey.PublicKey, rootKey)
	if err != nil {
		t.Fatal(err)
	}
	serverKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	serverTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: "XLB isolated proof bridge"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		IPAddresses: []net.IP{net.ParseIP("127.0.0.1")},
	}
	serverDER, err := x509.CreateCertificate(rand.Reader, serverTemplate, rootTemplate, &serverKey.PublicKey, rootKey)
	if err != nil {
		t.Fatal(err)
	}
	serverCertificatePEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverDER})
	privateKeyDER, err := x509.MarshalPKCS8PrivateKey(serverKey)
	if err != nil {
		t.Fatal(err)
	}
	privateKeyPEM := pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateKeyDER})
	certificate, err := tls.X509KeyPair(serverCertificatePEM, privateKeyPEM)
	if err != nil {
		t.Fatal(err)
	}
	rootPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: rootDER})
	return certificate, string(rootPEM)
}
