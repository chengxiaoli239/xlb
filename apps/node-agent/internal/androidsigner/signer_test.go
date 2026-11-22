package androidsigner

import (
	"bytes"
	"crypto"
	"crypto/ecdsa"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"errors"
	"os"
	"testing"
)

type androidDigestVector struct {
	Message   string `json:"message"`
	Digest    string `json:"digest"`
	PublicKey string `json:"public_key"`
	Signature string `json:"signature"`
}

type vectorKey struct {
	publicKey *ecdsa.PublicKey
	digest    []byte
	signature []byte
}

func (key vectorKey) Public() crypto.PublicKey {
	return key.publicKey
}

func (key vectorKey) SignDigest(digest []byte) ([]byte, error) {
	if !bytes.Equal(digest, key.digest) {
		return nil, errors.New("unexpected digest")
	}
	return append([]byte(nil), key.signature...), nil
}

func TestAndroidKeystoreVectorMatchesCryptoSignerDigestSemantics(t *testing.T) {
	encoded, err := os.ReadFile("testdata/android-keystore-digest-vector.json")
	if err != nil {
		t.Fatal(err)
	}
	var vector androidDigestVector
	if err := json.Unmarshal(encoded, &vector); err != nil {
		t.Fatal(err)
	}
	decode := func(value string) []byte {
		t.Helper()
		decoded, err := base64.RawURLEncoding.DecodeString(value)
		if err != nil {
			t.Fatal(err)
		}
		return decoded
	}
	message := decode(vector.Message)
	digest := decode(vector.Digest)
	if len(digest) != crypto.SHA256.Size() {
		t.Fatalf("digest length = %d, want %d", len(digest), crypto.SHA256.Size())
	}
	wantDigest := sha256.Sum256(message)
	if !bytes.Equal(digest, wantDigest[:]) {
		t.Fatal("Android vector digest does not match SHA-256(message)")
	}
	parsedPublicKey, err := x509.ParsePKIXPublicKey(decode(vector.PublicKey))
	if err != nil {
		t.Fatal(err)
	}
	publicKey, ok := parsedPublicKey.(*ecdsa.PublicKey)
	if !ok || publicKey.Curve.Params().Name != "P-256" {
		t.Fatalf("Android vector public key = %T, want P-256 ECDSA", parsedPublicKey)
	}
	signature := decode(vector.Signature)
	adapter := CryptoSignerAdapter{Key: vectorKey{
		publicKey: publicKey,
		digest:    digest,
		signature: signature,
	}}
	actual, err := adapter.Sign(nil, digest, crypto.SHA256)
	if err != nil {
		t.Fatal(err)
	}
	if !ecdsa.VerifyASN1(publicKey, digest, actual) {
		t.Fatal("Go crypto.Signer digest signature did not verify with Android Keystore public key")
	}
}

func TestCryptoSignerAdapterRejectsWrongDigestContract(t *testing.T) {
	adapter := CryptoSignerAdapter{Key: vectorKey{}}
	if _, err := adapter.Sign(nil, make([]byte, crypto.SHA256.Size()), crypto.SHA512); !errors.Is(err, ErrUnsupportedDigest) {
		t.Fatalf("SHA-512 options error = %v, want %v", err, ErrUnsupportedDigest)
	}
	if _, err := adapter.Sign(nil, make([]byte, crypto.SHA256.Size()-1), crypto.SHA256); !errors.Is(err, ErrUnsupportedDigest) {
		t.Fatalf("short digest error = %v, want %v", err, ErrUnsupportedDigest)
	}
}
