package main

import (
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
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	agent "xlb/node-agent"
	"xlb/node-agent/bridge"
)

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func run(arguments []string) error {
	if os.Getenv("XLB_BRIDGE_MODE") != "isolated-test" {
		return errors.New("XLB_BRIDGE_MODE must be isolated-test; production bridge mode is not implemented")
	}
	if len(arguments) == 0 {
		return errors.New("expected identity or serve command")
	}
	switch arguments[0] {
	case "identity":
		return createIdentity(arguments[1:])
	case "enroll":
		return enrollTestPeer(arguments[1:])
	case "serve":
		return serveBridge(arguments[1:])
	default:
		return errors.New("expected identity, enroll or serve command")
	}
}

func enrollTestPeer(arguments []string) error {
	flags := flag.NewFlagSet("enroll", flag.ContinueOnError)
	controlURL := flags.String("control-url", "", "isolated node control API HTTPS URL")
	controlDial := flags.String("control-dial", "", "loopback target used for SSM port forwarding")
	controlCA := flags.String("control-ca", "", "isolated node CA certificate")
	adminCertificatePath := flags.String("admin-cert", "", "isolated control client certificate")
	adminPrivateKeyPath := flags.String("admin-key", "", "isolated control client private key")
	nodeID := flags.String("node-id", "", "isolated node ID")
	entitlementID := flags.String("entitlement-id", "isolated-test-entitlement", "test-only entitlement ID")
	peerID := flags.String("peer-id", "", "unique isolated peer ID")
	deviceID := flags.String("device-id", "", "unique isolated device ID")
	deviceName := flags.String("device-name", "XLB isolated acceptance", "test-only display name")
	subscriptionID := flags.String("subscription-id", "", "optional test subscription to bind after peer creation")
	expiresMinutes := flags.Int("expires-minutes", 45, "test peer expiry in minutes")
	outputDirectory := flags.String("out", "", "new private identity directory outside the repository")
	if err := flags.Parse(arguments); err != nil {
		return err
	}
	if *controlURL == "" || *controlDial == "" || *controlCA == "" || *adminCertificatePath == "" ||
		*adminPrivateKeyPath == "" || *nodeID == "" || *peerID == "" || *deviceID == "" || *outputDirectory == "" {
		return errors.New("control URL/dial, control CA, admin cert/key, node/peer/device IDs and out are required")
	}
	if *expiresMinutes < 5 || *expiresMinutes > 720 {
		return errors.New("expires-minutes must be between 5 and 720")
	}
	absoluteOutput, err := filepath.Abs(*outputDirectory)
	if err != nil {
		return err
	}
	if _, err := os.Stat(absoluteOutput); err == nil {
		return errors.New("identity output directory already exists; refusing to overwrite")
	} else if !errors.Is(err, os.ErrNotExist) {
		return err
	}
	if isRepositoryPath(absoluteOutput) {
		return errors.New("test device keys must be created outside the repository")
	}
	if err := os.MkdirAll(absoluteOutput, 0o700); err != nil {
		return err
	}
	if err := os.Chmod(absoluteOutput, 0o700); err != nil {
		return err
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return err
	}
	publicDER, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		return err
	}
	credential, err := randomSecret()
	if err != nil {
		return err
	}
	expiresAt := time.Now().UTC().Add(time.Duration(*expiresMinutes) * time.Minute)
	request := agent.EnsurePeerRequest{
		NodeID: *nodeID, PeerID: *peerID, EntitlementID: *entitlementID,
		RequestID: randomRequestID(), Generation: 1,
		DeviceID: *deviceID, DeviceName: *deviceName,
		DevicePublicKey:         base64.RawURLEncoding.EncodeToString(publicDER),
		DeviceKeyFingerprint:    fmt.Sprintf("%x", sha256.Sum256(publicDER)),
		DeviceBindingGeneration: 1, PeerGeneration: 1, DeviceGeneration: 1,
		ExpiresAt: expiresAt, Credential: credential,
	}
	client, err := newAdminClient(*controlURL, *controlDial, *controlCA, *adminCertificatePath, *adminPrivateKeyPath)
	if err != nil {
		return err
	}
	defer client.CloseIdleConnections()
	var ensureReceipt agent.EnsurePeerReceipt
	if err := postControlJSON(client, *controlURL, "/v1/member-peers/ensure", request, &ensureReceipt); err != nil {
		return err
	}
	if ensureReceipt.NodeID != request.NodeID || ensureReceipt.PeerID != request.PeerID ||
		ensureReceipt.RequestID != request.RequestID || ensureReceipt.ConfirmationStatus != "confirmed_test" {
		return errors.New("node did not return a matching isolated test peer receipt")
	}
	identity := agent.DeviceCertificateIdentity{NodeID: *nodeID, PeerID: *peerID, DeviceID: *deviceID, PeerGeneration: 1, DeviceGeneration: 1}
	if *subscriptionID != "" {
		token, err := randomSecret()
		if err != nil {
			return err
		}
		binding := agent.BindSubscriptionRequest{
			NodeID: *nodeID, PeerID: *peerID, RequestID: randomRequestID(), SubscriptionID: *subscriptionID,
			SubscriptionToken: token, SubscriptionGeneration: 1, PeerGeneration: 1, DeviceGeneration: 1,
		}
		var receipt agent.SubscriptionBindingReceipt
		if err := postControlJSON(client, *controlURL, "/v1/member-peers/bind-subscription", binding, &receipt); err != nil {
			return err
		}
		if receipt.NodeID != binding.NodeID || receipt.PeerID != binding.PeerID || receipt.RequestID != binding.RequestID ||
			receipt.SubscriptionID != binding.SubscriptionID || receipt.ConfirmationStatus != "confirmed_test" {
			return errors.New("node did not return a matching isolated test subscription receipt")
		}
		identity.SubscriptionID = *subscriptionID
		identity.SubscriptionGeneration = 1
	}
	deviceCertificate, err := agent.NewDeviceCertificate(identity, key)
	if err != nil {
		return err
	}
	privateDER, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		return err
	}
	if err := writePrivateFile(filepath.Join(absoluteOutput, "device.crt"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: deviceCertificate.Certificate[0]})); err != nil {
		return err
	}
	if err := writePrivateFile(filepath.Join(absoluteOutput, "device.key"), pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateDER})); err != nil {
		return err
	}
	fmt.Printf("test_peer_enrolled=true\nconfirmation=%s\nidentity_dir=%s\ndevice_public_key=%s\n",
		ensureReceipt.ConfirmationStatus, absoluteOutput, request.DevicePublicKey)
	return nil
}

func newAdminClient(endpoint, dialAddress, caPath, certificatePath, privateKeyPath string) (*http.Client, error) {
	remote, err := url.Parse(endpoint)
	if err != nil || remote.Scheme != "https" || remote.Hostname() == "" || remote.User != nil {
		return nil, errors.New("control URL must be an HTTPS URL with a hostname")
	}
	dialHost, _, err := net.SplitHostPort(dialAddress)
	if err != nil || !isLoopbackAddress(dialHost) {
		return nil, errors.New("control dial address must be a loopback host:port used by SSM forwarding")
	}
	if err := requirePrivateKeyFile(privateKeyPath); err != nil {
		return nil, err
	}
	certificate, err := tls.LoadX509KeyPair(certificatePath, privateKeyPath)
	if err != nil {
		return nil, errors.New("load isolated control client identity failed")
	}
	caPEM, err := os.ReadFile(caPath)
	if err != nil {
		return nil, err
	}
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM(caPEM) {
		return nil, errors.New("control CA file contains no certificates")
	}
	transport := &http.Transport{
		TLSClientConfig:   &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: roots, ServerName: remote.Hostname(), Certificates: []tls.Certificate{certificate}},
		ForceAttemptHTTP2: true,
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			return (&net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second}).DialContext(ctx, network, dialAddress)
		},
	}
	return &http.Client{Transport: transport, Timeout: 15 * time.Second}, nil
}

func postControlJSON(client *http.Client, origin, path string, payload, target any) error {
	body, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	request, err := http.NewRequest(http.MethodPost, strings.TrimSuffix(origin, "/")+path, bytes.NewReader(body))
	if err != nil {
		return err
	}
	request.Header.Set("Content-Type", "application/json")
	response, err := client.Do(request)
	if err != nil {
		return errors.New("isolated node control request failed")
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return fmt.Errorf("isolated node control request returned HTTP %d", response.StatusCode)
	}
	var envelope struct {
		Data json.RawMessage `json:"data"`
	}
	if err := json.NewDecoder(io.LimitReader(response.Body, 64*1024)).Decode(&envelope); err != nil {
		return errors.New("isolated node control response was invalid")
	}
	if err := json.Unmarshal(envelope.Data, target); err != nil {
		return errors.New("isolated node control response data was invalid")
	}
	return nil
}

func randomSecret() (string, error) {
	value := make([]byte, 32)
	if _, err := rand.Read(value); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(value), nil
}

func randomRequestID() string {
	value := make([]byte, 24)
	if _, err := rand.Read(value); err != nil {
		panic(err)
	}
	return base64.RawURLEncoding.EncodeToString(value)
}

func createIdentity(arguments []string) error {
	flags := flag.NewFlagSet("identity", flag.ContinueOnError)
	nodeID := flags.String("node-id", "", "isolated test node ID")
	peerID := flags.String("peer-id", "", "isolated test peer ID")
	deviceID := flags.String("device-id", "", "isolated test device ID")
	peerGeneration := flags.Int("peer-generation", 1, "peer generation")
	deviceGeneration := flags.Int("device-generation", 1, "device binding generation")
	subscriptionID := flags.String("subscription-id", "", "optional isolated test subscription ID")
	subscriptionGeneration := flags.Int("subscription-generation", 0, "optional subscription generation")
	outputDirectory := flags.String("out", "", "new private directory outside the repository")
	if err := flags.Parse(arguments); err != nil {
		return err
	}
	if *nodeID == "" || *peerID == "" || *deviceID == "" || *outputDirectory == "" {
		return errors.New("node-id, peer-id, device-id and out are required")
	}
	absoluteOutput, err := filepath.Abs(*outputDirectory)
	if err != nil {
		return err
	}
	if _, err := os.Stat(absoluteOutput); err == nil {
		return errors.New("identity output directory already exists; refusing to overwrite")
	} else if !errors.Is(err, os.ErrNotExist) {
		return err
	}
	if isRepositoryPath(absoluteOutput) {
		return errors.New("test device keys must be created outside the repository")
	}
	if err := os.MkdirAll(absoluteOutput, 0o700); err != nil {
		return err
	}
	if err := os.Chmod(absoluteOutput, 0o700); err != nil {
		return err
	}
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return err
	}
	identity := agent.DeviceCertificateIdentity{
		NodeID: *nodeID, PeerID: *peerID, DeviceID: *deviceID, SubscriptionID: *subscriptionID,
		SubscriptionGeneration: *subscriptionGeneration, PeerGeneration: *peerGeneration, DeviceGeneration: *deviceGeneration,
	}
	certificate, err := agent.NewDeviceCertificate(identity, key)
	if err != nil {
		return err
	}
	certificatePEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: certificate.Certificate[0]})
	privateDER, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		return err
	}
	privatePEM := pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateDER})
	if err := writePrivateFile(filepath.Join(absoluteOutput, "device.crt"), certificatePEM); err != nil {
		return err
	}
	if err := writePrivateFile(filepath.Join(absoluteOutput, "device.key"), privatePEM); err != nil {
		return err
	}
	publicDER, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		return err
	}
	fmt.Printf("test_identity_created=true\npublic_key=%s\n", base64.RawURLEncoding.EncodeToString(publicDER))
	return nil
}

func serveBridge(arguments []string) error {
	flags := flag.NewFlagSet("serve", flag.ContinueOnError)
	listenAddress := flags.String("listen", "127.0.0.1:17890", "local SOCKS5 loopback address")
	remoteURL := flags.String("node-url", "", "node data-plane HTTPS URL, including certificate hostname")
	remoteDialAddress := flags.String("node-dial", "", "loopback target used for SSM port forwarding")
	caPath := flags.String("node-ca", "", "isolated node CA certificate")
	deviceCertificatePath := flags.String("device-cert", "", "isolated test device certificate")
	devicePrivateKeyPath := flags.String("device-key", "", "isolated test device private key")
	if err := flags.Parse(arguments); err != nil {
		return err
	}
	if *remoteURL == "" || *remoteDialAddress == "" || *caPath == "" || *deviceCertificatePath == "" || *devicePrivateKeyPath == "" {
		return errors.New("node-url, node-dial, node-ca, device-cert and device-key are required")
	}
	if err := requirePrivateKeyFile(*devicePrivateKeyPath); err != nil {
		return err
	}
	certificate, err := tls.LoadX509KeyPair(*deviceCertificatePath, *devicePrivateKeyPath)
	if err != nil {
		return errors.New("load isolated test device identity failed")
	}
	caPEM, err := os.ReadFile(*caPath)
	if err != nil {
		return err
	}
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM(caPEM) {
		return errors.New("node CA file contains no certificates")
	}
	proxy, err := bridge.New(bridge.Config{
		ListenAddress: *listenAddress, RemoteURL: *remoteURL, RemoteDialAddress: *remoteDialAddress,
		ServerRoots: roots, DeviceCertificate: certificate,
	})
	if err != nil {
		return err
	}
	listener, err := net.Listen("tcp", *listenAddress)
	if err != nil {
		return err
	}
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		<-stop
		_ = proxy.Close()
	}()
	fmt.Printf("isolated test SOCKS5 listening on %s\n", listener.Addr().String())
	return proxy.Serve(listener)
}

func writePrivateFile(path string, value []byte) error {
	file, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	if _, err := file.Write(value); err != nil {
		file.Close()
		return err
	}
	if err := file.Sync(); err != nil {
		file.Close()
		return err
	}
	return file.Close()
}

func requirePrivateKeyFile(path string) error {
	info, err := os.Stat(path)
	if err != nil {
		return err
	}
	if info.IsDir() || info.Mode().Perm()&0o077 != 0 {
		return fmt.Errorf("secret file permissions must be owner-only: %s", filepath.Base(path))
	}
	return nil
}

func isRepositoryPath(path string) bool {
	workingDirectory, err := os.Getwd()
	if err != nil {
		return false
	}
	for directory := workingDirectory; ; directory = filepath.Dir(directory) {
		if _, err := os.Stat(filepath.Join(directory, ".git")); err == nil {
			relative, err := filepath.Rel(directory, path)
			return err == nil && relative != ".." && !strings.HasPrefix(relative, ".."+string(filepath.Separator))
		}
		parent := filepath.Dir(directory)
		if parent == directory {
			return false
		}
	}
}

func isLoopbackAddress(host string) bool {
	if strings.EqualFold(host, "localhost") {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}
