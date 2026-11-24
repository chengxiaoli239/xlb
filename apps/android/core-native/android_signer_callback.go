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
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"errors"
	"math/big"
	"net"
	"runtime"
	"time"
	"unsafe"
)

//export XlbCompleteAndroidSignerHandshake
func XlbCompleteAndroidSignerHandshake(publicKeyDER *C.char, publicKeyLen C.int) C.int {
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	if publicKeyDER == nil || publicKeyLen < 1 || publicKeyLen > 256 {
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
	if err := completeAndroidSignerTLS13Handshake(&androidKeyStoreSigner{publicKey: publicKey}, publicKey); err != nil {
		return 0
	}
	return 1
}

func completeAndroidSignerTLS13Handshake(signer crypto.Signer, publicKey *ecdsa.PublicKey) error {
	if signer == nil || publicKey == nil || publicKey.Curve != elliptic.P256() {
		return errors.New("Android device signer key is invalid")
	}
	signerKey, ok := signer.Public().(*ecdsa.PublicKey)
	if !ok || signerKey.Curve != elliptic.P256() || signerKey.X.Cmp(publicKey.X) != 0 || signerKey.Y.Cmp(publicKey.Y) != 0 {
		return errors.New("Android device signer public key does not match")
	}

	now := time.Now()
	rootKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return err
	}
	rootSerial, err := randomCertificateSerial()
	if err != nil {
		return err
	}
	rootTemplate := &x509.Certificate{
		SerialNumber:          rootSerial,
		Subject:               pkix.Name{CommonName: "XLB Android signer test root"},
		NotBefore:             now.Add(-time.Minute),
		NotAfter:              now.Add(5 * time.Minute),
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature,
		BasicConstraintsValid: true,
		IsCA:                  true,
		MaxPathLen:            0,
	}
	rootDER, err := x509.CreateCertificate(rand.Reader, rootTemplate, rootTemplate, &rootKey.PublicKey, rootKey)
	if err != nil {
		return err
	}
	rootCertificate, err := x509.ParseCertificate(rootDER)
	if err != nil {
		return err
	}

	clientSerial, err := randomCertificateSerial()
	if err != nil {
		return err
	}
	clientTemplate := &x509.Certificate{
		SerialNumber:          clientSerial,
		Subject:               pkix.Name{CommonName: "XLB Android signer test client"},
		NotBefore:             now.Add(-time.Minute),
		NotAfter:              now.Add(5 * time.Minute),
		KeyUsage:              x509.KeyUsageDigitalSignature,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
		BasicConstraintsValid: true,
	}
	clientDER, err := x509.CreateCertificate(rand.Reader, clientTemplate, rootCertificate, publicKey, rootKey)
	if err != nil {
		return err
	}
	clientLeaf, err := x509.ParseCertificate(clientDER)
	if err != nil {
		return err
	}

	serverKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return err
	}
	serverSerial, err := randomCertificateSerial()
	if err != nil {
		return err
	}
	serverTemplate := &x509.Certificate{
		SerialNumber:          serverSerial,
		Subject:               pkix.Name{CommonName: "xlb-signer.test"},
		DNSNames:              []string{"xlb-signer.test"},
		NotBefore:             now.Add(-time.Minute),
		NotAfter:              now.Add(5 * time.Minute),
		KeyUsage:              x509.KeyUsageDigitalSignature,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
	}
	serverDER, err := x509.CreateCertificate(rand.Reader, serverTemplate, rootCertificate, &serverKey.PublicKey, rootKey)
	if err != nil {
		return err
	}
	serverLeaf, err := x509.ParseCertificate(serverDER)
	if err != nil {
		return err
	}

	roots := x509.NewCertPool()
	roots.AddCert(rootCertificate)
	clientCertificate := tls.Certificate{
		Certificate: [][]byte{clientDER},
		PrivateKey:  signer,
		Leaf:        clientLeaf,
	}
	serverCertificate := tls.Certificate{
		Certificate: [][]byte{serverDER},
		PrivateKey:  serverKey,
		Leaf:        serverLeaf,
	}
	serverRaw, clientRaw := net.Pipe()
	defer serverRaw.Close()
	defer clientRaw.Close()
	deadline := now.Add(5 * time.Second)
	if err := serverRaw.SetDeadline(deadline); err != nil {
		return err
	}
	if err := clientRaw.SetDeadline(deadline); err != nil {
		return err
	}
	serverConnection := tls.Server(serverRaw, &tls.Config{
		Certificates: []tls.Certificate{serverCertificate},
		ClientAuth:   tls.RequireAndVerifyClientCert,
		ClientCAs:    roots,
		MinVersion:   tls.VersionTLS13,
		MaxVersion:   tls.VersionTLS13,
	})
	clientConnection := tls.Client(clientRaw, &tls.Config{
		Certificates: []tls.Certificate{clientCertificate},
		RootCAs:      roots,
		ServerName:   "xlb-signer.test",
		MinVersion:   tls.VersionTLS13,
		MaxVersion:   tls.VersionTLS13,
	})
	serverResult := make(chan error, 1)
	go func() {
		serverResult <- serverConnection.Handshake()
	}()
	clientErr := clientConnection.Handshake()
	serverErr := <-serverResult
	if clientErr != nil {
		return errors.New("Android signer TLS client handshake failed")
	}
	if serverErr != nil {
		return errors.New("Android signer TLS server handshake failed")
	}
	peerCertificates := serverConnection.ConnectionState().PeerCertificates
	if len(peerCertificates) != 1 {
		return errors.New("Android signer TLS peer certificate is missing")
	}
	peerKey, ok := peerCertificates[0].PublicKey.(*ecdsa.PublicKey)
	if !ok || peerKey.X.Cmp(publicKey.X) != 0 || peerKey.Y.Cmp(publicKey.Y) != 0 {
		return errors.New("Android signer TLS peer certificate does not match")
	}
	return nil
}

func randomCertificateSerial() (*big.Int, error) {
	return rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 128))
}
