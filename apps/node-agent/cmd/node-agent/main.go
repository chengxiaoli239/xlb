package main

import (
	"context"
	"errors"
	"log"
	"net"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	agent "xlb/node-agent"
)

func main() {
	if err := run(); err != nil {
		log.Fatal(err)
	}
}

func run() error {
	if os.Getenv("NODE_AGENT_MODE") != "isolated-test" {
		return errors.New("NODE_AGENT_MODE must be isolated-test; production mode is not implemented")
	}
	nodeID := os.Getenv("NODE_AGENT_NODE_ID")
	statePath := os.Getenv("NODE_AGENT_STATE_PATH")
	certificatePath := os.Getenv("NODE_AGENT_SERVER_CERT")
	privateKeyPath := os.Getenv("NODE_AGENT_SERVER_KEY")
	clientCAPath := os.Getenv("NODE_AGENT_CLIENT_CA")
	if nodeID == "" || statePath == "" || certificatePath == "" || privateKeyPath == "" || clientCAPath == "" {
		return errors.New("node agent identity, state, and mTLS files are required")
	}

	address := os.Getenv("NODE_AGENT_LISTEN")
	if address == "" {
		address = "127.0.0.1:9443"
	}
	if err := validateLoopbackAddress(address); err != nil {
		return err
	}
	capacity, err := strconv.Atoi(os.Getenv("NODE_AGENT_CAPACITY"))
	if err != nil || capacity < 1 || capacity > 4096 {
		return errors.New("NODE_AGENT_CAPACITY must be between 1 and 4096")
	}
	store, err := agent.OpenPeerStore(statePath, nodeID, capacity)
	if err != nil {
		return err
	}
	processLock, err := acquireProcessLock(statePath + ".service.lock")
	if err != nil {
		return err
	}
	defer processLock.Close()

	dataAddress := os.Getenv("NODE_AGENT_DATA_LISTEN")
	if dataAddress == "" {
		dataAddress = "127.0.0.1:9444"
	}
	if err := validateLoopbackAddress(dataAddress); err != nil {
		return err
	}
	dataPlane, err := agent.NewDataPlane(store, agent.NewPublicTargetDialer())
	if err != nil {
		return err
	}
	server, err := agent.NewMTLSServer(address, certificatePath, privateKeyPath, clientCAPath, agent.NewHTTPHandler(store, dataPlane))
	if err != nil {
		return err
	}
	dataServer, err := agent.NewDataPlaneServer(dataAddress, certificatePath, privateKeyPath, dataPlane)
	if err != nil {
		return err
	}

	serveError := make(chan error, 1)
	go func() {
		serveError <- server.ListenAndServeTLS("", "")
	}()
	go func() {
		serveError <- dataServer.ListenAndServeTLS("", "")
	}()
	signals := make(chan os.Signal, 1)
	signal.Notify(signals, syscall.SIGINT, syscall.SIGTERM)
	select {
	case err := <-serveError:
		if errors.Is(err, net.ErrClosed) {
			return nil
		}
		return err
	case <-signals:
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		adminErr := server.Shutdown(ctx)
		dataErr := dataServer.Shutdown(ctx)
		if adminErr != nil {
			_ = server.Close()
			return adminErr
		}
		if dataErr != nil {
			_ = dataServer.Close()
			return dataErr
		}
		return nil
	}
}

func acquireProcessLock(path string) (*os.File, error) {
	file, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return nil, err
	}
	if err := file.Chmod(0o600); err != nil {
		file.Close()
		return nil, err
	}
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		file.Close()
		return nil, errors.New("another node agent process already owns this state directory")
	}
	return file, nil
}

func validateLoopbackAddress(address string) error {
	host, _, err := net.SplitHostPort(address)
	if err != nil {
		return errors.New("NODE_AGENT_LISTEN must be a loopback host and port")
	}
	if strings.EqualFold(host, "localhost") {
		return nil
	}
	ip := net.ParseIP(host)
	if ip == nil || !ip.IsLoopback() {
		return errors.New("node agent must bind only to loopback; remote access uses SSM port forwarding")
	}
	return nil
}
