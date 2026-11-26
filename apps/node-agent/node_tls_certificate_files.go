package agent

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

const maxNodeTLSCertificateFileBytes = 1 << 20

type NodeTLSCertificateFiles struct {
	CertificateChainPath string
	ClientCAPEMPath      string
}

// PEMNodeTLSCertificateProvider loads public node certificate material. It
// never reads or accepts a private-key file; the identity source attaches the
// KMS-backed crypto.Signer after matching the certificate public key.
type PEMNodeTLSCertificateProvider struct {
	files map[string]NodeTLSCertificateFiles
}

func NewPEMNodeTLSCertificateProvider(files map[string]NodeTLSCertificateFiles) (*PEMNodeTLSCertificateProvider, error) {
	if len(files) == 0 {
		return nil, ErrInvalidKMSNodeTLSIdentity
	}
	configuredFiles := make(map[string]NodeTLSCertificateFiles, len(files))
	for nodeID, nodeFiles := range files {
		if !validRuntimeToken(nodeID, 64) || !validNodeTLSCertificatePath(nodeFiles.CertificateChainPath) ||
			!validNodeTLSCertificatePath(nodeFiles.ClientCAPEMPath) ||
			filepath.Clean(nodeFiles.CertificateChainPath) == filepath.Clean(nodeFiles.ClientCAPEMPath) {
			return nil, ErrInvalidKMSNodeTLSIdentity
		}
		configuredFiles[nodeID] = nodeFiles
	}
	return &PEMNodeTLSCertificateProvider{files: configuredFiles}, nil
}

func (provider *PEMNodeTLSCertificateProvider) LoadNodeTLSCertificate(
	ctx context.Context,
	nodeID string,
) (NodeTLSIdentity, error) {
	if ctx == nil || provider == nil || !validRuntimeToken(nodeID, 64) {
		return NodeTLSIdentity{}, ErrInvalidKMSNodeTLSIdentity
	}
	if err := ctx.Err(); err != nil {
		return NodeTLSIdentity{}, err
	}
	files, exists := provider.files[nodeID]
	if !exists {
		return NodeTLSIdentity{}, ErrInvalidKMSNodeTLSIdentity
	}
	chainPEM, err := readNodeTLSCertificateFile(files.CertificateChainPath)
	if err != nil {
		return NodeTLSIdentity{}, fmt.Errorf("read node TLS certificate chain: %w", err)
	}
	certificateChain, _, err := parseNodeTLSCertificatePEM(chainPEM)
	if err != nil {
		return NodeTLSIdentity{}, err
	}
	clientCAPEM, err := readNodeTLSCertificateFile(files.ClientCAPEMPath)
	if err != nil {
		return NodeTLSIdentity{}, fmt.Errorf("read node TLS client CA bundle: %w", err)
	}
	_, clientCACertificates, err := parseNodeTLSCertificatePEM(clientCAPEM)
	if err != nil || len(clientCACertificates) == 0 {
		return NodeTLSIdentity{}, ErrNodeTLSIdentityUnavailable
	}
	clientCAs := x509.NewCertPool()
	for _, certificate := range clientCACertificates {
		if !certificate.IsCA || certificate.KeyUsage&x509.KeyUsageCertSign == 0 {
			return NodeTLSIdentity{}, ErrNodeTLSIdentityUnavailable
		}
		clientCAs.AddCert(certificate)
	}
	return NodeTLSIdentity{
		Certificate: tls.Certificate{Certificate: certificateChain},
		ClientCAs:   clientCAs,
	}, nil
}

func readNodeTLSCertificateFile(path string) ([]byte, error) {
	fileInfo, err := os.Lstat(path)
	if err != nil {
		return nil, err
	}
	if ValidateProtectedNodeRuntimeFile(path) != nil || !fileInfo.Mode().IsRegular() ||
		fileInfo.Mode()&os.ModeSymlink != 0 || fileInfo.Mode().Perm()&0o022 != 0 {
		return nil, ErrInvalidKMSNodeTLSIdentity
	}
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()
	openedInfo, err := file.Stat()
	if err != nil {
		return nil, err
	}
	if !os.SameFile(fileInfo, openedInfo) || ValidateProtectedNodeRuntimeFile(path) != nil ||
		!openedInfo.Mode().IsRegular() || openedInfo.Mode().Perm()&0o022 != 0 {
		return nil, ErrInvalidKMSNodeTLSIdentity
	}
	contents, err := io.ReadAll(io.LimitReader(file, maxNodeTLSCertificateFileBytes+1))
	if err != nil {
		return nil, err
	}
	if len(contents) == 0 || len(contents) > maxNodeTLSCertificateFileBytes {
		return nil, ErrInvalidKMSNodeTLSIdentity
	}
	return contents, nil
}

func parseNodeTLSCertificatePEM(contents []byte) ([][]byte, []*x509.Certificate, error) {
	var certificates [][]byte
	var parsedCertificates []*x509.Certificate
	for {
		contents = bytes.TrimSpace(contents)
		if len(contents) == 0 {
			break
		}
		if !bytes.HasPrefix(contents, []byte("-----BEGIN ")) {
			return nil, nil, ErrInvalidKMSNodeTLSIdentity
		}
		block, remaining := pem.Decode(contents)
		if block == nil || block.Type != "CERTIFICATE" || len(block.Headers) != 0 {
			return nil, nil, ErrInvalidKMSNodeTLSIdentity
		}
		certificate, err := x509.ParseCertificate(block.Bytes)
		if err != nil {
			return nil, nil, ErrInvalidKMSNodeTLSIdentity
		}
		certificates = append(certificates, block.Bytes)
		parsedCertificates = append(parsedCertificates, certificate)
		contents = remaining
	}
	if len(certificates) == 0 {
		return nil, nil, ErrInvalidKMSNodeTLSIdentity
	}
	return certificates, parsedCertificates, nil
}

func validNodeTLSCertificatePath(path string) bool {
	return filepath.IsAbs(path) && filepath.Clean(path) == path && !strings.ContainsRune(path, '\x00')
}
