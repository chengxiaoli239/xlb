package agent

import (
	"bytes"
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/sha256"
	"crypto/x509"
	"encoding/asn1"
	"errors"
	"fmt"
	"io"
	"math/big"
	"strings"
	"time"
)

var ErrInvalidKMSNodeTLSIdentity = errors.New("KMS node TLS identity is invalid")

// KMSAsymmetricKeyClient adapts AWS KMS GetPublicKey and Sign operations.
// Sign must pass the digest as MessageType=DIGEST with ECDSA_SHA_256.
type KMSAsymmetricKeyClient interface {
	GetPublicKey(context.Context, string) (KMSPublicKey, error)
	Sign(context.Context, string, []byte) ([]byte, error)
}

type KMSPublicKey struct {
	DER               []byte
	KeySpec           string
	KeyUsage          string
	SigningAlgorithms []string
}

// NodeTLSCertificateProvider loads the node certificate chain and trusted
// control-plane client CAs. The leaf certificate must not contain a private key.
type NodeTLSCertificateProvider interface {
	LoadNodeTLSCertificate(context.Context, string) (NodeTLSIdentity, error)
}

// KMSNodeTLSIdentitySource joins a node's issued certificate to an asymmetric
// P-256 KMS key without retrieving private key material from the KMS backend.
type KMSNodeTLSIdentitySource struct {
	client       KMSAsymmetricKeyClient
	certificates NodeTLSCertificateProvider
	keyIDs       map[string]string
	timeout      time.Duration
}

func NewKMSNodeTLSIdentitySource(
	client KMSAsymmetricKeyClient,
	certificates NodeTLSCertificateProvider,
	keyIDs map[string]string,
	timeout time.Duration,
) (*KMSNodeTLSIdentitySource, error) {
	if client == nil || certificates == nil || len(keyIDs) == 0 || timeout <= 0 {
		return nil, ErrInvalidKMSNodeTLSIdentity
	}
	configuredKeys := make(map[string]string, len(keyIDs))
	for nodeID, keyID := range keyIDs {
		if !validRuntimeToken(nodeID, 64) || strings.TrimSpace(keyID) != keyID || keyID == "" || len(keyID) > 2048 {
			return nil, ErrInvalidKMSNodeTLSIdentity
		}
		configuredKeys[nodeID] = keyID
	}
	return &KMSNodeTLSIdentitySource{
		client: client, certificates: certificates, keyIDs: configuredKeys, timeout: timeout,
	}, nil
}

func (source *KMSNodeTLSIdentitySource) LoadNodeTLSIdentity(
	ctx context.Context,
	nodeID string,
) (NodeTLSIdentity, error) {
	if ctx == nil || source == nil || !validRuntimeToken(nodeID, 64) {
		return NodeTLSIdentity{}, ErrInvalidKMSNodeTLSIdentity
	}
	keyID, exists := source.keyIDs[nodeID]
	if !exists {
		return NodeTLSIdentity{}, ErrInvalidKMSNodeTLSIdentity
	}
	publicKey, err := source.client.GetPublicKey(ctx, keyID)
	if err != nil {
		return NodeTLSIdentity{}, fmt.Errorf("load KMS public key for node %q: %w", nodeID, err)
	}
	if !supportsKMSP256Signing(publicKey) {
		return NodeTLSIdentity{}, ErrInvalidKMSNodeTLSIdentity
	}
	signer, err := NewKMSECDSASigner(source.client, keyID, publicKey.DER, source.timeout)
	if err != nil {
		return NodeTLSIdentity{}, err
	}
	identity, err := source.certificates.LoadNodeTLSCertificate(ctx, nodeID)
	if err != nil {
		return NodeTLSIdentity{}, fmt.Errorf("load TLS certificate for node %q: %w", nodeID, err)
	}
	if len(identity.Certificate.Certificate) == 0 || identity.Certificate.PrivateKey != nil {
		return NodeTLSIdentity{}, ErrInvalidKMSNodeTLSIdentity
	}
	leaf, err := x509.ParseCertificate(identity.Certificate.Certificate[0])
	if err != nil {
		return NodeTLSIdentity{}, ErrInvalidKMSNodeTLSIdentity
	}
	certificatePublicKeyDER, err := x509.MarshalPKIXPublicKey(leaf.PublicKey)
	if err != nil || !bytes.Equal(certificatePublicKeyDER, publicKey.DER) {
		return NodeTLSIdentity{}, ErrNodeTLSIdentityUnavailable
	}
	identity.Certificate.Leaf = leaf
	identity.Certificate.PrivateKey = signer
	if err := validateNodeTLSIdentity(identity); err != nil {
		return NodeTLSIdentity{}, err
	}
	return identity, nil
}

func supportsKMSP256Signing(publicKey KMSPublicKey) bool {
	if publicKey.KeySpec != "ECC_NIST_P256" || publicKey.KeyUsage != "SIGN_VERIFY" {
		return false
	}
	for _, algorithm := range publicKey.SigningAlgorithms {
		if algorithm == "ECDSA_SHA_256" {
			return true
		}
	}
	return false
}

// KMSECDSASigner adapts an AWS KMS P-256 asymmetric key to crypto.Signer.
type KMSECDSASigner struct {
	client  KMSAsymmetricKeyClient
	keyID   string
	public  *ecdsa.PublicKey
	timeout time.Duration
}

func NewKMSECDSASigner(
	client KMSAsymmetricKeyClient,
	keyID string,
	publicKeyDER []byte,
	timeout time.Duration,
) (KMSECDSASigner, error) {
	if client == nil || strings.TrimSpace(keyID) != keyID || keyID == "" || len(keyID) > 2048 || timeout <= 0 {
		return KMSECDSASigner{}, ErrInvalidKMSNodeTLSIdentity
	}
	publicKey, err := x509.ParsePKIXPublicKey(publicKeyDER)
	if err != nil {
		return KMSECDSASigner{}, ErrInvalidKMSNodeTLSIdentity
	}
	ecdsaPublicKey, ok := publicKey.(*ecdsa.PublicKey)
	if !ok || ecdsaPublicKey.Curve != elliptic.P256() || ecdsaPublicKey.X == nil || ecdsaPublicKey.Y == nil {
		return KMSECDSASigner{}, ErrInvalidKMSNodeTLSIdentity
	}
	return KMSECDSASigner{client: client, keyID: keyID, public: ecdsaPublicKey, timeout: timeout}, nil
}

func (signer KMSECDSASigner) Public() crypto.PublicKey {
	return signer.public
}

func (signer KMSECDSASigner) Sign(_ io.Reader, digest []byte, opts crypto.SignerOpts) ([]byte, error) {
	if signer.client == nil || signer.public == nil || signer.keyID == "" || signer.timeout <= 0 ||
		len(digest) != sha256.Size || opts == nil || opts.HashFunc() != crypto.SHA256 {
		return nil, ErrInvalidKMSNodeTLSIdentity
	}
	ctx, cancel := context.WithTimeout(context.Background(), signer.timeout)
	defer cancel()
	signature, err := signer.client.Sign(ctx, signer.keyID, digest)
	if err != nil {
		return nil, fmt.Errorf("sign node TLS digest with KMS: %w", err)
	}
	var parsed struct {
		R *big.Int
		S *big.Int
	}
	remaining, err := asn1.Unmarshal(signature, &parsed)
	if err != nil || len(remaining) != 0 || parsed.R == nil || parsed.S == nil ||
		!ecdsa.Verify(signer.public, digest, parsed.R, parsed.S) {
		return nil, ErrInvalidKMSNodeTLSIdentity
	}
	return signature, nil
}
