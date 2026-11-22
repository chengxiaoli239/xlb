package androidsigner

import (
	"crypto"
	"errors"
	"io"
)

var (
	ErrMissingKey        = errors.New("Android digest signer key is required")
	ErrUnsupportedDigest = errors.New("Android digest signer requires a SHA-256 digest")
)

// DigestSigningKey is the narrow platform boundary for a non-exportable P-256 key.
// Implementations receive the already-hashed digest required by crypto.Signer.
type DigestSigningKey interface {
	Public() crypto.PublicKey
	SignDigest(digest []byte) ([]byte, error)
}

// CryptoSignerAdapter exposes a platform digest signer through Go's crypto.Signer contract.
type CryptoSignerAdapter struct {
	Key DigestSigningKey
}

var _ crypto.Signer = CryptoSignerAdapter{}

func (adapter CryptoSignerAdapter) Public() crypto.PublicKey {
	if adapter.Key == nil {
		return nil
	}
	return adapter.Key.Public()
}

func (adapter CryptoSignerAdapter) Sign(_ io.Reader, digest []byte, opts crypto.SignerOpts) ([]byte, error) {
	if adapter.Key == nil {
		return nil, ErrMissingKey
	}
	if opts == nil || opts.HashFunc() != crypto.SHA256 || len(digest) != crypto.SHA256.Size() {
		return nil, ErrUnsupportedDigest
	}
	return adapter.Key.SignDigest(append([]byte(nil), digest...))
}
