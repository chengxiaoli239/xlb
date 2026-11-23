package main

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"flag"
	"fmt"
	"io"
	"log"
	"math/big"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"time"

	agent "xlb/node-agent"
)

type startup struct {
	Endpoint          string `json:"endpoint"`
	CACertificate     string `json:"ca_certificate"`
	ClientCertificate string `json:"client_certificate"`
	ClientPrivateKey  string `json:"client_private_key"`
	ClientReport      string `json:"client_report"`
}

func main() {
	directory := flag.String("directory", "", "temporary directory for test-only state and certificates")
	nodeID := flag.String("node-id", "", "isolated node identity")
	capacity := flag.Int("capacity", 1, "isolated node capacity")
	bridgeAddress := flag.String("bridge-listen-address", "127.0.0.1:17890", "loopback-only profile bridge address")
	bridgeEndpoint := flag.String("bridge-endpoint", "https://node.example.invalid:9444", "test-only node data-plane HTTPS origin")
	flag.Parse()
	if *directory == "" || *nodeID == "" || *capacity < 1 {
		fmt.Fprintln(os.Stderr, "directory, node ID, and positive capacity are required")
		os.Exit(2)
	}

	files, err := writeCertificates(*directory)
	if err != nil {
		fail("create temporary mTLS credentials")
	}
	bridgeServerCA, err := os.ReadFile(files.caCertificate)
	if err != nil {
		fail("read temporary node server CA")
	}
	renderer, err := agent.NewMihomoBridgeProfileRenderer(*bridgeAddress, *bridgeEndpoint, string(bridgeServerCA))
	if err != nil {
		fail("configure loopback-only profile renderer")
	}
	store, err := agent.OpenPeerStoreWithProfileRenderer(filepath.Join(*directory, "peer-store.json"), *nodeID, *capacity, renderer)
	if err != nil {
		fail("open temporary peer store")
	}
	server, err := agent.NewMTLSServer("127.0.0.1:0", files.serverCertificate, files.serverPrivateKey,
		files.caCertificate, agent.NewHTTPHandler(store))
	if err != nil {
		fail("configure loopback Node Agent")
	}
	server.ErrorLog = log.New(io.Discard, "", 0)
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		fail("listen for loopback Node Agent")
	}

	configuredHandler := server.Handler
	server.Handler = http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.Method == http.MethodPost && request.URL.Path == "/v1/member-peers/ensure" {
			if err := writeClientReport(files.clientReport, request.TLS, request.URL.Path); err != nil {
				fail("write mTLS request report")
			}
			defer func() {
				go func() {
					ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
					defer cancel()
					_ = server.Shutdown(ctx)
				}()
			}()
		}
		configuredHandler.ServeHTTP(writer, request)
	})

	if err := json.NewEncoder(os.Stdout).Encode(startup{
		Endpoint: "https://" + listener.Addr().String(), CACertificate: files.caCertificate,
		ClientCertificate: files.clientCertificate, ClientPrivateKey: files.clientPrivateKey,
		ClientReport: files.clientReport,
	}); err != nil {
		fail("announce loopback Node Agent")
	}
	if err := server.ServeTLS(listener, "", ""); err != nil && err != http.ErrServerClosed {
		fail("serve loopback Node Agent")
	}
}

type certificateFiles struct {
	caCertificate     string
	serverCertificate string
	serverPrivateKey  string
	clientCertificate string
	clientPrivateKey  string
	clientReport      string
}

func writeCertificates(directory string) (certificateFiles, error) {
	if err := os.MkdirAll(directory, 0o700); err != nil {
		return certificateFiles{}, err
	}
	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return certificateFiles{}, err
	}
	now := time.Now().UTC()
	caTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "xlb temporary integration CA"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		IsCA: true, BasicConstraintsValid: true,
		KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageCRLSign | x509.KeyUsageDigitalSignature,
	}
	caDER, err := x509.CreateCertificate(rand.Reader, caTemplate, caTemplate, &caKey.PublicKey, caKey)
	if err != nil {
		return certificateFiles{}, err
	}
	caCertificate, err := x509.ParseCertificate(caDER)
	if err != nil {
		return certificateFiles{}, err
	}
	files := certificateFiles{
		caCertificate: filepath.Join(directory, "ca.pem"), serverCertificate: filepath.Join(directory, "server.pem"),
		serverPrivateKey: filepath.Join(directory, "server.key"), clientCertificate: filepath.Join(directory, "client.pem"),
		clientPrivateKey: filepath.Join(directory, "client.key"), clientReport: filepath.Join(directory, "client-report.json"),
	}
	if err := writePEM(files.caCertificate, "CERTIFICATE", caDER); err != nil {
		return certificateFiles{}, err
	}
	serverKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return certificateFiles{}, err
	}
	serverTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: "127.0.0.1"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		IPAddresses: []net.IP{net.ParseIP("127.0.0.1")},
	}
	serverDER, err := x509.CreateCertificate(rand.Reader, serverTemplate, caCertificate, &serverKey.PublicKey, caKey)
	if err != nil {
		return certificateFiles{}, err
	}
	if err := writePEM(files.serverCertificate, "CERTIFICATE", serverDER); err != nil {
		return certificateFiles{}, err
	}
	if err := writePrivateKey(files.serverPrivateKey, serverKey); err != nil {
		return certificateFiles{}, err
	}
	clientKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return certificateFiles{}, err
	}
	clientTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(3), Subject: pkix.Name{CommonName: "xlb temporary integration client"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
	}
	clientDER, err := x509.CreateCertificate(rand.Reader, clientTemplate, caCertificate, &clientKey.PublicKey, caKey)
	if err != nil {
		return certificateFiles{}, err
	}
	if err := writePEM(files.clientCertificate, "CERTIFICATE", clientDER); err != nil {
		return certificateFiles{}, err
	}
	if err := writePrivateKey(files.clientPrivateKey, clientKey); err != nil {
		return certificateFiles{}, err
	}
	return files, nil
}

func writePEM(path, blockType string, der []byte) error {
	return os.WriteFile(path, pem.EncodeToMemory(&pem.Block{Type: blockType, Bytes: der}), 0o600)
}

func writePrivateKey(path string, key *ecdsa.PrivateKey) error {
	der, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		return err
	}
	return writePEM(path, "PRIVATE KEY", der)
}

func writeClientReport(path string, state *tls.ConnectionState, requestPath string) error {
	report := struct {
		TLSPresent              bool   `json:"tls_present"`
		TLSVersion              string `json:"tls_version"`
		PeerCertificateCount    int    `json:"peer_certificate_count"`
		VerifiedChainCount      int    `json:"verified_chain_count"`
		ClientCertificateSHA256 string `json:"client_certificate_sha256"`
		VerifiedChain           bool   `json:"verified_chain"`
		RequestPath             string `json:"request_path"`
	}{RequestPath: requestPath}
	if state != nil {
		report.TLSPresent = true
		report.PeerCertificateCount = len(state.PeerCertificates)
		report.VerifiedChainCount = len(state.VerifiedChains)
		report.VerifiedChain = len(state.VerifiedChains) > 0
		if state.Version == tls.VersionTLS13 {
			report.TLSVersion = "TLS1.3"
		}
		if len(state.PeerCertificates) > 0 {
			digest := sha256.Sum256(state.PeerCertificates[0].Raw)
			report.ClientCertificateSHA256 = hex.EncodeToString(digest[:])
		}
	}
	encoded, err := json.Marshal(report)
	if err != nil {
		return err
	}
	return os.WriteFile(path, encoded, 0o600)
}

func fail(message string) {
	fmt.Fprintln(os.Stderr, message)
	os.Exit(1)
}
