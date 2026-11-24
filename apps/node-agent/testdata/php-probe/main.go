package main

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"time"

	"xlb/node-agent"
)

type localCanaryDialer struct {
	address string
}

func (dialer localCanaryDialer) DialTarget(ctx context.Context, host string, port uint16) (net.Conn, error) {
	if host != "127.0.0.1" || port != 443 {
		return nil, errors.New("test canary destination is not loopback")
	}
	return (&net.Dialer{Timeout: time.Second}).DialContext(ctx, "tcp", dialer.address)
}

func main() {
	certificatePath := flag.String("certificate", "", "test server certificate path")
	privateKeyPath := flag.String("private-key", "", "test server private key path")
	clientCAPath := flag.String("client-ca", "", "test client CA path")
	nodeID := flag.String("node-id", "", "test node identity")
	flag.Parse()
	if *certificatePath == "" || *privateKeyPath == "" || *clientCAPath == "" || *nodeID == "" {
		fmt.Fprintln(os.Stderr, "certificate, private key, client CA, and node ID are required")
		os.Exit(2)
	}
	caPEM, err := os.ReadFile(*clientCAPath)
	if err != nil {
		fmt.Fprintln(os.Stderr, "read isolated canary CA")
		os.Exit(1)
	}
	certificate, err := tls.LoadX509KeyPair(*certificatePath, *privateKeyPath)
	if err != nil {
		fmt.Fprintln(os.Stderr, "load isolated canary server certificate")
		os.Exit(1)
	}
	canary := httptest.NewUnstartedServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != http.MethodPost || request.URL.Path != "/.well-known/xlb-node-probe/v1" {
			http.NotFound(writer, request)
			return
		}
		var probe agent.NodeDataPlaneProbeRequest
		if err := json.NewDecoder(request.Body).Decode(&probe); err != nil {
			http.Error(writer, "invalid canary challenge", http.StatusBadRequest)
			return
		}
		writer.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(writer).Encode(struct {
			Data struct {
				Challenge string `json:"challenge"`
			} `json:"data"`
		}{Data: struct {
			Challenge string `json:"challenge"`
		}{Challenge: probe.Challenge}})
	}))
	canary.TLS = &tls.Config{MinVersion: tls.VersionTLS13, Certificates: []tls.Certificate{certificate}}
	canary.StartTLS()
	defer canary.Close()
	canaryExecutor, err := agent.NewHTTPSCanaryProbeExecutor(agent.DataPlaneProbeRuntimeConfig{
		Status:         agent.RuntimeProbeHTTPSCanary,
		CanaryEndpoint: "https://127.0.0.1/.well-known/xlb-node-probe/v1",
		ServerCAPEM:    string(caPEM),
	}, localCanaryDialer{address: canary.Listener.Addr().String()})
	if err != nil {
		fmt.Fprintln(os.Stderr, "configure isolated HTTPS canary executor")
		os.Exit(1)
	}

	configuredServer, err := agent.NewMTLSServer("127.0.0.1:0", *certificatePath, *privateKeyPath, *clientCAPath,
		agent.NewDataPlaneProbeHandler(*nodeID, canaryExecutor))
	if err != nil {
		fmt.Fprintln(os.Stderr, "configure Go Node Agent probe fixture")
		os.Exit(1)
	}
	configuredServer.ErrorLog = log.New(io.Discard, "", 0)
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		fmt.Fprintln(os.Stderr, "listen on loopback for Go Node Agent probe fixture")
		os.Exit(1)
	}

	server := &http.Server{
		Handler:           configuredServer.Handler,
		ReadHeaderTimeout: configuredServer.ReadHeaderTimeout,
		ReadTimeout:       configuredServer.ReadTimeout,
		WriteTimeout:      configuredServer.WriteTimeout,
		IdleTimeout:       configuredServer.IdleTimeout,
		MaxHeaderBytes:    configuredServer.MaxHeaderBytes,
		TLSConfig:         configuredServer.TLSConfig,
		ErrorLog:          configuredServer.ErrorLog,
	}
	server.Handler = http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		configuredServer.Handler.ServeHTTP(writer, request)
		go func() {
			_ = server.Shutdown(context.Background())
		}()
	})

	if _, err := fmt.Fprintln(os.Stdout, "https://"+listener.Addr().String()); err != nil {
		fmt.Fprintln(os.Stderr, "announce Go Node Agent loopback endpoint")
		os.Exit(1)
	}
	if err := server.ServeTLS(listener, "", ""); err != nil && err != http.ErrServerClosed {
		fmt.Fprintln(os.Stderr, "serve Go Node Agent probe fixture")
		os.Exit(1)
	}
}
