package agent

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"errors"
	"math/big"
	"strings"
	"testing"
	"time"
)

func TestNewRuntimeDataPlaneEnablesQuotaOnlyForTrustedProductionConfig(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	configJSON := productionRendererRuntimeConfigJSON(RuntimeProfileMihomoBridge,
		"https://bridge.example.invalid:9444", string(caPEM))
	config, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(configJSON))
	if err != nil {
		t.Fatal(err)
	}
	store, err := OpenPeerStore(t.TempDir()+"/production-agent.json", config.Node.ID, 2)
	if err != nil {
		t.Fatal(err)
	}
	identity := testQuotaNodeIdentity(t)
	if err := config.Validate(); err != nil {
		t.Fatalf("runtime config validation error = %v", err)
	}
	if err := validateNodeTLSIdentity(identity); err != nil {
		t.Fatalf("node TLS identity validation error = %v", err)
	}

	dataPlane, err := NewRuntimeDataPlane(context.Background(), store, NewPublicTargetDialer(), identity, config)
	if err != nil {
		t.Fatalf("NewRuntimeDataPlane error = %v", err)
	}
	if dataPlane.quotaProvider == nil {
		t.Fatal("production-shaped runtime returned a data plane without quota enforcement")
	}
}

func TestNewRuntimeDataPlaneFailsClosedForInvalidQuotaClientIdentity(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	configJSON := productionRendererRuntimeConfigJSON(RuntimeProfileMihomoBridge,
		"https://bridge.example.invalid:9444", string(caPEM))
	config, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(configJSON))
	if err != nil {
		t.Fatal(err)
	}
	store, err := OpenPeerStore(t.TempDir()+"/production-agent.json", config.Node.ID, 2)
	if err != nil {
		t.Fatal(err)
	}
	serverCertificate, _, _ := createTestCertificates(t)

	if _, err := NewRuntimeDataPlane(context.Background(), store, NewPublicTargetDialer(),
		NodeTLSIdentity{Certificate: serverCertificate}, config); !errors.Is(err, ErrInvalidNodeRuntimeConfig) {
		t.Fatalf("NewRuntimeDataPlane error = %v; want invalid client-auth identity", err)
	}
}

func TestNewRuntimeDataPlaneKeepsIsolatedTestWithoutOnlineQuota(t *testing.T) {
	config, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(
		`{"schema_version":"xlb-node-runtime/v1","environment":"isolated-test","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"test-file-only"},"profile_renderer":{"status":"unavailable"}}`,
	))
	if err != nil {
		t.Fatal(err)
	}
	store, err := OpenPeerStore(t.TempDir()+"/test-agent.json", config.Node.ID, 2)
	if err != nil {
		t.Fatal(err)
	}
	dataPlane, err := NewRuntimeDataPlane(context.Background(), store, NewPublicTargetDialer(), NodeTLSIdentity{}, config)
	if err != nil {
		t.Fatalf("NewRuntimeDataPlane error = %v", err)
	}
	if dataPlane.quotaProvider != nil {
		t.Fatal("isolated-test runtime unexpectedly enabled online quota authority")
	}
}

func testQuotaNodeIdentity(t *testing.T) NodeTLSIdentity {
	t.Helper()
	privateKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	caTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(300), Subject: pkix.Name{CommonName: "quota node test CA"},
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
	leafTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(301), Subject: pkix.Name{CommonName: "quota-test-node"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature,
		ExtKeyUsage: []x509.ExtKeyUsage{
			x509.ExtKeyUsageServerAuth, x509.ExtKeyUsageClientAuth,
		},
	}
	leafDER, err := x509.CreateCertificate(rand.Reader, leafTemplate, caCertificate, &privateKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	leaf, err := x509.ParseCertificate(leafDER)
	if err != nil {
		t.Fatal(err)
	}
	roots := x509.NewCertPool()
	roots.AddCert(caCertificate)
	return NodeTLSIdentity{
		Certificate: tls.Certificate{
			Certificate: [][]byte{leafDER, caDER},
			PrivateKey:  privateKey,
			Leaf:        leaf,
		},
		ClientCAs: roots,
	}
}
