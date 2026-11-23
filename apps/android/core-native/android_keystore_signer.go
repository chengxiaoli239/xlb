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
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"errors"
	"io"
	"sync"
	"time"
	"unsafe"

	agent "xlb/node-agent"
)

var androidDeviceCertificateMu sync.Mutex
var androidDeviceCertificate *tls.Certificate

type androidKeyStoreSigner struct {
	publicKey *ecdsa.PublicKey
}

func (signer *androidKeyStoreSigner) Public() crypto.PublicKey {
	return signer.publicKey
}

func (signer *androidKeyStoreSigner) Sign(_ io.Reader, digest []byte, options crypto.SignerOpts) ([]byte, error) {
	if signer == nil || signer.publicKey == nil || options == nil || options.HashFunc() != crypto.SHA256 ||
		len(digest) != sha256.Size {
		return nil, errors.New("Android Keystore signer requires a SHA-256 digest")
	}
	signature := make([]byte, 80)
	signedLength := C.xlb_call_java_sign_digest(
		(*C.uchar)(unsafe.Pointer(&digest[0])), C.int(len(digest)),
		(*C.uchar)(unsafe.Pointer(&signature[0])), C.int(len(signature)),
	)
	if signedLength < 1 || int(signedLength) > len(signature) {
		return nil, errors.New("Android Keystore signing callback failed")
	}
	signature = signature[:int(signedLength)]
	if !ecdsa.VerifyASN1(signer.publicKey, digest, signature) {
		return nil, errors.New("Android Keystore signature does not match its public key")
	}
	return signature, nil
}

//export XlbCreateAndroidDeviceCertificate
func XlbCreateAndroidDeviceCertificate(nodeID, peerID, deviceID *C.char,
	peerGeneration, deviceGeneration C.int, publicKeyDER *C.char, publicKeyLength C.int) *C.char {
	if nodeID == nil || peerID == nil || deviceID == nil || publicKeyDER == nil ||
		publicKeyLength < 1 || publicKeyLength > 256 || peerGeneration < 1 || deviceGeneration < 1 {
		return nil
	}

	parsed, err := x509.ParsePKIXPublicKey(C.GoBytes(unsafe.Pointer(publicKeyDER), publicKeyLength))
	publicKey, ok := parsed.(*ecdsa.PublicKey)
	if err != nil || !ok || publicKey.Curve != elliptic.P256() {
		return nil
	}

	androidDeviceCertificateMu.Lock()
	defer androidDeviceCertificateMu.Unlock()
	if androidDeviceCertificate != nil {
		return nil
	}

	signer := &androidKeyStoreSigner{publicKey: publicKey}
	identity := agent.DeviceCertificateIdentity{
		NodeID: C.GoString(nodeID), PeerID: C.GoString(peerID), DeviceID: C.GoString(deviceID),
		PeerGeneration: int(peerGeneration), DeviceGeneration: int(deviceGeneration),
	}
	certificate, err := agent.NewDeviceCertificateWithLifetime(identity, signer, 10*time.Minute)
	if err != nil || len(certificate.Certificate) != 1 {
		return nil
	}
	androidDeviceCertificate = &certificate
	encoded := base64.RawURLEncoding.EncodeToString(certificate.Certificate[0])
	return C.CString(encoded)
}

//export XlbReleaseAndroidDeviceCertificate
func XlbReleaseAndroidDeviceCertificate() {
	androidDeviceCertificateMu.Lock()
	androidDeviceCertificate = nil
	androidDeviceCertificateMu.Unlock()
}
