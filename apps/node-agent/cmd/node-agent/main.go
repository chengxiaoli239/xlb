package main

import (
	"context"
	"crypto/tls"
	"crypto/x509"
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
	environmentCertificatePath := os.Getenv("NODE_AGENT_SERVER_CERT")
	environmentPrivateKeyPath := os.Getenv("NODE_AGENT_SERVER_KEY")
	environmentClientCAPath := os.Getenv("NODE_AGENT_CLIENT_CA")
	if nodeID == "" || runtimeConfigPath == "" || statePath == "" {
		return errors.New("node agent identity, runtime config, and state path are required")
	}
	runtimeConfig, err := readRuntimeConfig(runtimeConfigPath, nodeID)
	if err != nil {
		return err
	}
	certificatePath, privateKeyPath, clientCAPath, err := resolveNodeTLSCertificateFiles(
		runtimeConfig, environmentCertificatePath, environmentPrivateKeyPath, environmentClientCAPath,
	)
	if err != nil {
		return err
	}
	listeners, err := resolveNodeAgentListenAddresses(
		runtimeConfig,
		os.Getenv("NODE_AGENT_LISTEN"),
		os.Getenv("NODE_AGENT_DATA_LISTEN"),
		os.Getenv("NODE_AGENT_PROBE_LISTEN"),
	)
	if err != nil {
		return err
	}
	identitySource, err := resolveNodeTLSIdentitySource(
		runtimeConfig,
		os.Getenv("NODE_AGENT_TLS_IDENTITY_SOURCE"),
		os.Getenv("NODE_AGENT_TLS_KMS_KEY_ID"),
	)
	if err != nil {
		return err
	}
	identity, err := loadNodeTLSIdentity(
		nodeID,
		identitySource.source,
		certificatePath,
		privateKeyPath,
		clientCAPath,
		identitySource.keyARN,
		identitySource.region,
	)
	if err != nil {
		return err
	}

	capacity, err := strconv.Atoi(os.Getenv("NODE_AGENT_CAPACITY"))
	if err != nil || capacity < 1 || capacity > 4096 {
		return errors.New("NODE_AGENT_CAPACITY must be between 1 and 4096")
	}
	store, err := openPeerStoreForRuntimeConfig(statePath, nodeID, capacity, runtimeConfig)
	if err != nil {
		return err
	}
	processLock, err := acquireProcessLock(statePath + ".service.lock")
	if err != nil {
		return err
	}
	defer processLock.Close()

	servers, err := buildRuntimeHTTPServers(context.Background(), runtimeConfig, listeners, store, identity)
	if err != nil {
		return err
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

type nodeTLSIdentitySelection struct {
	source string
	keyARN string
	region string
}

type nodeAgentListenAddresses struct {
	management string
	dataPlane  string
	probe      string
}

func buildRuntimeHTTPServers(ctx context.Context, runtimeConfig agent.TrustedNodeRuntimeConfig,
	listeners nodeAgentListenAddresses, store *agent.PeerStore, identity agent.NodeTLSIdentity,
) ([]*http.Server, error) {
	var resolvedListeners nodeAgentListenAddresses
	var err error
	switch runtimeConfig.Environment {
	case agent.RuntimeEnvironmentTest:
		resolvedListeners, err = resolveNodeAgentListenAddresses(
			runtimeConfig, listeners.management, listeners.dataPlane, listeners.probe,
		)
	case agent.RuntimeEnvironmentProd:
		resolvedListeners, err = resolveNodeAgentListenAddresses(runtimeConfig, "", "", "")
	default:
		return nil, agent.ErrInvalidNodeRuntimeConfig
	}
	if err != nil || resolvedListeners != listeners {
		return nil, agent.ErrInvalidNodeRuntimeConfig
	}
	if err := validateProbeListenerAddress(listeners.probe, listeners.management, listeners.dataPlane); err != nil {
		return nil, err
	}
	dataPlane, err := agent.NewRuntimeDataPlane(ctx, store, agent.NewPublicTargetDialer(), identity, runtimeConfig)
	if err != nil {
		return nil, err
	}
	managementServer, err := agent.NewMTLSServerWithNodeTLSIdentity(
		listeners.management, identity, agent.NewHTTPHandler(store, dataPlane),
	)
	if err != nil {
		return nil, err
	}
	dataPlaneServer, err := agent.NewDataPlaneServerWithNodeTLSIdentity(
		listeners.dataPlane, identity.Certificate, dataPlane,
	)
	if err != nil {
		return nil, err
	}
	servers := []*http.Server{managementServer, dataPlaneServer}
	probeServer, err := newDataPlaneProbeServer(
		listeners.probe, runtimeConfig.Node.ID, identity, runtimeConfig.DataPlaneProbe,
	)
	if err != nil {
		return nil, err
	}
	if probeServer != nil {
		servers = append(servers, probeServer)
	}
	return servers, nil
}

func resolveNodeTLSCertificateFiles(config agent.TrustedNodeRuntimeConfig,
	environmentCertificatePath, environmentPrivateKeyPath, environmentClientCAPath string,
) (string, string, string, error) {
	switch config.Environment {
	case agent.RuntimeEnvironmentTest:
		if config.SchemaVersion != agent.TrustedNodeRuntimeSchemaV1 ||
			config.NodeTLSIdentity.CertificateChainPath != "" || config.NodeTLSIdentity.ClientCAPEMPath != "" {
			return "", "", "", agent.ErrInvalidNodeRuntimeConfig
		}
		return environmentCertificatePath, environmentPrivateKeyPath, environmentClientCAPath, nil
	case agent.RuntimeEnvironmentProd:
		if config.SchemaVersion != agent.TrustedNodeRuntimeSchemaV2 || environmentCertificatePath != "" ||
			environmentPrivateKeyPath != "" || environmentClientCAPath != "" ||
			config.Validate() != nil {
			return "", "", "", agent.ErrInvalidNodeRuntimeConfig
		}
		return config.NodeTLSIdentity.CertificateChainPath, "", config.NodeTLSIdentity.ClientCAPEMPath, nil
	default:
		return "", "", "", agent.ErrInvalidNodeRuntimeConfig
	}
}

func resolveNodeAgentListenAddresses(config agent.TrustedNodeRuntimeConfig,
	environmentManagementAddress, environmentDataPlaneAddress, environmentProbeAddress string,
) (nodeAgentListenAddresses, error) {
	switch config.Environment {
	case agent.RuntimeEnvironmentTest:
		if config.SchemaVersion != agent.TrustedNodeRuntimeSchemaV1 ||
			config.AgentListeners != (agent.AgentListenerRuntimeConfig{}) {
			return nodeAgentListenAddresses{}, agent.ErrInvalidNodeRuntimeConfig
		}
		managementAddress := environmentManagementAddress
		if managementAddress == "" {
			managementAddress = "127.0.0.1:9443"
		}
		dataPlaneAddress := environmentDataPlaneAddress
		if dataPlaneAddress == "" {
			dataPlaneAddress = "127.0.0.1:9444"
		}
		if err := validateLoopbackAddress(managementAddress); err != nil {
			return nodeAgentListenAddresses{}, err
		}
		if err := validateLoopbackAddress(dataPlaneAddress); err != nil {
			return nodeAgentListenAddresses{}, err
		}
		if err := validateProbeListenerAddress(environmentProbeAddress, managementAddress, dataPlaneAddress); err != nil {
			return nodeAgentListenAddresses{}, err
		}
		return nodeAgentListenAddresses{
			management: managementAddress,
			dataPlane:  dataPlaneAddress,
			probe:      environmentProbeAddress,
		}, nil
	case agent.RuntimeEnvironmentProd:
		if config.SchemaVersion != agent.TrustedNodeRuntimeSchemaV2 ||
			environmentManagementAddress != "" || environmentDataPlaneAddress != "" || environmentProbeAddress != "" ||
			config.Validate() != nil {
			return nodeAgentListenAddresses{}, agent.ErrInvalidNodeRuntimeConfig
		}
		return nodeAgentListenAddresses{
			management: config.AgentListeners.ManagementListenAddress,
			dataPlane:  config.AgentListeners.DataPlaneListenAddress,
		}, nil
	default:
		return nodeAgentListenAddresses{}, agent.ErrInvalidNodeRuntimeConfig
	}
}

func resolveNodeTLSIdentitySource(
	runtimeConfig agent.TrustedNodeRuntimeConfig,
	environmentSource string,
	environmentKeyARN string,
) (nodeTLSIdentitySelection, error) {
	switch runtimeConfig.Environment {
	case agent.RuntimeEnvironmentTest:
		if runtimeConfig.SchemaVersion != agent.TrustedNodeRuntimeSchemaV1 ||
			runtimeConfig.NodeTLSIdentity.Source != "" || runtimeConfig.NodeTLSIdentity.KeyARN != "" {
			return nodeTLSIdentitySelection{}, agent.ErrInvalidNodeRuntimeConfig
		}
		if environmentSource == "" {
			environmentSource = "test-files"
		}
		if environmentSource == "test-files" && environmentKeyARN == "" {
			return nodeTLSIdentitySelection{source: environmentSource}, nil
		}
		if environmentSource == "aws-kms" && agent.ValidKMSKeyARN(environmentKeyARN) &&
			kmsKeyARNRegion(environmentKeyARN) == runtimeConfig.Node.Region {
			return nodeTLSIdentitySelection{
				source: environmentSource,
				keyARN: environmentKeyARN,
				region: runtimeConfig.Node.Region,
			}, nil
		}
		return nodeTLSIdentitySelection{}, agent.ErrInvalidNodeRuntimeConfig
	case agent.RuntimeEnvironmentProd:
		if runtimeConfig.SchemaVersion != agent.TrustedNodeRuntimeSchemaV2 ||
			runtimeConfig.NodeTLSIdentity.Source != "aws-kms" ||
			!agent.ValidKMSKeyARN(runtimeConfig.NodeTLSIdentity.KeyARN) ||
			environmentSource != "" || environmentKeyARN != "" {
			return nodeTLSIdentitySelection{}, agent.ErrInvalidNodeRuntimeConfig
		}
		return nodeTLSIdentitySelection{
			source: runtimeConfig.NodeTLSIdentity.Source,
			keyARN: runtimeConfig.NodeTLSIdentity.KeyARN,
			region: runtimeConfig.Node.Region,
		}, nil
	default:
		return nodeTLSIdentitySelection{}, agent.ErrInvalidNodeRuntimeConfig
	}
}

func kmsKeyARNRegion(keyARN string) string {
	parts := strings.SplitN(keyARN, ":", 6)
	if len(parts) != 6 {
		return ""
	}
	return parts[3]
}

func newDataPlaneProbeServer(address, nodeID string, identity agent.NodeTLSIdentity,
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
	return agent.NewMTLSServerWithNodeTLSIdentity(address, identity,
		agent.NewDataPlaneProbeHandler(nodeID, executor))
}

func loadNodeTLSIdentity(nodeID, source, certificatePath, privateKeyPath, clientCAPath, kmsKeyID, region string) (agent.NodeTLSIdentity, error) {
	if source == "" || source == "test-files" {
		if certificatePath == "" || privateKeyPath == "" || clientCAPath == "" {
			return agent.NodeTLSIdentity{}, errors.New("isolated-test file-backed mTLS certificate and client CA are required")
		}
		certificate, err := tls.LoadX509KeyPair(certificatePath, privateKeyPath)
		if err != nil {
			return agent.NodeTLSIdentity{}, errors.New("load isolated-test node certificate")
		}
		clientCAPEM, err := os.ReadFile(clientCAPath)
		if err != nil {
			return agent.NodeTLSIdentity{}, errors.New("load isolated-test client CA bundle")
		}
		clientCAs := x509.NewCertPool()
		if !clientCAs.AppendCertsFromPEM(clientCAPEM) {
			return agent.NodeTLSIdentity{}, errors.New("isolated-test client CA bundle is invalid")
		}
		return agent.NodeTLSIdentity{Certificate: certificate, ClientCAs: clientCAs}, nil
	}
	if source != "aws-kms" || kmsKeyID == "" || certificatePath == "" || clientCAPath == "" || privateKeyPath != "" {
		return agent.NodeTLSIdentity{}, errors.New("node TLS identity source configuration is invalid")
	}
	client, err := agent.NewAWSKMSAsymmetricKeyClient(context.Background(), region)
	if err != nil {
		return agent.NodeTLSIdentity{}, errors.New("initialize AWS KMS node identity client")
	}
	certificates, err := agent.NewPEMNodeTLSCertificateProvider(map[string]agent.NodeTLSCertificateFiles{
		nodeID: {CertificateChainPath: certificatePath, ClientCAPEMPath: clientCAPath},
	})
	if err != nil {
		return agent.NodeTLSIdentity{}, errors.New("configure public node TLS certificate files")
	}
	identitySource, err := agent.NewKMSNodeTLSIdentitySource(
		client,
		certificates,
		map[string]string{nodeID: kmsKeyID},
		5*time.Second,
	)
	if err != nil {
		return agent.NodeTLSIdentity{}, errors.New("configure AWS KMS node TLS identity")
	}
	identity, err := agent.LoadNodeTLSIdentity(context.Background(), nodeID, identitySource)
	if err != nil {
		return agent.NodeTLSIdentity{}, errors.New("load AWS KMS node TLS identity")
	}
	return identity, nil
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
	pathInfo, err := os.Lstat(path)
	if err != nil || !pathInfo.Mode().IsRegular() || pathInfo.Mode()&os.ModeSymlink != 0 || pathInfo.Mode().Perm()&0o022 != 0 {
		return agent.TrustedNodeRuntimeConfig{}, errors.New("NODE_AGENT_RUNTIME_CONFIG is not a protected regular file")
	}
	file, err := os.Open(path)
	if err != nil {
		return agent.TrustedNodeRuntimeConfig{}, errors.New("NODE_AGENT_RUNTIME_CONFIG could not be opened")
	}
	defer file.Close()
	openedInfo, err := file.Stat()
	if err != nil || !os.SameFile(pathInfo, openedInfo) || !openedInfo.Mode().IsRegular() || openedInfo.Mode().Perm()&0o022 != 0 {
		return agent.TrustedNodeRuntimeConfig{}, errors.New("NODE_AGENT_RUNTIME_CONFIG changed while opening")
	}

	config, err := agent.DecodeTrustedNodeRuntimeConfig(file)
	if err != nil {
		return agent.TrustedNodeRuntimeConfig{}, err
	}
	if config.Environment != agent.RuntimeEnvironmentTest || config.Node.ID != nodeID {
		return agent.TrustedNodeRuntimeConfig{}, agent.ErrInvalidNodeRuntimeConfig
	}

	return config, nil
}

func openPeerStoreForRuntimeConfig(path, nodeID string, capacity int, config agent.TrustedNodeRuntimeConfig) (*agent.PeerStore, error) {
	switch config.ProfileRenderer.Status {
	case agent.RuntimeProfileUnavailable:
		return agent.OpenPeerStore(path, nodeID, capacity)
	case agent.RuntimeProfileMihomoBridgeTest:
		renderer, err := agent.NewMihomoBridgeProfileRenderer(
			config.ProofBridge.ListenAddress,
			config.ProfileRenderer.BridgeEndpoint,
			config.ProfileRenderer.BridgeServerCAPEM,
		)
		if err != nil {
			return nil, agent.ErrInvalidNodeRuntimeConfig
		}
		return agent.OpenPeerStoreWithTestProfileRenderer(path, nodeID, capacity, renderer)
	case agent.RuntimeProfileMihomoBridge:
		renderer, err := agent.NewMihomoBridgeProfileRenderer(
			config.ProofBridge.ListenAddress,
			config.ProfileRenderer.BridgeEndpoint,
			config.ProfileRenderer.BridgeServerCAPEM,
		)
		if err != nil {
			return nil, agent.ErrInvalidNodeRuntimeConfig
		}
		return agent.OpenPeerStoreWithProfileRenderer(path, nodeID, capacity, renderer)
	default:
		return nil, agent.ErrInvalidNodeRuntimeConfig
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
