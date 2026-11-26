package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"
)

const testKMSKeyARN = "arn:aws:kms:us-west-2:289069331089:key/dd29fe54-38e5-4094-8dde-0c1a1cd8c872"

func TestWriteCertificateBundleMatchesKMSPublicKeyAndSANDomains(t *testing.T) {
	nodeKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	nodePublicDER, err := x509.MarshalPKIXPublicKey(&nodeKey.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	outputDirectory := t.TempDir()
	if err := os.Chmod(outputDirectory, 0o700); err != nil {
		t.Fatal(err)
	}
	nodePublicIP := net.ParseIP("54.187.217.237").To4()
	if err := writeCertificateBundle(outputDirectory, "xlb-lab-us-test", testKMSKeyARN,
		nodePublicIP, nodePublicDER, &nodeKey.PublicKey); err != nil {
		t.Fatalf("writeCertificateBundle error = %v", err)
	}

	ca := readCertificate(t, filepath.Join(outputDirectory, "ca.pem"))
	if !ca.IsCA || ca.KeyUsage&x509.KeyUsageCertSign == 0 {
		t.Fatal("temporary CA lacks CA constraints or certificate-signing usage")
	}
	serverChain := readCertificateChain(t, filepath.Join(outputDirectory, "node-server-chain.pem"))
	if len(serverChain) != 2 {
		t.Fatalf("node server chain has %d certificates, want leaf and CA", len(serverChain))
	}
	serverCertificate := serverChain[0]
	if err := serverCertificate.CheckSignatureFrom(ca); err != nil {
		t.Fatalf("node certificate was not issued by the temporary CA: %v", err)
	}
	if err := serverCertificate.VerifyHostname("127.0.0.1"); err != nil {
		t.Fatalf("node certificate does not cover the SSM loopback control endpoint: %v", err)
	}
	if err := serverCertificate.VerifyHostname(nodePublicIP.String()); err != nil {
		t.Fatalf("node certificate does not cover the public bridge endpoint: %v", err)
	}
	if len(serverCertificate.ExtKeyUsage) != 2 ||
		!containsExtendedKeyUsage(serverCertificate.ExtKeyUsage, x509.ExtKeyUsageServerAuth) ||
		!containsExtendedKeyUsage(serverCertificate.ExtKeyUsage, x509.ExtKeyUsageClientAuth) {
		t.Fatalf("node certificate extended key usage = %v, want server and client auth", serverCertificate.ExtKeyUsage)
	}
	serverPublicKey, ok := serverCertificate.PublicKey.(*ecdsa.PublicKey)
	if !ok || serverPublicKey.Curve != elliptic.P256() ||
		serverPublicKey.X.Cmp(nodeKey.PublicKey.X) != 0 || serverPublicKey.Y.Cmp(nodeKey.PublicKey.Y) != 0 {
		t.Fatal("node certificate public key does not match the supplied KMS public key")
	}
	if serverCertificate.NotAfter.Sub(time.Now()) > certificateLifetime || serverCertificate.NotAfter.Before(time.Now()) {
		t.Fatal("node certificate validity is outside the isolated test lifetime")
	}

	adminCertificate := readCertificate(t, filepath.Join(outputDirectory, "admin-client.pem"))
	if err := adminCertificate.CheckSignatureFrom(ca); err != nil {
		t.Fatalf("admin client certificate was not issued by the temporary CA: %v", err)
	}
	if len(adminCertificate.ExtKeyUsage) != 1 || adminCertificate.ExtKeyUsage[0] != x509.ExtKeyUsageClientAuth {
		t.Fatalf("admin certificate extended key usage = %v, want client auth only", adminCertificate.ExtKeyUsage)
	}
	adminKeyPEM, err := os.ReadFile(filepath.Join(outputDirectory, "admin-client-key.pem"))
	if err != nil {
		t.Fatal(err)
	}
	adminKeyBlock, _ := pem.Decode(adminKeyPEM)
	if adminKeyBlock == nil || adminKeyBlock.Type != "PRIVATE KEY" {
		t.Fatal("admin private key is not encoded as PKCS#8 PEM")
	}
	adminPrivateKeyValue, err := x509.ParsePKCS8PrivateKey(adminKeyBlock.Bytes)
	if err != nil {
		t.Fatalf("parse admin private key: %v", err)
	}
	adminPrivateKey, ok := adminPrivateKeyValue.(*ecdsa.PrivateKey)
	adminPublicKey, adminPublicKeyOK := adminCertificate.PublicKey.(*ecdsa.PublicKey)
	if !ok || !adminPublicKeyOK || adminPrivateKey.Curve != elliptic.P256() ||
		adminPrivateKey.PublicKey.X.Cmp(adminPublicKey.X) != 0 ||
		adminPrivateKey.PublicKey.Y.Cmp(adminPublicKey.Y) != 0 {
		t.Fatal("admin private key does not match its client certificate")
	}

	for _, fileName := range []string{"ca.pem", "node-server-chain.pem", "admin-client.pem", "admin-client-key.pem", "kms-public-key.der", "manifest.json"} {
		if _, err := os.Stat(filepath.Join(outputDirectory, fileName)); err != nil {
			t.Errorf("certificate bundle is missing %s: %v", fileName, err)
		}
	}
	if _, err := os.Stat(filepath.Join(outputDirectory, "ca-key.pem")); !os.IsNotExist(err) {
		t.Fatalf("temporary CA private key must not be written to disk, stat error = %v", err)
	}
	if _, err := os.Stat(filepath.Join(outputDirectory, "node-server-key.pem")); !os.IsNotExist(err) {
		t.Fatalf("KMS-backed node private key must not be exported, stat error = %v", err)
	}
	adminKeyInfo, err := os.Stat(filepath.Join(outputDirectory, "admin-client-key.pem"))
	if err != nil {
		t.Fatal(err)
	}
	if adminKeyInfo.Mode().Perm() != 0o600 {
		t.Fatalf("admin client private-key mode = %04o, want 0600", adminKeyInfo.Mode().Perm())
	}
	manifestBytes, err := os.ReadFile(filepath.Join(outputDirectory, "manifest.json"))
	if err != nil {
		t.Fatal(err)
	}
	var manifest bundleManifest
	if err := json.Unmarshal(manifestBytes, &manifest); err != nil {
		t.Fatalf("decode certificate manifest: %v", err)
	}
	if manifest.Purpose != "isolated-test-only" || manifest.KMSKeyARN != testKMSKeyARN ||
		manifest.NodePublicIPv4 != nodePublicIP.String() || manifest.NodeID != "xlb-lab-us-test" {
		t.Fatalf("certificate manifest identity is incorrect: %+v", manifest)
	}
}

func containsExtendedKeyUsage(usages []x509.ExtKeyUsage, expected x509.ExtKeyUsage) bool {
	for _, usage := range usages {
		if usage == expected {
			return true
		}
	}
	return false
}

func TestCertificateBundleRejectsUnsafeInputs(t *testing.T) {
	nodeKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	publicDER, err := x509.MarshalPKIXPublicKey(&nodeKey.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	validIP := net.ParseIP("54.187.217.237").To4()
	outputDirectory := t.TempDir()
	if err := os.Chmod(outputDirectory, 0o700); err != nil {
		t.Fatal(err)
	}

	if err := writeCertificateBundle(outputDirectory, "bad node", testKMSKeyARN, validIP, publicDER, &nodeKey.PublicKey); err == nil {
		t.Fatal("node ID with spaces was accepted")
	}
	if err := writeCertificateBundle(outputDirectory, "xlb-lab-test", "arn:aws:kms:us-east-1:289069331089:key/00000000-0000-4000-8000-000000000000", validIP, publicDER, &nodeKey.PublicKey); err == nil {
		t.Fatal("KMS key ARN from a different region was accepted")
	}
	if err := writeCertificateBundle(outputDirectory, "xlb-lab-test", testKMSKeyARN, net.ParseIP("192.168.1.2"), publicDER, &nodeKey.PublicKey); err == nil {
		t.Fatal("private IPv4 endpoint was accepted")
	}
	otherCurveKey, err := ecdsa.GenerateKey(elliptic.P384(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	if err := writeCertificateBundle(outputDirectory, "xlb-lab-test", testKMSKeyARN, validIP, publicDER, &otherCurveKey.PublicKey); err == nil {
		t.Fatal("mismatched KMS public key curve was accepted")
	}
}

func readCertificate(t *testing.T, path string) *x509.Certificate {
	t.Helper()
	certificates := readCertificateChain(t, path)
	if len(certificates) == 0 {
		t.Fatalf("no certificates in %s", filepath.Base(path))
	}
	return certificates[0]
}

func readCertificateChain(t *testing.T, path string) []*x509.Certificate {
	t.Helper()
	contents, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read %s: %v", filepath.Base(path), err)
	}
	var certificates []*x509.Certificate
	for len(contents) > 0 {
		block, remaining := pem.Decode(contents)
		if block == nil {
			break
		}
		if block.Type == "CERTIFICATE" {
			certificate, err := x509.ParseCertificate(block.Bytes)
			if err != nil {
				t.Fatalf("parse certificate from %s: %v", filepath.Base(path), err)
			}
			certificates = append(certificates, certificate)
		}
		contents = remaining
	}
	return certificates
}
