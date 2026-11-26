package agent

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestLiveKMSNodeTLSIdentityCompletesTLS13Handshake(t *testing.T) {
	keyARN := os.Getenv("XLB_LIVE_KMS_KEY_ARN")
	if keyARN == "" {
		t.Skip("set XLB_LIVE_KMS_KEY_ARN to run the live AWS KMS acceptance")
	}
	region := os.Getenv("XLB_LIVE_KMS_REGION")
	if region == "" {
		t.Fatal("XLB_LIVE_KMS_REGION is required when live KMS acceptance is enabled")
	}

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	client, err := NewAWSKMSAsymmetricKeyClient(ctx, region)
	if err != nil {
		t.Fatalf("create AWS KMS client: %v", err)
	}
	publicKey, err := client.GetPublicKey(ctx, keyARN)
	if err != nil {
		t.Fatalf("load live KMS public key: %v", err)
	}
	if !supportsKMSP256Signing(publicKey) {
		t.Fatal("live KMS key does not support P-256 signing")
	}

	parsedPublicKey, err := x509.ParsePKIXPublicKey(publicKey.DER)
	if err != nil {
		t.Fatalf("parse live KMS public key: %v", err)
	}
	serverPublicKey, ok := parsedPublicKey.(*ecdsa.PublicKey)
	if !ok {
		t.Fatalf("live KMS public key type is %T, want ECDSA", parsedPublicKey)
	}

	certificatePEM, caPEM, trustedRoots := createLiveKMSNodeCertificate(t, serverPublicKey)
	certificatePath := filepath.Join(t.TempDir(), "node-certificate.pem")
	clientCAPath := filepath.Join(t.TempDir(), "client-ca.pem")
	if err := os.WriteFile(certificatePath, certificatePEM, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(clientCAPath, caPEM, 0o600); err != nil {
		t.Fatal(err)
	}

	certificateProvider, err := NewPEMNodeTLSCertificateProvider(map[string]NodeTLSCertificateFiles{
		"xlb-live-kms-test": {
			CertificateChainPath: certificatePath,
			ClientCAPEMPath:      clientCAPath,
		},
	})
	if err != nil {
		t.Fatalf("create certificate provider: %v", err)
	}
	identitySource, err := NewKMSNodeTLSIdentitySource(
		client,
		certificateProvider,
		map[string]string{"xlb-live-kms-test": keyARN},
		15*time.Second,
	)
	if err != nil {
		t.Fatalf("create KMS node TLS identity source: %v", err)
	}
	identity, err := LoadNodeTLSIdentity(ctx, "xlb-live-kms-test", identitySource)
	if err != nil {
		t.Fatalf("load KMS-backed node TLS identity: %v", err)
	}

	server := httptest.NewUnstartedServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		writer.WriteHeader(http.StatusNoContent)
	}))
	server.TLS = &tls.Config{
		Certificates: []tls.Certificate{identity.Certificate},
		MinVersion:   tls.VersionTLS13,
	}
	server.StartTLS()
	defer server.Close()

	clientTransport := &http.Transport{TLSClientConfig: &tls.Config{
		MinVersion: tls.VersionTLS13,
		RootCAs:    trustedRoots,
		ServerName: "localhost",
	}}
	defer clientTransport.CloseIdleConnections()
	response, err := (&http.Client{Transport: clientTransport}).Get(server.URL)
	if err != nil {
		t.Fatalf("complete TLS 1.3 handshake with live KMS signer: %v", err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusNoContent {
		t.Fatalf("TLS 1.3 KMS-backed endpoint returned HTTP %d", response.StatusCode)
	}
	if response.TLS == nil || response.TLS.Version != tls.VersionTLS13 {
		t.Fatal("endpoint did not negotiate TLS 1.3")
	}
}

func createLiveKMSNodeCertificate(
	t *testing.T,
	serverPublicKey *ecdsa.PublicKey,
) ([]byte, []byte, *x509.CertPool) {
	t.Helper()

	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatalf("generate temporary test CA key: %v", err)
	}
	serial := big.NewInt(time.Now().UnixNano())
	caTemplate := &x509.Certificate{
		SerialNumber:          serial,
		Subject:               pkix.Name{CommonName: "XLB temporary live KMS test CA"},
		NotBefore:             time.Now().Add(-time.Minute),
		NotAfter:              time.Now().Add(time.Hour),
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature,
		BasicConstraintsValid: true,
		IsCA:                  true,
	}
	caDER, err := x509.CreateCertificate(rand.Reader, caTemplate, caTemplate, &caKey.PublicKey, caKey)
	if err != nil {
		t.Fatalf("create temporary test CA certificate: %v", err)
	}
	caCertificate, err := x509.ParseCertificate(caDER)
	if err != nil {
		t.Fatalf("parse temporary test CA certificate: %v", err)
	}

	serverTemplate := &x509.Certificate{
		SerialNumber: serial.Add(serial, big.NewInt(1)),
		Subject:      pkix.Name{CommonName: "localhost"},
		DNSNames:     []string{"localhost"},
		NotBefore:    time.Now().Add(-time.Minute),
		NotAfter:     time.Now().Add(time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth, x509.ExtKeyUsageClientAuth},
	}
	serverDER, err := x509.CreateCertificate(rand.Reader, serverTemplate, caCertificate, serverPublicKey, caKey)
	if err != nil {
		t.Fatalf("create temporary KMS-backed node certificate: %v", err)
	}

	caPool := x509.NewCertPool()
	caPool.AddCert(caCertificate)
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverDER}),
		pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: caDER}), caPool
}
