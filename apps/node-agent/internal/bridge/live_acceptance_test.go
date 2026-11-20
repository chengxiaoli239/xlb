package bridge

import (
	"bufio"
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	agent "xlb/node-agent"
)

const liveAcceptanceFlag = "XLB_LIVE_NODE_ACCEPTANCE"

type liveAcceptanceConfig struct {
	nodeID        string
	controlURL    string
	controlDial   string
	dataURL       string
	dataDial      string
	caPath        string
	adminCert     string
	adminKey      string
	expectedSlots int
}

type livePeerIdentity struct {
	request     agent.EnsurePeerRequest
	identity    agent.DeviceCertificateIdentity
	privateKey  *ecdsa.PrivateKey
	certificate tls.Certificate
	certPath    string
	keyPath     string
}

type liveRuntimeSnapshot struct {
	ActiveSessions int `json:"active_sessions"`
	Capacity       int `json:"capacity"`
}

func TestLiveNodeProtocolAcceptance(t *testing.T) {
	if os.Getenv(liveAcceptanceFlag) != "1" {
		t.Skip("live isolated-node acceptance is opt-in and requires active SSM port forwards")
	}
	config := readLiveAcceptanceConfig(t)
	adminClient, adminTransport := liveAdminClient(t, config)
	defer adminTransport.CloseIdleConnections()
	peers := createLivePeers(t, config, adminClient, 12)
	runLiveProcessCapacityCompetition(t, config, peers, config.expectedSlots)
	waitForLiveSessions(t, adminClient, config, 0, 15*time.Second)
	assertLiveDeviceProofAndRevocation(t, config, adminClient, peers[0], peers[1])
}

func TestLiveNodeCapacityProcessChild(t *testing.T) {
	if os.Getenv("XLB_LIVE_CHILD") != "1" {
		return
	}
	dataURL := requiredEnvironment(t, "XLB_LIVE_CHILD_DATA_URL")
	dialAddress := requiredEnvironment(t, "XLB_LIVE_CHILD_DATA_DIAL")
	caPath := requiredEnvironment(t, "XLB_LIVE_CHILD_CA")
	certificatePath := requiredEnvironment(t, "XLB_LIVE_CHILD_CERT")
	privateKeyPath := requiredEnvironment(t, "XLB_LIVE_CHILD_KEY")
	statusPath := requiredEnvironment(t, "XLB_LIVE_CHILD_STATUS")
	releasePath := requiredEnvironment(t, "XLB_LIVE_CHILD_RELEASE")
	certificate, err := tls.LoadX509KeyPair(certificatePath, privateKeyPath)
	if err != nil {
		t.Fatal("load live data-plane device identity")
	}
	caPEM, err := os.ReadFile(caPath)
	if err != nil {
		t.Fatal("read live node CA")
	}
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM(caPEM) {
		t.Fatal("load live node CA")
	}
	remote, err := url.Parse(dataURL)
	if err != nil {
		t.Fatal("parse live node URL")
	}
	transport := &http.Transport{
		TLSClientConfig:   &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: roots, ServerName: remote.Hostname(), Certificates: []tls.Certificate{certificate}},
		ForceAttemptHTTP2: true, MaxConnsPerHost: 1,
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			return (&net.Dialer{Timeout: 10 * time.Second}).DialContext(ctx, network, dialAddress)
		},
	}
	defer transport.CloseIdleConnections()
	reader, writer := io.Pipe()
	query := url.Values{"host": []string{"checkip.amazonaws.com"}, "port": []string{"443"}}
	request, err := http.NewRequest(http.MethodPost, strings.TrimSuffix(dataURL, "/")+"/v1/tunnel/connect?"+query.Encode(), reader)
	if err != nil {
		t.Fatal(err)
	}
	request.ContentLength = -1
	response, err := (&http.Client{Transport: transport}).Do(request)
	if err != nil {
		_ = writer.CloseWithError(err)
		t.Fatal("connect to isolated node data plane")
	}
	defer response.Body.Close()
	if response.ProtoMajor != 2 {
		_ = writer.Close()
		t.Fatalf("live data plane negotiated HTTP/%d; want HTTP/2", response.ProtoMajor)
	}
	if err := os.WriteFile(statusPath, []byte(strconv.Itoa(response.StatusCode)), 0o600); err != nil {
		_ = writer.Close()
		t.Fatal("write live process result")
	}
	if response.StatusCode == http.StatusConflict {
		_ = writer.Close()
		return
	}
	if response.StatusCode != http.StatusOK {
		_ = writer.Close()
		t.Fatalf("unexpected live data-plane status %d", response.StatusCode)
	}
	if !waitForFile(releasePath, 45*time.Second) {
		_ = writer.Close()
		t.Fatal("timed out waiting for live concurrency barrier release")
	}
	_ = writer.Close()
}

func readLiveAcceptanceConfig(t *testing.T) liveAcceptanceConfig {
	t.Helper()
	capacity, err := strconv.Atoi(os.Getenv("XLB_LIVE_EXPECTED_CAPACITY"))
	if os.Getenv("XLB_LIVE_EXPECTED_CAPACITY") == "" {
		capacity = 3
	} else if err != nil || capacity < 1 || capacity > 4096 {
		t.Fatal("XLB_LIVE_EXPECTED_CAPACITY must be between 1 and 4096")
	}
	config := liveAcceptanceConfig{
		nodeID:        requiredEnvironment(t, "XLB_LIVE_NODE_ID"),
		controlURL:    requiredEnvironment(t, "XLB_LIVE_CONTROL_URL"),
		controlDial:   requiredEnvironment(t, "XLB_LIVE_CONTROL_DIAL"),
		dataURL:       requiredEnvironment(t, "XLB_LIVE_DATA_URL"),
		dataDial:      requiredEnvironment(t, "XLB_LIVE_DATA_DIAL"),
		caPath:        requiredEnvironment(t, "XLB_LIVE_NODE_CA"),
		adminCert:     requiredEnvironment(t, "XLB_LIVE_ADMIN_CERT"),
		adminKey:      requiredEnvironment(t, "XLB_LIVE_ADMIN_KEY"),
		expectedSlots: capacity,
	}
	if err := validateLiveAcceptanceConfig(config); err != nil {
		t.Fatal(err)
	}
	return config
}

func validateLiveAcceptanceConfig(config liveAcceptanceConfig) error {
	if !strings.HasPrefix(config.nodeID, "xlb-lab-") {
		return errors.New("live acceptance node ID must start with xlb-lab-")
	}
	for _, endpoint := range []struct {
		name string
		url  string
		dial string
	}{{"control", config.controlURL, config.controlDial}, {"data", config.dataURL, config.dataDial}} {
		remote, err := url.Parse(endpoint.url)
		if err != nil || remote.Scheme != "https" || remote.Hostname() == "" || remote.Port() == "" || remote.User != nil {
			return fmt.Errorf("live %s endpoint must be an HTTPS URL with an explicit port", endpoint.name)
		}
		if !isLoopbackHost(remote.Hostname()) {
			return fmt.Errorf("live %s endpoint hostname must be localhost or a loopback IP", endpoint.name)
		}
		dialHost, _, err := net.SplitHostPort(endpoint.dial)
		if err != nil || !isLoopbackHost(dialHost) {
			return fmt.Errorf("live %s dial address must be a loopback host and port", endpoint.name)
		}
	}
	if config.controlDial == config.dataDial {
		return errors.New("live control and data port forwards must be different")
	}
	return nil
}

func TestLiveAcceptanceConfigRejectsNonLoopbackTargets(t *testing.T) {
	config := liveAcceptanceConfig{
		nodeID: "xlb-lab-unit-test", controlURL: "https://localhost:19443", controlDial: "127.0.0.1:19443",
		dataURL: "https://localhost:19444", dataDial: "127.0.0.1:19444", expectedSlots: 3,
	}
	if err := validateLiveAcceptanceConfig(config); err != nil {
		t.Fatalf("valid SSM loopback configuration rejected: %v", err)
	}
	config.controlURL = "https://production.example:19443"
	if err := validateLiveAcceptanceConfig(config); err == nil {
		t.Fatal("non-loopback control URL was accepted")
	}
	config.controlURL = "https://localhost:19443"
	config.dataDial = "198.51.100.10:19444"
	if err := validateLiveAcceptanceConfig(config); err == nil {
		t.Fatal("non-loopback data dial target was accepted")
	}
}

func createLivePeers(t *testing.T, config liveAcceptanceConfig, client *http.Client, count int) []livePeerIdentity {
	t.Helper()
	identities := make([]livePeerIdentity, count)
	identityDirectory := t.TempDir()
	for index := range count {
		suffix := fmt.Sprintf("%02d-%s", index, liveNonce())
		peerID := "peer-" + suffix
		deviceID := "device-" + suffix
		key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		if err != nil {
			t.Fatal(err)
		}
		publicDER, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
		if err != nil {
			t.Fatal(err)
		}
		request := agent.EnsurePeerRequest{
			NodeID: config.nodeID, PeerID: peerID, EntitlementID: "isolated-live-test-entitlement",
			RequestID: liveNonce(), Generation: 1,
			DeviceID: deviceID, DeviceName: "Isolated live test device",
			DevicePublicKey:         base64.RawURLEncoding.EncodeToString(publicDER),
			DeviceKeyFingerprint:    fmt.Sprintf("%x", sha256Sum(publicDER)),
			DeviceBindingGeneration: 1, PeerGeneration: 1, DeviceGeneration: 1,
			ExpiresAt: time.Now().UTC().Add(30 * time.Minute), Credential: liveNonce(),
		}
		var provisionReceipt agent.EnsurePeerReceipt
		postLiveControl(t, client, config.controlURL, "/v1/member-peers/ensure", request, &provisionReceipt)
		if provisionReceipt.NodeID != config.nodeID || provisionReceipt.PeerID != peerID || provisionReceipt.ConfirmationStatus != "confirmed_test" {
			t.Fatalf("node returned a mismatched isolated peer receipt: %+v", provisionReceipt)
		}
		subscriptionID := "subscription-" + suffix
		binding := agent.BindSubscriptionRequest{
			NodeID: config.nodeID, PeerID: peerID, RequestID: liveNonce(), SubscriptionID: subscriptionID,
			SubscriptionToken: liveNonce(), SubscriptionGeneration: 1, PeerGeneration: 1, DeviceGeneration: 1,
		}
		var bindingReceipt agent.SubscriptionBindingReceipt
		postLiveControl(t, client, config.controlURL, "/v1/member-peers/bind-subscription", binding, &bindingReceipt)
		if bindingReceipt.PeerID != peerID || bindingReceipt.SubscriptionID != subscriptionID || bindingReceipt.ConfirmationStatus != "confirmed_test" {
			t.Fatalf("node returned a mismatched isolated subscription receipt: %+v", bindingReceipt)
		}
		identity := agent.DeviceCertificateIdentity{
			NodeID: config.nodeID, PeerID: peerID, DeviceID: deviceID, SubscriptionID: subscriptionID,
			SubscriptionGeneration: 1, PeerGeneration: 1, DeviceGeneration: 1,
		}
		certificate, err := agent.NewDeviceCertificate(identity, key)
		if err != nil {
			t.Fatal(err)
		}
		certificatePath := filepath.Join(identityDirectory, peerID+".crt")
		keyPath := filepath.Join(identityDirectory, peerID+".key")
		privateDER, err := x509.MarshalPKCS8PrivateKey(key)
		if err != nil {
			t.Fatal(err)
		}
		writeTestIdentityFile(t, certificatePath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: certificate.Certificate[0]}))
		writeTestIdentityFile(t, keyPath, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateDER}))
		identities[index] = livePeerIdentity{
			request: request, identity: identity, privateKey: key, certificate: certificate,
			certPath: certificatePath, keyPath: keyPath,
		}
	}
	return identities
}

func runLiveProcessCapacityCompetition(t *testing.T, config liveAcceptanceConfig, identities []livePeerIdentity, expected int) {
	t.Helper()
	barrierDirectory := t.TempDir()
	releasePath := filepath.Join(barrierDirectory, "release")
	type childProcess struct {
		command *exec.Cmd
		wait    chan error
		status  string
	}
	children := make([]childProcess, len(identities))
	t.Cleanup(func() {
		_ = os.WriteFile(releasePath, nil, 0o600)
		for _, child := range children {
			if child.command != nil && child.command.Process != nil {
				select {
				case <-child.wait:
				default:
					_ = child.command.Process.Kill()
				}
			}
		}
	})
	for index, identity := range identities {
		statusPath := filepath.Join(barrierDirectory, fmt.Sprintf("child-%02d.status", index))
		command := exec.Command(os.Args[0], "-test.run=^TestLiveNodeCapacityProcessChild$")
		command.Stdout, command.Stderr = io.Discard, io.Discard
		command.Env = append(os.Environ(),
			"XLB_LIVE_CHILD=1", "XLB_LIVE_CHILD_DATA_URL="+config.dataURL,
			"XLB_LIVE_CHILD_DATA_DIAL="+config.dataDial, "XLB_LIVE_CHILD_CA="+config.caPath,
			"XLB_LIVE_CHILD_CERT="+identity.certPath, "XLB_LIVE_CHILD_KEY="+identity.keyPath,
			"XLB_LIVE_CHILD_STATUS="+statusPath, "XLB_LIVE_CHILD_RELEASE="+releasePath,
		)
		if err := command.Start(); err != nil {
			t.Fatal(err)
		}
		wait := make(chan error, 1)
		go func(command *exec.Cmd) { wait <- command.Wait() }(command)
		children[index] = childProcess{command: command, wait: wait, status: statusPath}
	}
	deadline := time.Now().Add(45 * time.Second)
	statuses := make([]string, len(children))
	for index, child := range children {
		for time.Now().Before(deadline) {
			value, err := os.ReadFile(child.status)
			if err == nil {
				statuses[index] = strings.TrimSpace(string(value))
				break
			}
			if !errors.Is(err, os.ErrNotExist) {
				t.Fatal(err)
			}
			time.Sleep(25 * time.Millisecond)
		}
		if statuses[index] == "" {
			t.Fatalf("live child %d did not report before the timeout", index)
		}
	}
	accepted, rejected := 0, 0
	for _, status := range statuses {
		switch status {
		case strconv.Itoa(http.StatusOK):
			accepted++
		case strconv.Itoa(http.StatusConflict):
			rejected++
		default:
			t.Fatalf("unexpected live child status %q", status)
		}
	}
	if accepted != expected || rejected != len(children)-expected {
		t.Fatalf("live node accepted=%d capacity-rejected=%d; want %d and %d", accepted, rejected, expected, len(children)-expected)
	}
	if err := os.WriteFile(releasePath, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	for _, child := range children {
		select {
		case err := <-child.wait:
			if err != nil {
				t.Fatalf("live capacity child failed: %v", err)
			}
		case <-time.After(10 * time.Second):
			t.Fatal("live capacity child did not exit after the barrier released")
		}
	}
}

func assertLiveDeviceProofAndRevocation(t *testing.T, config liveAcceptanceConfig, adminClient *http.Client, first, second livePeerIdentity) {
	t.Helper()
	assertLiveSessionCount(t, adminClient, config, 0)
	roots := loadLiveRoots(t, config.caPath)
	firstBridge, firstListener := startLiveBridge(t, config, first.certificate, roots)
	firstConnection, reply := connectSOCKS(t, firstListener.Addr().String(), "checkip.amazonaws.com", 443)
	if reply != 0 {
		t.Fatalf("first device did not establish its test tunnel; SOCKS reply=%d", reply)
	}
	assertLiveSessionCount(t, adminClient, config, 1)
	secure := tls.Client(firstConnection, &tls.Config{ServerName: "checkip.amazonaws.com", MinVersion: tls.VersionTLS13})
	if err := secure.Handshake(); err != nil {
		t.Fatal("first proof-capable device TLS handshake failed")
	}
	if _, err := io.WriteString(secure, "GET / HTTP/1.1\r\nHost: checkip.amazonaws.com\r\nConnection: keep-alive\r\n\r\n"); err != nil {
		t.Fatal("write isolated egress probe")
	}
	response, err := http.ReadResponse(bufio.NewReader(secure), &http.Request{Method: http.MethodGet})
	if err != nil {
		t.Fatal("read isolated egress probe response")
	}
	body, err := io.ReadAll(io.LimitReader(response.Body, 2048))
	response.Body.Close()
	if err != nil || response.StatusCode != http.StatusOK || len(bytes.TrimSpace(body)) == 0 {
		t.Fatalf("isolated node egress response status=%d bytes=%d err=%v", response.StatusCode, len(body), err)
	}

	spoofedCertificate, err := agent.NewDeviceCertificate(first.identity, second.privateKey)
	if err != nil {
		t.Fatal(err)
	}
	assertLiveCapacityAvailable(t, adminClient, config)
	simultaneousStatus := requestLiveDataPlane(t, config, spoofedCertificate, roots)
	if simultaneousStatus != http.StatusForbidden {
		t.Fatalf("copied identity while first peer is online returned HTTP %d; want proof denial %d", simultaneousStatus, http.StatusForbidden)
	}
	assertLiveSessionCount(t, adminClient, config, 1)

	_ = secure.Close()
	_ = firstBridge.Close()
	waitForLiveSessions(t, adminClient, config, 0, 15*time.Second)
	secondBridge, secondListener := startLiveBridge(t, config, second.certificate, roots)
	secondConnection, ownReply := connectSOCKS(t, secondListener.Addr().String(), "checkip.amazonaws.com", 443)
	if ownReply != 0 {
		_ = secondBridge.Close()
		t.Fatalf("second device could not use its own peer after first disconnected; SOCKS reply=%d", ownReply)
	}
	assertLiveSessionCount(t, adminClient, config, 1)
	_ = secondConnection.Close()
	_ = secondBridge.Close()
	waitForLiveSessions(t, adminClient, config, 0, 15*time.Second)

	assertLiveCapacityAvailable(t, adminClient, config)
	staggeredStatus := requestLiveDataPlane(t, config, spoofedCertificate, roots)
	if staggeredStatus != http.StatusForbidden {
		t.Fatalf("copied identity after first peer disconnected returned HTTP %d; want proof denial %d", staggeredStatus, http.StatusForbidden)
	}
	assertLiveSessionCount(t, adminClient, config, 0)

	firstBridge, firstListener = startLiveBridge(t, config, first.certificate, roots)
	firstConnection, reply = connectSOCKS(t, firstListener.Addr().String(), "checkip.amazonaws.com", 443)
	if reply != 0 {
		t.Fatalf("first device could not reconnect before revocation; SOCKS reply=%d", reply)
	}
	assertLiveSessionCount(t, adminClient, config, 1)
	secure = tls.Client(firstConnection, &tls.Config{ServerName: "checkip.amazonaws.com", MinVersion: tls.VersionTLS13})
	if err := secure.Handshake(); err != nil {
		t.Fatal("first device could not re-establish TLS before revocation")
	}
	if _, err := io.WriteString(secure, "GET / HTTP/1.1\r\nHost: checkip.amazonaws.com\r\nConnection: keep-alive\r\n\r\n"); err != nil {
		t.Fatal(err)
	}
	response, err = http.ReadResponse(bufio.NewReader(secure), &http.Request{Method: http.MethodGet})
	if err != nil {
		t.Fatal(err)
	}
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 2048))
	response.Body.Close()
	revocation := agent.RevokePeerRequest{
		NodeID: config.nodeID, PeerID: first.identity.PeerID, RequestID: liveNonce(), Generation: first.identity.PeerGeneration + 1,
	}
	var receipt agent.RevocationReceipt
	postLiveControl(t, adminClient, config.controlURL, "/v1/member-peers/revoke", revocation, &receipt)
	if receipt.NodeID != config.nodeID || receipt.PeerID != first.identity.PeerID || receipt.RequestID != revocation.RequestID ||
		receipt.ConfirmationStatus != "confirmed_test" || receipt.UploadBytes == 0 || receipt.DownloadBytes == 0 {
		t.Fatalf("live revocation receipt did not include final node/peer/request/traffic: %+v", receipt)
	}
	_ = secure.Close()
	_ = firstBridge.Close()
	waitForLiveSessions(t, adminClient, config, 0, 15*time.Second)
	assertLiveCapacityAvailable(t, adminClient, config)
	blockedStatus := requestLiveDataPlane(t, config, first.certificate, roots)
	if blockedStatus != http.StatusForbidden {
		t.Fatalf("revoked device reconnect returned HTTP %d; want authorization denial %d", blockedStatus, http.StatusForbidden)
	}
	assertLiveSessionCount(t, adminClient, config, 0)
}

func liveRuntimeStatus(t *testing.T, client *http.Client, config liveAcceptanceConfig) liveRuntimeSnapshot {
	t.Helper()
	request, err := http.NewRequest(http.MethodGet, strings.TrimSuffix(config.controlURL, "/")+"/v1/test/status", nil)
	if err != nil {
		t.Fatal("build isolated node status request")
	}
	response, err := client.Do(request)
	if err != nil {
		t.Fatal("read isolated node session status")
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		t.Fatalf("isolated node status returned HTTP %d", response.StatusCode)
	}
	var envelope struct {
		Data liveRuntimeSnapshot `json:"data"`
	}
	if err := json.NewDecoder(response.Body).Decode(&envelope); err != nil {
		t.Fatal("decode isolated node session status")
	}
	return envelope.Data
}

func requestLiveDataPlane(t *testing.T, config liveAcceptanceConfig, certificate tls.Certificate, roots *x509.CertPool) int {
	t.Helper()
	remote, err := url.Parse(config.dataURL)
	if err != nil {
		t.Fatal("parse isolated data-plane URL")
	}
	transport := &http.Transport{
		TLSClientConfig:   &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: roots, ServerName: remote.Hostname(), Certificates: []tls.Certificate{certificate}},
		ForceAttemptHTTP2: true,
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			return (&net.Dialer{Timeout: 10 * time.Second}).DialContext(ctx, network, config.dataDial)
		},
	}
	defer transport.CloseIdleConnections()
	query := url.Values{"host": []string{"checkip.amazonaws.com"}, "port": []string{"443"}}
	request, err := http.NewRequest(http.MethodPost, strings.TrimSuffix(config.dataURL, "/")+"/v1/tunnel/connect?"+query.Encode(), strings.NewReader(""))
	if err != nil {
		t.Fatal("build isolated data-plane proof request")
	}
	response, err := (&http.Client{Transport: transport, Timeout: 15 * time.Second}).Do(request)
	if err != nil {
		t.Fatal("send isolated data-plane proof request")
	}
	defer response.Body.Close()
	return response.StatusCode
}

func assertLiveSessionCount(t *testing.T, client *http.Client, config liveAcceptanceConfig, expected int) {
	t.Helper()
	snapshot := liveRuntimeStatus(t, client, config)
	if snapshot.ActiveSessions != expected {
		t.Fatalf("isolated node has %d active sessions; want %d", snapshot.ActiveSessions, expected)
	}
}

func assertLiveCapacityAvailable(t *testing.T, client *http.Client, config liveAcceptanceConfig) {
	t.Helper()
	snapshot := liveRuntimeStatus(t, client, config)
	if snapshot.Capacity != config.expectedSlots {
		t.Fatalf("isolated node capacity is %d; test expects %d", snapshot.Capacity, config.expectedSlots)
	}
	if snapshot.ActiveSessions >= snapshot.Capacity {
		t.Fatalf("cannot attribute copied-identity rejection while node capacity is full: active=%d capacity=%d", snapshot.ActiveSessions, snapshot.Capacity)
	}
}

func waitForLiveSessions(t *testing.T, client *http.Client, config liveAcceptanceConfig, expected int, timeout time.Duration) {
	t.Helper()
	deadline := time.NewTimer(timeout)
	defer deadline.Stop()
	ticker := time.NewTicker(100 * time.Millisecond)
	defer ticker.Stop()
	for {
		if snapshot := liveRuntimeStatus(t, client, config); snapshot.ActiveSessions == expected {
			return
		}
		select {
		case <-deadline.C:
			snapshot := liveRuntimeStatus(t, client, config)
			t.Fatalf("timed out waiting for %d active sessions; got %d of capacity %d", expected, snapshot.ActiveSessions, snapshot.Capacity)
		case <-ticker.C:
		}
	}
}

func startLiveBridge(t *testing.T, config liveAcceptanceConfig, certificate tls.Certificate, roots *x509.CertPool) (*Bridge, net.Listener) {
	t.Helper()
	proxy, err := New(Config{
		ListenAddress: "127.0.0.1:0", RemoteURL: config.dataURL, RemoteDialAddress: config.dataDial,
		ServerRoots: roots, DeviceCertificate: certificate,
	})
	if err != nil {
		t.Fatal(err)
	}
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		_ = proxy.Close()
		t.Fatal(err)
	}
	go func() { _ = proxy.Serve(listener) }()
	t.Cleanup(func() { _ = proxy.Close() })
	return proxy, listener
}

func connectSOCKS(t *testing.T, address, host string, port uint16) (net.Conn, byte) {
	t.Helper()
	connection, err := net.DialTimeout("tcp", address, 8*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	_ = connection.SetDeadline(time.Now().Add(15 * time.Second))
	if _, err := connection.Write([]byte{5, 1, 0}); err != nil {
		connection.Close()
		t.Fatal(err)
	}
	var methodReply [2]byte
	if _, err := io.ReadFull(connection, methodReply[:]); err != nil || methodReply != [2]byte{5, 0} {
		connection.Close()
		t.Fatalf("live bridge SOCKS method negotiation failed: reply=%v err=%v", methodReply, err)
	}
	domain := []byte(host)
	request := append([]byte{5, 1, 0, 3, byte(len(domain))}, domain...)
	request = append(request, byte(port>>8), byte(port))
	if _, err := connection.Write(request); err != nil {
		connection.Close()
		t.Fatal(err)
	}
	var reply [10]byte
	if _, err := io.ReadFull(connection, reply[:]); err != nil {
		connection.Close()
		t.Fatalf("read live bridge SOCKS CONNECT reply: %v", err)
	}
	_ = connection.SetDeadline(time.Time{})
	if reply[1] != 0 {
		connection.Close()
		return nil, reply[1]
	}
	return connection, 0
}

func liveAdminClient(t *testing.T, config liveAcceptanceConfig) (*http.Client, *http.Transport) {
	t.Helper()
	certificateInfo, err := os.Stat(config.adminKey)
	if err != nil || certificateInfo.Mode().Perm()&0o077 != 0 {
		t.Fatal("isolated admin key must have owner-only permissions")
	}
	certificate, err := tls.LoadX509KeyPair(config.adminCert, config.adminKey)
	if err != nil {
		t.Fatal("load isolated admin client certificate")
	}
	roots := loadLiveRoots(t, config.caPath)
	remote, err := url.Parse(config.controlURL)
	if err != nil {
		t.Fatal(err)
	}
	transport := &http.Transport{
		TLSClientConfig:   &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: roots, ServerName: remote.Hostname(), Certificates: []tls.Certificate{certificate}},
		ForceAttemptHTTP2: true,
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			return (&net.Dialer{Timeout: 10 * time.Second}).DialContext(ctx, network, config.controlDial)
		},
	}
	return &http.Client{Transport: transport, Timeout: 15 * time.Second}, transport
}

func postLiveControl(t *testing.T, client *http.Client, origin, path string, payload, target any) {
	t.Helper()
	body, err := json.Marshal(payload)
	if err != nil {
		t.Fatal(err)
	}
	request, err := http.NewRequest(http.MethodPost, strings.TrimSuffix(origin, "/")+path, bytes.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	request.Header.Set("Content-Type", "application/json")
	response, err := client.Do(request)
	if err != nil {
		t.Fatal("isolated node control request failed")
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		t.Fatalf("isolated node control request returned HTTP %d", response.StatusCode)
	}
	var envelope struct {
		Data json.RawMessage `json:"data"`
	}
	if err := json.NewDecoder(io.LimitReader(response.Body, 64*1024)).Decode(&envelope); err != nil {
		t.Fatal("decode isolated node control response")
	}
	if err := json.Unmarshal(envelope.Data, target); err != nil {
		t.Fatal("decode isolated node control response data")
	}
}

func loadLiveRoots(t *testing.T, path string) *x509.CertPool {
	t.Helper()
	caPEM, err := os.ReadFile(path)
	if err != nil {
		t.Fatal("read isolated node CA")
	}
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM(caPEM) {
		t.Fatal("parse isolated node CA")
	}
	return roots
}

func waitForFile(path string, timeout time.Duration) bool {
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if _, err := os.Stat(path); err == nil {
			return true
		}
		time.Sleep(25 * time.Millisecond)
	}
	return false
}

func requiredEnvironment(t *testing.T, name string) string {
	t.Helper()
	value := os.Getenv(name)
	if value == "" {
		t.Fatalf("required environment variable %s is missing", name)
	}
	return value
}

func liveNonce() string {
	value := make([]byte, 24)
	if _, err := rand.Read(value); err != nil {
		panic(err)
	}
	return base64.RawURLEncoding.EncodeToString(value)
}

func sha256Sum(value []byte) [32]byte {
	return sha256.Sum256(value)
}

func writeTestIdentityFile(t *testing.T, path string, value []byte) {
	t.Helper()
	if err := os.WriteFile(path, value, 0o600); err != nil {
		t.Fatal(err)
	}
}
