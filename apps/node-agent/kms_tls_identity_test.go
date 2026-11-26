package agent

import (
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/asn1"
	"encoding/pem"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"
)

type fakeKMSClient struct {
	publicKeyDER []byte
	metadata     *KMSPublicKey
	privateKey   *ecdsa.PrivateKey
	signature    []byte
	getPublicErr error
	signErr      error
}

func (client fakeKMSClient) GetPublicKey(context.Context, string) (KMSPublicKey, error) {
	if client.metadata != nil {
		return *client.metadata, client.getPublicErr
	}
	return KMSPublicKey{
		DER: client.publicKeyDER, KeySpec: "ECC_NIST_P256", KeyUsage: "SIGN_VERIFY",
		SigningAlgorithms: []string{"ECDSA_SHA_256"},
	}, client.getPublicErr
}

func (client fakeKMSClient) Sign(_ context.Context, _ string, digest []byte) ([]byte, error) {
	if client.signErr != nil {
		return nil, client.signErr
	}
	if client.signature != nil {
		return client.signature, nil
	}
	r, s, err := ecdsa.Sign(rand.Reader, client.privateKey, digest)
	if err != nil {
		return nil, err
	}
	return asn1.Marshal(ecdsaSignature{R: r, S: s})
}

type fakeNodeTLSCertificateProvider struct {
	identity NodeTLSIdentity
	err      error
}

func (provider fakeNodeTLSCertificateProvider) LoadNodeTLSCertificate(
	context.Context,
	string,
) (NodeTLSIdentity, error) {
	return provider.identity, provider.err
}

func TestKMSNodeTLSIdentitySourceLoadsMatchingP256Signer(t *testing.T) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	certificate, roots := nodeTLSCertificateForKey(t, key)
	publicKeyDER, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	source, err := NewKMSNodeTLSIdentitySource(
		fakeKMSClient{publicKeyDER: publicKeyDER, privateKey: key},
		fakeNodeTLSCertificateProvider{identity: NodeTLSIdentity{Certificate: certificate, ClientCAs: roots}},
		map[string]string{"node-kms-test": "arn:aws:kms:us-west-2:123456789012:key/test-key"},
		time.Second,
	)
	if err != nil {
		t.Fatal(err)
	}

	identity, err := LoadNodeTLSIdentity(context.Background(), "node-kms-test", source)
	if err != nil {
		t.Fatalf("LoadNodeTLSIdentity error = %v", err)
	}
	signer, ok := identity.Certificate.PrivateKey.(crypto.Signer)
	if !ok {
		t.Fatal("loaded identity does not expose a crypto.Signer")
	}
	if _, ok := signer.(KMSECDSASigner); !ok {
		t.Fatalf("loaded signer has type %T; want KMSECDSASigner", signer)
	}

	digest := sha256.Sum256([]byte("node identity signer test"))
	signature, err := signer.Sign(rand.Reader, digest[:], crypto.SHA256)
	if err != nil {
		t.Fatalf("KMS signer Sign error = %v", err)
	}
	if !ecdsa.VerifyASN1(&key.PublicKey, digest[:], signature) {
		t.Fatal("KMS signer returned a signature that does not match its public key")
	}
}

func TestKMSECDSASignerRejectsUnsupportedDigestAndMalformedSignature(t *testing.T) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	publicKeyDER, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	signer, err := NewKMSECDSASigner(fakeKMSClient{
		publicKeyDER: publicKeyDER,
		privateKey:   key,
		signature:    []byte("malformed"),
	}, "test-key", publicKeyDER, time.Second)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := signer.Sign(rand.Reader, make([]byte, sha256.Size), crypto.SHA384); err == nil {
		t.Fatal("signer accepted a digest algorithm other than SHA-256")
	}
	if _, err := signer.Sign(rand.Reader, make([]byte, sha256.Size), crypto.SHA256); err == nil {
		t.Fatal("signer accepted malformed ASN.1 returned by the KMS API")
	}
}

func TestKMSNodeTLSIdentitySourceRejectsNonP256AndMismatchedCertificate(t *testing.T) {
	p256Key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	nonP256Key, err := ecdsa.GenerateKey(elliptic.P384(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	certificate, roots := nodeTLSCertificateForKey(t, p256Key)
	nonP256PublicKeyDER, err := x509.MarshalPKIXPublicKey(&nonP256Key.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	nonP256Source, err := NewKMSNodeTLSIdentitySource(
		fakeKMSClient{publicKeyDER: nonP256PublicKeyDER, privateKey: nonP256Key},
		fakeNodeTLSCertificateProvider{identity: NodeTLSIdentity{Certificate: certificate, ClientCAs: roots}},
		map[string]string{"node-kms-test": "test-key"},
		time.Second,
	)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := nonP256Source.LoadNodeTLSIdentity(context.Background(), "node-kms-test"); err == nil {
		t.Fatal("identity source loaded a non-P256 KMS key")
	}

	otherP256Key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	otherP256PublicKeyDER, err := x509.MarshalPKIXPublicKey(&otherP256Key.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	source, err := NewKMSNodeTLSIdentitySource(
		fakeKMSClient{publicKeyDER: otherP256PublicKeyDER, privateKey: otherP256Key},
		fakeNodeTLSCertificateProvider{identity: NodeTLSIdentity{Certificate: certificate, ClientCAs: roots}},
		map[string]string{"node-kms-test": "test-key"},
		time.Second,
	)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := source.LoadNodeTLSIdentity(context.Background(), "node-kms-test"); err == nil {
		t.Fatal("identity source accepted a certificate whose public key differs from KMS")
	}
	if _, err := source.LoadNodeTLSIdentity(context.Background(), "node-kms-other"); err == nil {
		t.Fatal("identity source accepted an unconfigured node ID")
	}
}

func TestKMSNodeTLSIdentitySourceRejectsUnsupportedKMSKeyMetadata(t *testing.T) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	certificate, roots := nodeTLSCertificateForKey(t, key)
	publicKeyDER, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	client := fakeKMSClient{publicKeyDER: publicKeyDER, privateKey: key}
	source, err := NewKMSNodeTLSIdentitySource(
		client,
		fakeNodeTLSCertificateProvider{identity: NodeTLSIdentity{Certificate: certificate, ClientCAs: roots}},
		map[string]string{"node-kms-test": "test-key"},
		time.Second,
	)
	if err != nil {
		t.Fatal(err)
	}

	client.metadata = &KMSPublicKey{
		DER: publicKeyDER, KeySpec: "RSA_2048", KeyUsage: "SIGN_VERIFY",
		SigningAlgorithms: []string{"RSASSA_PSS_SHA_256"},
	}
	source.client = client
	if _, err := source.LoadNodeTLSIdentity(context.Background(), "node-kms-test"); err == nil {
		t.Fatal("identity source accepted unsupported KMS key metadata")
	}
}

func TestPEMNodeTLSCertificateProviderLoadsPublicCertificateAndCAOnly(t *testing.T) {
	serverCertificate, _, clientCAPEM := createTestCertificates(t)
	directory := t.TempDir()
	certificateChainPath := filepath.Join(directory, "node-chain.pem")
	clientCAPath := filepath.Join(directory, "client-ca.pem")
	chainPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverCertificate.Certificate[0]})
	if len(serverCertificate.Certificate) > 1 {
		chainPEM = append(chainPEM, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverCertificate.Certificate[1]})...)
	}
	if err := os.WriteFile(certificateChainPath, chainPEM, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(clientCAPath, clientCAPEM, 0o600); err != nil {
		t.Fatal(err)
	}
	provider, err := NewPEMNodeTLSCertificateProvider(map[string]NodeTLSCertificateFiles{
		"node-kms-test": {CertificateChainPath: certificateChainPath, ClientCAPEMPath: clientCAPath},
	})
	if err != nil {
		t.Fatal(err)
	}
	identity, err := provider.LoadNodeTLSCertificate(context.Background(), "node-kms-test")
	if err != nil {
		t.Fatalf("LoadNodeTLSCertificate error = %v", err)
	}
	if len(identity.Certificate.Certificate) != len(serverCertificate.Certificate) {
		t.Fatalf("loaded certificate chain has %d certificates; want %d",
			len(identity.Certificate.Certificate), len(serverCertificate.Certificate))
	}
	if identity.Certificate.PrivateKey != nil {
		t.Fatal("certificate provider unexpectedly loaded a private key")
	}
	if identity.ClientCAs == nil || len(identity.ClientCAs.Subjects()) == 0 {
		t.Fatal("certificate provider did not load the client CA trust bundle")
	}
}

func TestPEMNodeTLSCertificateProviderRejectsSymlinkAndWritableFiles(t *testing.T) {
	serverCertificate, _, clientCAPEM := createTestCertificates(t)
	directory := t.TempDir()
	certificateChainPath := filepath.Join(directory, "node-chain.pem")
	clientCAPath := filepath.Join(directory, "client-ca.pem")
	chainPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverCertificate.Certificate[0]})
	if err := os.WriteFile(certificateChainPath, chainPEM, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(clientCAPath, clientCAPEM, 0o600); err != nil {
		t.Fatal(err)
	}
	provider, err := NewPEMNodeTLSCertificateProvider(map[string]NodeTLSCertificateFiles{
		"node-kms-test": {CertificateChainPath: certificateChainPath, ClientCAPEMPath: clientCAPath},
	})
	if err != nil {
		t.Fatal(err)
	}
	symlinkPath := filepath.Join(directory, "node-chain-link.pem")
	if err := os.Symlink(certificateChainPath, symlinkPath); err != nil {
		t.Fatal(err)
	}
	provider.files["node-kms-test"] = NodeTLSCertificateFiles{
		CertificateChainPath: symlinkPath,
		ClientCAPEMPath:      clientCAPath,
	}
	if _, err := provider.LoadNodeTLSCertificate(context.Background(), "node-kms-test"); err == nil {
		t.Fatal("certificate provider accepted a symlink")
	}
	provider.files["node-kms-test"] = NodeTLSCertificateFiles{
		CertificateChainPath: certificateChainPath,
		ClientCAPEMPath:      clientCAPath,
	}
	if err := os.Chmod(certificateChainPath, 0o666); err != nil {
		t.Fatal(err)
	}
	if _, err := provider.LoadNodeTLSCertificate(context.Background(), "node-kms-test"); err == nil {
		t.Fatal("certificate provider accepted a group/world-writable file")
	}
}

func TestPEMNodeTLSCertificateProviderRejectsNonCertificateCABlocks(t *testing.T) {
	serverCertificate, _, clientCAPEM := createTestCertificates(t)
	directory := t.TempDir()
	certificateChainPath := filepath.Join(directory, "node-chain.pem")
	clientCAPath := filepath.Join(directory, "client-ca.pem")
	chainPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverCertificate.Certificate[0]})
	if err := os.WriteFile(certificateChainPath, chainPEM, 0o600); err != nil {
		t.Fatal(err)
	}
	provider, err := NewPEMNodeTLSCertificateProvider(map[string]NodeTLSCertificateFiles{
		"node-kms-test": {CertificateChainPath: certificateChainPath, ClientCAPEMPath: clientCAPath},
	})
	if err != nil {
		t.Fatal(err)
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	privateKeyDER, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	inputs := map[string][]byte{
		"leading junk": append([]byte("unexpected leading content\n"), clientCAPEM...),
		"private key block": append(append([]byte(nil), clientCAPEM...),
			pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateKeyDER})...),
		"unknown PEM block": append(append([]byte(nil), clientCAPEM...),
			pem.EncodeToMemory(&pem.Block{Type: "XLB UNKNOWN", Bytes: []byte("data")})...),
		"trailing junk": append(append([]byte(nil), clientCAPEM...), []byte("unexpected trailing content")...),
	}
	for name, contents := range inputs {
		t.Run(name, func(t *testing.T) {
			if err := os.WriteFile(clientCAPath, contents, 0o600); err != nil {
				t.Fatal(err)
			}
			if _, err := provider.LoadNodeTLSCertificate(context.Background(), "node-kms-test"); err == nil {
				t.Fatal("certificate provider accepted a non-certificate CA bundle block")
			}
		})
	}
}

type ecdsaSignature struct {
	R *big.Int
	S *big.Int
}

func nodeTLSCertificateForKey(t *testing.T, key *ecdsa.PrivateKey) (tls.Certificate, *x509.CertPool) {
	t.Helper()
	now := time.Now()
	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	caTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(100), Subject: pkix.Name{CommonName: "KMS signer test CA"},
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
		SerialNumber: big.NewInt(101), Subject: pkix.Name{CommonName: "node-kms-test"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		DNSNames: []string{"localhost"}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")},
	}
	leafDER, err := x509.CreateCertificate(rand.Reader, leafTemplate, caCertificate, &key.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	leaf, err := x509.ParseCertificate(leafDER)
	if err != nil {
		t.Fatal(err)
	}
	roots := x509.NewCertPool()
	roots.AddCert(caCertificate)
	return tls.Certificate{Certificate: [][]byte{leafDER, caDER}, Leaf: leaf}, roots
}
