//go:build xlb_android_test_callback

package main

/*
#include <stdlib.h>
*/
import "C"

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"errors"
	"io"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"sync"
	"time"

	agent "xlb/node-agent"
)

type androidNodeAgentFixtureRequest struct {
	NodeID                  string    `json:"node_id"`
	PeerID                  string    `json:"peer_id"`
	EntitlementID           string    `json:"entitlement_id"`
	RequestID               string    `json:"request_id"`
	Generation              int       `json:"generation"`
	DeviceID                string    `json:"device_id"`
	DeviceName              string    `json:"device_name"`
	DevicePublicKey         string    `json:"device_public_key"`
	DeviceKeyFingerprint    string    `json:"device_key_fingerprint"`
	DeviceBindingGeneration int       `json:"device_binding_generation"`
	SubscriptionID          string    `json:"subscription_id"`
	SubscriptionToken       string    `json:"subscription_token"`
	SubscriptionGeneration  int       `json:"subscription_generation"`
	PeerGeneration          int       `json:"peer_generation"`
	DeviceGeneration        int       `json:"device_generation"`
	ExpiresAt               time.Time `json:"expires_at"`
	Credential              string    `json:"credential"`
	StatePath               string    `json:"state_path"`
}

type androidNodeAgentFixtureResponse struct {
	Endpoint string `json:"endpoint"`
	CAPEM    string `json:"ca_pem"`
}

var androidNodeAgentFixture struct {
	sync.Mutex
	server     *httptest.Server
	lastStatus int
	lastBody   string
}

//export XlbStartAndroidNodeAgentFixture
func XlbStartAndroidNodeAgentFixture(requestJSON *C.char, requestLength C.int) *C.char {
	if requestJSON == nil || requestLength < 1 || requestLength > 32768 {
		return C.CString(`{"error":"fixture request is invalid"}`)
	}
	var request androidNodeAgentFixtureRequest
	if err := json.Unmarshal([]byte(C.GoStringN(requestJSON, requestLength)), &request); err != nil {
		return C.CString(`{"error":"fixture request is invalid"}`)
	}
	androidNodeAgentFixture.Lock()
	defer androidNodeAgentFixture.Unlock()
	if androidNodeAgentFixture.server != nil {
		return C.CString(`{"error":"fixture is already running"}`)
	}
	response, err := startAndroidNodeAgentFixture(request)
	if err != nil {
		return C.CString(`{"error":"fixture could not start"}`)
	}
	encoded, err := json.Marshal(response)
	if err != nil {
		return C.CString(`{"error":"fixture response is invalid"}`)
	}
	return C.CString(string(encoded))
}

//export XlbStopAndroidNodeAgentFixture
func XlbStopAndroidNodeAgentFixture() {
	androidNodeAgentFixture.Lock()
	defer androidNodeAgentFixture.Unlock()
	if androidNodeAgentFixture.server == nil {
		return
	}
	androidNodeAgentFixture.server.Close()
	androidNodeAgentFixture.server = nil
}

//export XlbAndroidNodeAgentFixtureStatus
func XlbAndroidNodeAgentFixtureStatus() *C.char {
	androidNodeAgentFixture.Lock()
	defer androidNodeAgentFixture.Unlock()
	encoded, err := json.Marshal(map[string]any{
		"status": androidNodeAgentFixture.lastStatus,
		"body":   androidNodeAgentFixture.lastBody,
	})
	if err != nil {
		return C.CString(`{"status":0,"body":""}`)
	}
	return C.CString(string(encoded))
}

func startAndroidNodeAgentFixture(request androidNodeAgentFixtureRequest) (androidNodeAgentFixtureResponse, error) {
	statePath := filepath.Clean(request.StatePath)
	if !filepath.IsAbs(request.StatePath) || filepath.Base(statePath) != "peer-store.json" ||
		!strings.HasPrefix(filepath.Base(filepath.Dir(statePath)), "isolated-node-agent-") ||
		request.NodeID == "" || request.PeerID == "" || request.DeviceID == "" ||
		request.DevicePublicKey == "" || request.DeviceKeyFingerprint == "" || request.RequestID == "" ||
		request.EntitlementID == "" || request.DeviceName == "" || request.Credential == "" ||
		request.Generation < 1 || request.PeerGeneration != request.Generation ||
		request.DeviceBindingGeneration < 1 || request.DeviceGeneration != request.DeviceBindingGeneration ||
		!request.ExpiresAt.After(time.Now()) {
		return androidNodeAgentFixtureResponse{}, errors.New("fixture peer identity is invalid")
	}
	store, err := agent.OpenPeerStore(statePath, request.NodeID, 1)
	if err != nil {
		return androidNodeAgentFixtureResponse{}, err
	}
	peerRequest := agent.EnsurePeerRequest{
		NodeID: request.NodeID, PeerID: request.PeerID, EntitlementID: request.EntitlementID,
		RequestID: request.RequestID, Generation: request.Generation, DeviceID: request.DeviceID,
		DeviceName: request.DeviceName, DevicePublicKey: request.DevicePublicKey,
		DeviceKeyFingerprint:    request.DeviceKeyFingerprint,
		DeviceBindingGeneration: request.DeviceBindingGeneration, PeerGeneration: request.PeerGeneration,
		DeviceGeneration: request.DeviceGeneration, SubscriptionID: request.SubscriptionID,
		SubscriptionToken: request.SubscriptionToken, SubscriptionGeneration: request.SubscriptionGeneration,
		ExpiresAt: request.ExpiresAt, Credential: request.Credential,
	}
	if _, err := store.EnsurePeer(peerRequest); err != nil {
		return androidNodeAgentFixtureResponse{}, err
	}
	dataPlane, err := agent.NewDataPlane(store, androidFixtureEchoTargetDialer{})
	if err != nil {
		return androidNodeAgentFixtureResponse{}, err
	}
	serverCertificate, caPEM, err := androidFixtureServerCertificate()
	if err != nil {
		return androidNodeAgentFixtureResponse{}, err
	}
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		capture := &androidFixtureResponseWriter{ResponseWriter: writer}
		dataPlane.Handler().ServeHTTP(capture, request)
		androidNodeAgentFixture.Lock()
		androidNodeAgentFixture.lastStatus = capture.status
		androidNodeAgentFixture.lastBody = capture.body.String()
		androidNodeAgentFixture.Unlock()
	}))
	server.EnableHTTP2 = true
	server.Config.ConnContext = dataPlane.ConnContext
	server.Config.ConnState = dataPlane.ConnState
	server.TLS = &tls.Config{
		MinVersion: tls.VersionTLS13, Certificates: []tls.Certificate{serverCertificate},
		ClientAuth: tls.RequireAnyClientCert,
	}
	server.StartTLS()
	listener, ok := server.Listener.Addr().(*net.TCPAddr)
	if !ok || listener.IP == nil || !listener.IP.IsLoopback() {
		server.Close()
		return androidNodeAgentFixtureResponse{}, errors.New("fixture listener is not loopback")
	}
	androidNodeAgentFixture.server = server
	androidNodeAgentFixture.lastStatus = 0
	androidNodeAgentFixture.lastBody = ""
	return androidNodeAgentFixtureResponse{
		Endpoint: "https://" + server.Listener.Addr().String(), CAPEM: caPEM,
	}, nil
}

type androidFixtureResponseWriter struct {
	http.ResponseWriter
	status int
	body   bytes.Buffer
}

func (writer *androidFixtureResponseWriter) WriteHeader(status int) {
	writer.status = status
	writer.ResponseWriter.WriteHeader(status)
}

func (writer *androidFixtureResponseWriter) Write(payload []byte) (int, error) {
	if writer.status == 0 {
		writer.status = http.StatusOK
	}
	if writer.status >= 400 && writer.body.Len() < 512 {
		remaining := 512 - writer.body.Len()
		if len(payload) > remaining {
			payload = payload[:remaining]
		}
		_, _ = writer.body.Write(payload)
	}
	return writer.ResponseWriter.Write(payload)
}

func (writer *androidFixtureResponseWriter) Flush() {
	if writer.status == 0 {
		writer.status = http.StatusOK
	}
	if flusher, ok := writer.ResponseWriter.(http.Flusher); ok {
		flusher.Flush()
	}
}

type androidFixtureEchoTargetDialer struct{}

func (androidFixtureEchoTargetDialer) DialTarget(context.Context, string, uint16) (net.Conn, error) {
	client, server := net.Pipe()
	go func() {
		defer server.Close()
		_, _ = io.Copy(server, server)
	}()
	return client, nil
}

func androidFixtureServerCertificate() (tls.Certificate, string, error) {
	rootKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return tls.Certificate{}, "", err
	}
	now := time.Now()
	rootTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(11), Subject: pkix.Name{CommonName: "XLB Android isolated Agent test CA"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), IsCA: true,
		BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
	}
	rootDER, err := x509.CreateCertificate(rand.Reader, rootTemplate, rootTemplate, &rootKey.PublicKey, rootKey)
	if err != nil {
		return tls.Certificate{}, "", err
	}
	serverKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return tls.Certificate{}, "", err
	}
	serverTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(12), Subject: pkix.Name{CommonName: "XLB Android isolated Agent"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		IPAddresses: []net.IP{net.ParseIP("127.0.0.1")},
	}
	serverDER, err := x509.CreateCertificate(rand.Reader, serverTemplate, rootTemplate, &serverKey.PublicKey, rootKey)
	if err != nil {
		return tls.Certificate{}, "", err
	}
	serverPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverDER})
	privateKeyDER, err := x509.MarshalPKCS8PrivateKey(serverKey)
	if err != nil {
		return tls.Certificate{}, "", err
	}
	privateKeyPEM := pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateKeyDER})
	certificate, err := tls.X509KeyPair(serverPEM, privateKeyPEM)
	if err != nil {
		return tls.Certificate{}, "", err
	}
	caPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: rootDER})
	return certificate, string(caPEM), nil
}
