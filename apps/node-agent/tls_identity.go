package agent

import (
	"bytes"
	"context"
	"crypto"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"time"
)

var ErrNodeTLSIdentityUnavailable = errors.New("node TLS identity is unavailable")

// NodeTLSIdentity contains a TLS certificate whose private key may be backed by
// a non-exportable crypto.Signer, plus the CA pool used for control-plane clients.
type NodeTLSIdentity struct {
	Certificate tls.Certificate
	ClientCAs   *x509.CertPool
}

// NodeTLSIdentitySource resolves the node server identity from a platform key
// store. Implementations must return a signer, not private key bytes.
type NodeTLSIdentitySource interface {
	LoadNodeTLSIdentity(context.Context, string) (NodeTLSIdentity, error)
}

func LoadNodeTLSIdentity(ctx context.Context, nodeID string, source NodeTLSIdentitySource) (NodeTLSIdentity, error) {
	if ctx == nil || !validRuntimeToken(nodeID, 64) || source == nil {
		return NodeTLSIdentity{}, ErrNodeTLSIdentityUnavailable
	}
	identity, err := source.LoadNodeTLSIdentity(ctx, nodeID)
	if err != nil {
		return NodeTLSIdentity{}, ErrNodeTLSIdentityUnavailable
	}
	if err := validateNodeTLSIdentity(identity); err != nil {
		return NodeTLSIdentity{}, err
	}
	return identity, nil
}

func validateNodeTLSIdentity(identity NodeTLSIdentity) error {
	if identity.ClientCAs == nil || len(identity.ClientCAs.Subjects()) == 0 {
		return ErrNodeTLSIdentityUnavailable
	}
	return validateNodeServerCertificate(identity.Certificate)
}

func validateNodeServerCertificate(certificate tls.Certificate) error {
	if len(certificate.Certificate) == 0 || certificate.PrivateKey == nil {
		return ErrNodeTLSIdentityUnavailable
	}
	signer, ok := certificate.PrivateKey.(crypto.Signer)
	if !ok {
		return ErrNodeTLSIdentityUnavailable
	}
	leaf, err := x509.ParseCertificate(certificate.Certificate[0])
	if err != nil || (certificate.Leaf != nil && !bytes.Equal(certificate.Leaf.Raw, leaf.Raw)) {
		return ErrNodeTLSIdentityUnavailable
	}
	publicKeyDER, err := x509.MarshalPKIXPublicKey(signer.Public())
	now := time.Now()
	if err != nil || !bytes.Equal(leaf.RawSubjectPublicKeyInfo, publicKeyDER) ||
		now.Before(leaf.NotBefore) || !now.Before(leaf.NotAfter) ||
		(leaf.KeyUsage != 0 && leaf.KeyUsage&x509.KeyUsageDigitalSignature == 0) {
		return ErrNodeTLSIdentityUnavailable
	}
	serverAuthenticationAllowed := len(leaf.ExtKeyUsage) == 0
	for _, usage := range leaf.ExtKeyUsage {
		if usage == x509.ExtKeyUsageServerAuth || usage == x509.ExtKeyUsageAny {
			serverAuthenticationAllowed = true
		}
	}
	if !serverAuthenticationAllowed {
		return ErrNodeTLSIdentityUnavailable
	}
	return nil
}
