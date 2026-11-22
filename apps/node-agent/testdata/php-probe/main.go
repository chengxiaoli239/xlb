package main

import (
	"context"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"

	"xlb/node-agent"
)

type echoExecutor struct{}

func (echoExecutor) Exchange(_ context.Context, challenge []byte) ([]byte, error) {
	return append([]byte(nil), challenge...), nil
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

	configuredServer, err := agent.NewMTLSServer("127.0.0.1:0", *certificatePath, *privateKeyPath, *clientCAPath,
		agent.NewDataPlaneProbeHandler(*nodeID, echoExecutor{}))
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
