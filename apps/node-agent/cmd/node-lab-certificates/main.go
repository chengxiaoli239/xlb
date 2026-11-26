// Command node-lab-certificates creates short-lived mTLS material for an
// isolated Node Agent test. It never exports or creates a node private key.
package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"time"
)

const certificateLifetime = 2 * time.Hour

var (
	nodeIDPattern    = regexp.MustCompile(`^[A-Za-z0-9._-]{1,64}$`)
	kmsKeyARNPattern = regexp.MustCompile(`^arn:aws:kms:us-west-2:[0-9]{12}:key/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
)

type bundleManifest struct {
	Purpose             string   `json:"purpose"`
	NodeID              string   `json:"node_id"`
	KMSKeyARN           string   `json:"kms_key_arn"`
	NodePublicIPv4      string   `json:"node_public_ipv4"`
	NodeCertificateSANs []string `json:"node_certificate_sans"`
	CertificateFiles    []string `json:"certificate_files"`
	PrivateKeyFiles     []string `json:"private_key_files"`
	GeneratedAt         string   `json:"generated_at"`
	ExpiresAt           string   `json:"expires_at"`
}

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "ERROR:", err)
		os.Exit(1)
	}
}

func run(arguments []string) error {
	flags := flag.NewFlagSet("node-lab-certificates", flag.ContinueOnError)
	publicKeyPath := flags.String("kms-public-key-der", "", "DER-encoded KMS public key exported through ops/aws-cli")
	keyARN := flags.String("kms-key-arn", "", "the exact KMS key ARN that matches the public-key file")
	nodeID := flags.String("node-id", "", "the isolated Node Agent node ID")
	nodePublicIP := flags.String("node-public-ip", "", "the isolated node's public IPv4 endpoint")
	if err := flags.Parse(arguments); err != nil {
		return err
	}
	if flags.NArg() != 0 || *publicKeyPath == "" || *keyARN == "" || *nodeID == "" || *nodePublicIP == "" {
		return errors.New("usage: node-lab-certificates --kms-public-key-der <file> --kms-key-arn <arn> --node-id <id> --node-public-ip <ipv4>")
	}
	if !nodeIDPattern.MatchString(*nodeID) {
		return errors.New("node ID contains unsupported characters")
	}
	if !validKMSKeyARN(*keyARN) {
		return errors.New("KMS key ARN is invalid")
	}
	publicIP := net.ParseIP(*nodePublicIP)
	if publicIP == nil || publicIP.To4() == nil || !isPublicIPv4(publicIP) {
		return errors.New("node public endpoint must be a globally routable IPv4 address")
	}
	publicKeyDER, err := readRegularFile(*publicKeyPath)
	if err != nil {
		return err
	}
	publicKey, err := x509.ParsePKIXPublicKey(publicKeyDER)
	if err != nil {
		return errors.New("KMS public-key file is not a valid SubjectPublicKeyInfo DER value")
	}
	ecdsaPublicKey, ok := publicKey.(*ecdsa.PublicKey)
	if !ok || ecdsaPublicKey.Curve != elliptic.P256() {
		return errors.New("KMS public key must use the P-256 curve")
	}

	outputDirectory, err := os.MkdirTemp("", "xlb-node-lab-certificates-")
	if err != nil {
		return fmt.Errorf("create temporary certificate directory: %w", err)
	}
	if err := os.Chmod(outputDirectory, 0o700); err != nil {
		_ = os.RemoveAll(outputDirectory)
		return fmt.Errorf("protect temporary certificate directory: %w", err)
	}
	if err := writeCertificateBundle(outputDirectory, *nodeID, *keyARN, publicIP.To4(), publicKeyDER, ecdsaPublicKey); err != nil {
		_ = os.RemoveAll(outputDirectory)
		return err
	}
	fmt.Printf("Created isolated Node Agent test certificate bundle in %s\n", outputDirectory)
	fmt.Printf("KMS key ARN: %s\n", *keyARN)
	fmt.Printf("Node ID: %s\n", *nodeID)
	fmt.Printf("Node server certificate: %s\n", filepath.Join(outputDirectory, "node-server-chain.pem"))
	fmt.Printf("Client CA: %s\n", filepath.Join(outputDirectory, "ca.pem"))
	fmt.Printf("Admin client certificate: %s\n", filepath.Join(outputDirectory, "admin-client.pem"))
	fmt.Printf("Admin client private key: %s\n", filepath.Join(outputDirectory, "admin-client-key.pem"))
	fmt.Println("Remove this temporary directory after the isolated test. It is not a production credential bundle.")
	return nil
}

func writeCertificateBundle(outputDirectory, nodeID, keyARN string, publicIP net.IP,
	publicKeyDER []byte, nodePublicKey *ecdsa.PublicKey,
) error {
	if !nodeIDPattern.MatchString(nodeID) || !validKMSKeyARN(keyARN) || !isPublicIPv4(publicIP) ||
		nodePublicKey == nil || nodePublicKey.Curve != elliptic.P256() {
		return errors.New("isolated test node certificate inputs are invalid")
	}
	now := time.Now().UTC().Truncate(time.Second)
	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return fmt.Errorf("generate temporary test CA key: %w", err)
	}
	caSerial, err := randomSerialNumber()
	if err != nil {
		return fmt.Errorf("generate test CA serial: %w", err)
	}
	caTemplate := &x509.Certificate{
		SerialNumber:          caSerial,
		Subject:               pkix.Name{CommonName: "XLB isolated Node Agent test CA"},
		NotBefore:             now.Add(-time.Minute),
		NotAfter:              now.Add(certificateLifetime),
		IsCA:                  true,
		BasicConstraintsValid: true,
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageCRLSign | x509.KeyUsageDigitalSignature,
	}
	caDER, err := x509.CreateCertificate(rand.Reader, caTemplate, caTemplate, &caKey.PublicKey, caKey)
	if err != nil {
		return fmt.Errorf("create temporary test CA certificate: %w", err)
	}
	caCertificate, err := x509.ParseCertificate(caDER)
	if err != nil {
		return fmt.Errorf("parse temporary test CA certificate: %w", err)
	}
	caPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: caDER})
	if err := writePrivateFile(filepath.Join(outputDirectory, "ca.pem"), caPEM, 0o644); err != nil {
		return err
	}

	serverSerial, err := randomSerialNumber()
	if err != nil {
		return fmt.Errorf("generate node certificate serial: %w", err)
	}
	serverTemplate := &x509.Certificate{
		SerialNumber: serverSerial,
		Subject:      pkix.Name{CommonName: nodeID},
		NotBefore:    now.Add(-time.Minute),
		NotAfter:     now.Add(certificateLifetime),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		IPAddresses:  []net.IP{publicIP, net.ParseIP("127.0.0.1")},
	}
	serverDER, err := x509.CreateCertificate(rand.Reader, serverTemplate, caCertificate, nodePublicKey, caKey)
	if err != nil {
		return fmt.Errorf("create KMS-backed node server certificate: %w", err)
	}
	serverChain := append(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverDER}), caPEM...)
	if err := writePrivateFile(filepath.Join(outputDirectory, "node-server-chain.pem"), serverChain, 0o644); err != nil {
		return err
	}
	if err := writePrivateFile(filepath.Join(outputDirectory, "kms-public-key.der"), publicKeyDER, 0o644); err != nil {
		return err
	}

	adminKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return fmt.Errorf("generate temporary admin client key: %w", err)
	}
	adminSerial, err := randomSerialNumber()
	if err != nil {
		return fmt.Errorf("generate admin client certificate serial: %w", err)
	}
	adminTemplate := &x509.Certificate{
		SerialNumber: adminSerial,
		Subject:      pkix.Name{CommonName: "XLB isolated Node Agent admin test client"},
		NotBefore:    now.Add(-time.Minute),
		NotAfter:     now.Add(certificateLifetime),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
	}
	adminDER, err := x509.CreateCertificate(rand.Reader, adminTemplate, caCertificate, &adminKey.PublicKey, caKey)
	if err != nil {
		return fmt.Errorf("create temporary admin client certificate: %w", err)
	}
	if err := writePrivateFile(filepath.Join(outputDirectory, "admin-client.pem"),
		pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: adminDER}), 0o644); err != nil {
		return err
	}
	adminKeyDER, err := x509.MarshalPKCS8PrivateKey(adminKey)
	if err != nil {
		return fmt.Errorf("encode temporary admin client key: %w", err)
	}
	if err := writePrivateFile(filepath.Join(outputDirectory, "admin-client-key.pem"),
		pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: adminKeyDER}), 0o600); err != nil {
		return err
	}

	manifest := bundleManifest{
		Purpose:             "isolated-test-only",
		NodeID:              nodeID,
		KMSKeyARN:           keyARN,
		NodePublicIPv4:      publicIP.String(),
		NodeCertificateSANs: []string{publicIP.String(), "127.0.0.1"},
		CertificateFiles:    []string{"ca.pem", "node-server-chain.pem", "admin-client.pem"},
		PrivateKeyFiles:     []string{"admin-client-key.pem"},
		GeneratedAt:         now.Format(time.RFC3339),
		ExpiresAt:           now.Add(certificateLifetime).Format(time.RFC3339),
	}
	manifestBytes, err := json.MarshalIndent(manifest, "", "  ")
	if err != nil {
		return fmt.Errorf("encode test certificate manifest: %w", err)
	}
	if err := writePrivateFile(filepath.Join(outputDirectory, "manifest.json"), append(manifestBytes, '\n'), 0o600); err != nil {
		return err
	}
	return nil
}

func writePrivateFile(path string, contents []byte, mode os.FileMode) error {
	file, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return fmt.Errorf("create test certificate file %s: %w", filepath.Base(path), err)
	}
	if _, err := file.Write(contents); err != nil {
		_ = file.Close()
		_ = os.Remove(path)
		return fmt.Errorf("write test certificate file %s: %w", filepath.Base(path), err)
	}
	if err := file.Sync(); err != nil {
		_ = file.Close()
		_ = os.Remove(path)
		return fmt.Errorf("sync test certificate file %s: %w", filepath.Base(path), err)
	}
	if err := file.Close(); err != nil {
		_ = os.Remove(path)
		return fmt.Errorf("close test certificate file %s: %w", filepath.Base(path), err)
	}
	return nil
}

func readRegularFile(path string) ([]byte, error) {
	info, err := os.Lstat(path)
	if err != nil {
		return nil, fmt.Errorf("read KMS public key file: %w", err)
	}
	if !info.Mode().IsRegular() || info.Mode()&os.ModeSymlink != 0 || info.Size() == 0 || info.Size() > 64*1024 {
		return nil, errors.New("KMS public key input must be a non-empty regular file no larger than 64 KiB")
	}
	contents, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read KMS public key file: %w", err)
	}
	return contents, nil
}

func randomSerialNumber() (*big.Int, error) {
	return rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 128))
}

func validKMSKeyARN(value string) bool {
	return kmsKeyARNPattern.MatchString(value)
}

func isPublicIPv4(address net.IP) bool {
	return address != nil && address.To4() != nil && address.IsGlobalUnicast() &&
		!address.IsPrivate() && !address.IsLoopback() && !address.IsLinkLocalUnicast()
}
