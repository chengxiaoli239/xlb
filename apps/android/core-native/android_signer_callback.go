//go:build xlb_android_test_callback

package main

/*
#include <stdlib.h>
#include "callbacks.h"
*/
import "C"

import (
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"errors"
	"io"
	"runtime"
	"unsafe"
)

type androidCallbackSigner struct {
	publicKey *ecdsa.PublicKey
}

func (signer androidCallbackSigner) Public() crypto.PublicKey {
	return signer.publicKey
}

func (signer androidCallbackSigner) Sign(_ io.Reader, digest []byte, opts crypto.SignerOpts) ([]byte, error) {
	if signer.publicKey == nil || opts == nil || opts.HashFunc() != crypto.SHA256 ||
		len(digest) != crypto.SHA256.Size() {
		return nil, errors.New("Android device signer requires a SHA-256 digest")
	}
	signature := make([]byte, 80)
	signedLen := C.xlb_call_java_sign_digest(
		(*C.uchar)(unsafe.Pointer(&digest[0])), C.int(len(digest)),
		(*C.uchar)(unsafe.Pointer(&signature[0])), C.int(len(signature)),
	)
	if signedLen < 1 || int(signedLen) > len(signature) {
		return nil, errors.New("Android device signer callback failed")
	}
	return signature[:int(signedLen)], nil
}

//export XlbVerifyAndroidSigner
func XlbVerifyAndroidSigner(publicKeyDER *C.char, publicKeyLen C.int, digestBytes *C.char, digestLen C.int) C.int {
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	if publicKeyDER == nil || digestBytes == nil || publicKeyLen < 1 || publicKeyLen > 256 ||
		digestLen != C.int(crypto.SHA256.Size()) {
		return 0
	}
	parsed, err := x509.ParsePKIXPublicKey(C.GoBytes(unsafe.Pointer(publicKeyDER), publicKeyLen))
	if err != nil {
		return 0
	}
	publicKey, ok := parsed.(*ecdsa.PublicKey)
	if !ok || publicKey.Curve != elliptic.P256() {
		return 0
	}
	digest := C.GoBytes(unsafe.Pointer(digestBytes), digestLen)
	signature, err := (androidCallbackSigner{publicKey: publicKey}).Sign(rand.Reader, digest, crypto.SHA256)
	if err != nil || !ecdsa.VerifyASN1(publicKey, digest, signature) {
		return 0
	}
	return 1
}
