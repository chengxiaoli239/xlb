package main

import (
	"context"
	"errors"
	"log"
	"net"
	"net/http"
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
	runtimeConfigPath := os.Getenv("NODE_AGENT_RUNTIME_CONFIG")
	statePath := os.Getenv("NODE_AGENT_STATE_PATH")
	certificatePath := os.Getenv("NODE_AGENT_SERVER_CERT")
	privateKeyPath := os.Getenv("NODE_AGENT_SERVER_KEY")
	clientCAPath := os.Getenv("NODE_AGENT_CLIENT_CA")
	if nodeID == "" || runtimeConfigPath == "" || statePath == "" || certificatePath == "" || privateKeyPath == "" || clientCAPath == "" {
		return errors.New("node agent identity, state, and mTLS files are required")
	}
	runtimeConfig, err := readRuntimeConfig(runtimeConfigPath, nodeID)
	if err != nil {
		return err
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
	probeAddress := os.Getenv("NODE_AGENT_PROBE_LISTEN")
	if err := validateProbeListenerAddress(probeAddress, address, dataAddress); err != nil {
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
	probeServer, err := newDataPlaneProbeServer(
		probeAddress, nodeID, certificatePath, privateKeyPath, clientCAPath, runtimeConfig.DataPlaneProbe,
	)
	if err != nil {
		return err
	}

	servers := []*http.Server{server, dataServer}
	if probeServer != nil {
		servers = append(servers, probeServer)
	}
	serveError := make(chan error, len(servers))
	for _, httpServer := range servers {
		go func(server *http.Server) {
			serveError <- server.ListenAndServeTLS("", "")
		}(httpServer)
	}
	signals := make(chan os.Signal, 1)
	signal.Notify(signals, syscall.SIGINT, syscall.SIGTERM)
	select {
	case err := <-serveError:
		shutdownErr := shutdownServers(servers)
		if shutdownErr != nil {
			return shutdownErr
		}
		if errors.Is(err, net.ErrClosed) {
			return nil
		}
		return err
	case <-signals:
		return shutdownServers(servers)
	}
}

func newDataPlaneProbeServer(address, nodeID, certificatePath, privateKeyPath, clientCAPath string,
	probeConfig agent.DataPlaneProbeRuntimeConfig) (*http.Server, error) {
	if strings.TrimSpace(address) == "" {
		if probeConfig.Status == agent.RuntimeProbeHTTPSCanary {
			return nil, errors.New("data-plane canary requires NODE_AGENT_PROBE_LISTEN")
		}
		return nil, nil
	}
	if strings.TrimSpace(nodeID) == "" {
		return nil, errors.New("probe listener requires a node identity")
	}
	var executor agent.DataPlaneProbeExecutor
	if probeConfig.Status == agent.RuntimeProbeHTTPSCanary {
		var err error
		executor, err = agent.NewHTTPSCanaryProbeExecutor(probeConfig, agent.NewPublicTargetDialer())
		if err != nil {
			return nil, errors.New("configure node data-plane HTTPS canary")
		}
	}
	return agent.NewMTLSServer(address, certificatePath, privateKeyPath, clientCAPath,
		agent.NewDataPlaneProbeHandler(nodeID, executor))
}

func validateProbeListenerAddress(address, managementAddress, dataAddress string) error {
	if strings.TrimSpace(address) == "" {
		return nil
	}
	if err := validateLoopbackAddress(address); err != nil {
		return errors.New("NODE_AGENT_PROBE_LISTEN must be a distinct loopback address")
	}
	_, probePortValue, err := net.SplitHostPort(address)
	if err != nil {
		return errors.New("NODE_AGENT_PROBE_LISTEN must be a distinct loopback address")
	}
	probePort, err := strconv.Atoi(probePortValue)
	if err != nil || probePort < 1 || probePort > 65535 {
		return errors.New("NODE_AGENT_PROBE_LISTEN must be a distinct loopback address")
	}
	for _, existingAddress := range []string{managementAddress, dataAddress} {
		_, existingPortValue, splitErr := net.SplitHostPort(existingAddress)
		existingPort, portErr := strconv.Atoi(existingPortValue)
		if splitErr != nil || portErr != nil || probePort == existingPort {
			return errors.New("NODE_AGENT_PROBE_LISTEN must use a port distinct from the management and data listeners")
		}
	}
	return nil
}

func shutdownServers(servers []*http.Server) error {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	for _, server := range servers {
		if err := server.Shutdown(ctx); err != nil {
			for _, remaining := range servers {
				_ = remaining.Close()
			}
			return err
		}
	}
	return nil
}

func validateRuntimeConfig(path, nodeID string) error {
	_, err := readRuntimeConfig(path, nodeID)
	return err
}

func readRuntimeConfig(path, nodeID string) (agent.TrustedNodeRuntimeConfig, error) {
	file, err := os.Open(path)
	if err != nil {
		return agent.TrustedNodeRuntimeConfig{}, errors.New("NODE_AGENT_RUNTIME_CONFIG could not be opened")
	}
	defer file.Close()

	config, err := agent.DecodeTrustedNodeRuntimeConfig(file)
	if err != nil {
		return agent.TrustedNodeRuntimeConfig{}, err
	}
	if config.Environment != agent.RuntimeEnvironmentTest || config.Node.ID != nodeID ||
		config.ProfileRenderer.Status != agent.RuntimeProfileUnavailable {
		return agent.TrustedNodeRuntimeConfig{}, agent.ErrInvalidNodeRuntimeConfig
	}

	return config, nil
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
