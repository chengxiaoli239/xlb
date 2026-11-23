package proofbridge

import (
	"crypto/sha256"
	"crypto/subtle"
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"net"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	nodebridge "xlb/node-agent/bridge"
)

const ListenAddress = "127.0.0.1:17890"

var ErrDeviceCertificateUnavailable = errors.New("device proof certificate is unavailable")
var ErrCredentialsUnavailable = errors.New("proof bridge credentials are no longer active")

type Manager struct {
	mu     sync.Mutex
	active *activeBridge
}

type activeBridge struct {
	bridge         *nodebridge.Bridge
	listener       net.Listener
	done           chan struct{}
	usernameDigest [sha256.Size]byte
	passwordDigest [sha256.Size]byte
}

func NewManager() *Manager {
	return &Manager{}
}

func (manager *Manager) Start(listenAddress, remoteURL, caBundle, localUsername, localPassword string,
	certificate tls.Certificate) error {
	manager.mu.Lock()
	defer manager.mu.Unlock()

	if manager.isRunningLocked() {
		return errors.New("proof bridge is already running")
	}
	if manager.active != nil {
		manager.stopLocked()
	}
	if localUsername == "" || localPassword == "" {
		return errors.New("proof bridge requires local SOCKS credentials")
	}
	if len(remoteURL) == 0 || len(remoteURL) > 2048 || len(caBundle) == 0 || len(caBundle) > 64*1024 {
		return errors.New("proof bridge configuration is invalid")
	}
	parsedURL, err := url.Parse(remoteURL)
	if err != nil || parsedURL.Scheme != "https" || parsedURL.Hostname() == "" || parsedURL.User != nil ||
		(parsedURL.Path != "" && parsedURL.Path != "/") || parsedURL.RawQuery != "" || parsedURL.Fragment != "" {
		return errors.New("proof bridge endpoint must be a trusted HTTPS origin")
	}
	if port := parsedURL.Port(); port != "" {
		portNumber, err := strconv.Atoi(port)
		if err != nil || portNumber < 1 || portNumber > 65535 {
			return errors.New("proof bridge endpoint must be a trusted HTTPS origin")
		}
	}
	if err := validateLoopbackListenAddress(listenAddress); err != nil {
		return err
	}
	serverRoots, err := parseNodeCARoots(caBundle)
	if err != nil {
		return err
	}
	secureBridge, err := nodebridge.New(nodebridge.Config{
		ListenAddress:     listenAddress,
		RemoteURL:         remoteURL,
		ServerRoots:       serverRoots,
		DeviceCertificate: certificate,
		LocalUsername:     localUsername,
		LocalPassword:     localPassword,
	})
	if err != nil {
		return errors.New("proof bridge could not validate node identity")
	}
	listener, err := net.Listen("tcp", listenAddress)
	if err != nil {
		_ = secureBridge.Close()
		return errors.New("proof bridge loopback listener could not start")
	}
	state := &activeBridge{
		bridge: secureBridge, listener: listener, done: make(chan struct{}),
		usernameDigest: sha256.Sum256([]byte(localUsername)),
		passwordDigest: sha256.Sum256([]byte(localPassword)),
	}
	manager.active = state
	go func() {
		_ = secureBridge.Serve(listener)
		close(state.done)
	}()
	return nil
}

func (manager *Manager) IsRunning() bool {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	return manager.isRunningLocked()
}

func (manager *Manager) Address() string {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	if !manager.isRunningLocked() || manager.active.listener == nil {
		return ""
	}
	return manager.active.listener.Addr().String()
}

func (manager *Manager) WithActiveCredentials(username, password string, operation func() error) error {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	if operation == nil || !manager.isRunningLocked() {
		return ErrCredentialsUnavailable
	}
	usernameDigest := sha256.Sum256([]byte(username))
	passwordDigest := sha256.Sum256([]byte(password))
	if subtle.ConstantTimeCompare(usernameDigest[:], manager.active.usernameDigest[:]) != 1 ||
		subtle.ConstantTimeCompare(passwordDigest[:], manager.active.passwordDigest[:]) != 1 {
		return ErrCredentialsUnavailable
	}
	return operation()
}

func (manager *Manager) Stop() {
	manager.mu.Lock()
	defer manager.mu.Unlock()
	manager.stopLocked()
}

func (manager *Manager) isRunningLocked() bool {
	if manager.active == nil {
		return false
	}
	select {
	case <-manager.active.done:
		manager.stopLocked()
		return false
	default:
		return true
	}
}

func (manager *Manager) stopLocked() {
	if manager.active == nil {
		return
	}
	state := manager.active
	manager.active = nil
	_ = state.bridge.Close()
	_ = state.listener.Close()
	select {
	case <-state.done:
	case <-time.After(2 * time.Second):
	}
	for index := range state.usernameDigest {
		state.usernameDigest[index] = 0
	}
	for index := range state.passwordDigest {
		state.passwordDigest[index] = 0
	}
}

func parseNodeCARoots(caBundle string) (*x509.CertPool, error) {
	roots := x509.NewCertPool()
	remaining := []byte(caBundle)
	certificateCount := 0
	for len(strings.TrimSpace(string(remaining))) > 0 {
		block, rest := pem.Decode(remaining)
		if block == nil || block.Type != "CERTIFICATE" || len(block.Headers) != 0 {
			return nil, errors.New("node CA bundle is invalid")
		}
		certificate, err := x509.ParseCertificate(block.Bytes)
		if err != nil || !certificate.IsCA || certificate.KeyUsage&x509.KeyUsageCertSign == 0 {
			return nil, errors.New("node CA bundle must contain CA certificates only")
		}
		roots.AddCert(certificate)
		certificateCount++
		if certificateCount > 8 {
			return nil, errors.New("node CA bundle contains too many certificates")
		}
		remaining = rest
	}
	if certificateCount == 0 {
		return nil, errors.New("node CA bundle is empty")
	}
	return roots, nil
}

func validateLoopbackListenAddress(address string) error {
	host, _, err := net.SplitHostPort(address)
	if err != nil || host != "127.0.0.1" {
		return errors.New("proof bridge listener must bind to IPv4 loopback")
	}
	return nil
}
