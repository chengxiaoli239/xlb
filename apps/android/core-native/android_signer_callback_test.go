//go:build xlb_android_test_callback

package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"testing"
)

func TestAndroidSignerCompletesTLS13MutualTLSHandshake(t *testing.T) {
	privateKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	if err := completeAndroidSignerTLS13Handshake(privateKey, &privateKey.PublicKey); err != nil {
		t.Fatalf("complete test TLS handshake: %v", err)
	}
}

func TestAndroidSignerRejectsMismatchedPublicKey(t *testing.T) {
	signerKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	otherKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	if err := completeAndroidSignerTLS13Handshake(signerKey, &otherKey.PublicKey); err == nil {
		t.Fatal("expected mismatched signer public key to fail")
	}
}
