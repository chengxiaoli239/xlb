package bridge

import (
	"context"
	"crypto"
	"crypto/sha256"
	"crypto/subtle"
	"crypto/tls"
	"crypto/x509"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	agent "xlb/node-agent"
)

type Config struct {
	ListenAddress     string
	RemoteURL         string
	RemoteDialAddress string
	ServerRoots       *x509.CertPool
	DeviceCertificate tls.Certificate
	LocalUsername     string
	LocalPassword     string
}

type Bridge struct {
	identity      agent.DeviceCertificateIdentity
	remoteURL     *url.URL
	client        *http.Client
	transport     *http.Transport
	ctx           context.Context
	cancel        context.CancelFunc
	connected     atomic.Bool
	mu            sync.Mutex
	credentialsMu sync.RWMutex
	listener      net.Listener
	localUser     []byte
	localPass     []byte
}

func New(config Config) (*Bridge, error) {
	listenHost, _, err := net.SplitHostPort(config.ListenAddress)
	if err != nil || !isLoopbackHost(listenHost) {
		return nil, errors.New("bridge listener must use a valid loopback address")
	}
	remote, err := url.Parse(config.RemoteURL)
	if err != nil || remote.Scheme != "https" || remote.Hostname() == "" || remote.User != nil || remote.RawQuery != "" || remote.Fragment != "" {
		return nil, errors.New("bridge remote URL must be an HTTPS origin without credentials or query")
	}
	if config.ServerRoots == nil {
		return nil, errors.New("a trusted node CA is required")
	}
	if config.RemoteDialAddress != "" {
		dialHost, _, splitErr := net.SplitHostPort(config.RemoteDialAddress)
		if splitErr != nil || !isLoopbackHost(dialHost) {
			return nil, errors.New("test bridge remote dial address must be loopback")
		}
	}
	if (config.LocalUsername == "") != (config.LocalPassword == "") ||
		len(config.LocalUsername) > 255 || len(config.LocalPassword) > 255 ||
		strings.ContainsRune(config.LocalUsername, '\x00') || strings.ContainsRune(config.LocalPassword, '\x00') {
		return nil, errors.New("local SOCKS credentials are invalid")
	}
	certificate := config.DeviceCertificate
	if len(certificate.Certificate) == 0 || certificate.PrivateKey == nil {
		return nil, errors.New("a proof-capable device certificate and signer are required")
	}
	leaf := certificate.Leaf
	if leaf == nil {
		leaf, err = x509.ParseCertificate(certificate.Certificate[0])
		if err != nil {
			return nil, errors.New("device certificate is invalid")
		}
		certificate.Leaf = leaf
	}
	identity, err := agent.DeviceIdentityFromCertificate(leaf)
	if err != nil {
		return nil, errors.New("device certificate has no valid XLB peer proof")
	}
	signer, ok := certificate.PrivateKey.(crypto.Signer)
	if !ok {
		return nil, errors.New("device certificate private key must be a crypto.Signer")
	}
	publicDER, err := x509.MarshalPKIXPublicKey(signer.Public())
	if err != nil || fingerprint(publicDER) != fingerprint(leaf.RawSubjectPublicKeyInfo) {
		return nil, errors.New("device certificate public key does not match its signer")
	}
	serverName := remote.Hostname()

	ctx, cancel := context.WithCancel(context.Background())
	transport := &http.Transport{
		TLSClientConfig: &tls.Config{
			MinVersion: tls.VersionTLS13, RootCAs: config.ServerRoots, ServerName: serverName,
			Certificates: []tls.Certificate{certificate}, NextProtos: []string{"h2", "http/1.1"},
		},
		ForceAttemptHTTP2:     true,
		MaxConnsPerHost:       1,
		MaxIdleConns:          1,
		MaxIdleConnsPerHost:   1,
		IdleConnTimeout:       45 * time.Second,
		ResponseHeaderTimeout: 10 * time.Second,
	}
	if config.RemoteDialAddress != "" {
		transport.DialContext = func(ctx context.Context, network, _ string) (net.Conn, error) {
			return (&net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second}).DialContext(ctx, network, config.RemoteDialAddress)
		}
	}
	bridge := &Bridge{
		identity: identity, remoteURL: remote, client: &http.Client{Transport: transport},
		transport: transport, ctx: ctx, cancel: cancel,
		localUser: []byte(config.LocalUsername), localPass: []byte(config.LocalPassword),
	}
	go bridge.heartbeatLoop()
	return bridge, nil
}

func (bridge *Bridge) Serve(listener net.Listener) error {
	if listener == nil {
		return errors.New("bridge listener is required")
	}
	address, ok := listener.Addr().(*net.TCPAddr)
	if !ok || address.IP == nil || !address.IP.IsLoopback() {
		return errors.New("bridge listener must bind to loopback")
	}
	bridge.mu.Lock()
	bridge.listener = listener
	bridge.mu.Unlock()
	for {
		connection, err := listener.Accept()
		if err != nil {
			select {
			case <-bridge.ctx.Done():
				return nil
			default:
			}
			return err
		}
		go bridge.serveSOCKSConnection(connection)
	}
}

func (bridge *Bridge) Close() error {
	bridge.cancel()
	bridge.mu.Lock()
	listener := bridge.listener
	bridge.mu.Unlock()
	if listener != nil {
		_ = listener.Close()
	}
	bridge.transport.CloseIdleConnections()
	bridge.credentialsMu.Lock()
	for index := range bridge.localUser {
		bridge.localUser[index] = 0
	}
	for index := range bridge.localPass {
		bridge.localPass[index] = 0
	}
	bridge.credentialsMu.Unlock()
	return nil
}

func (bridge *Bridge) heartbeatLoop() {
	ticker := time.NewTicker(20 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-bridge.ctx.Done():
			return
		case <-ticker.C:
			if !bridge.connected.Load() {
				continue
			}
			request, err := http.NewRequestWithContext(bridge.ctx, http.MethodPost,
				bridge.endpoint("/v1/tunnel/heartbeat", nil), nil)
			if err != nil {
				continue
			}
			response, err := bridge.client.Do(request)
			if err != nil {
				bridge.transport.CloseIdleConnections()
				continue
			}
			response.Body.Close()
			if response.StatusCode != http.StatusOK {
				bridge.transport.CloseIdleConnections()
			}
		}
	}
}

func (bridge *Bridge) serveSOCKSConnection(connection net.Conn) {
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(10 * time.Second))
	var greeting [2]byte
	if _, err := io.ReadFull(connection, greeting[:]); err != nil || greeting[0] != 5 || greeting[1] == 0 {
		return
	}
	methods := make([]byte, int(greeting[1]))
	if _, err := io.ReadFull(connection, methods); err != nil {
		return
	}
	noAuth := false
	usernamePassword := false
	for _, method := range methods {
		if method == 0 {
			noAuth = true
		}
		if method == 2 {
			usernamePassword = true
		}
	}
	authMethod := byte(0)
	if bridge.requiresSOCKSUserPassword() {
		if !usernamePassword {
			_, _ = connection.Write([]byte{5, 0xff})
			return
		}
		authMethod = 2
	} else if !noAuth {
		_, _ = connection.Write([]byte{5, 0xff})
		return
	}
	if _, err := connection.Write([]byte{5, authMethod}); err != nil {
		return
	}
	if authMethod == 2 && !bridge.authenticateSOCKSUserPassword(connection) {
		return
	}
	var requestHeader [4]byte
	if _, err := io.ReadFull(connection, requestHeader[:]); err != nil || requestHeader[0] != 5 {
		return
	}
	if requestHeader[1] != 1 || requestHeader[2] != 0 {
		_ = writeSOCKSReply(connection, 7)
		return
	}
	host, err := readSOCKSHost(connection, requestHeader[3])
	if err != nil {
		_ = writeSOCKSReply(connection, 8)
		return
	}
	var portBytes [2]byte
	if _, err := io.ReadFull(connection, portBytes[:]); err != nil {
		return
	}
	port := binary.BigEndian.Uint16(portBytes[:])
	if port != 80 && port != 443 {
		_ = writeSOCKSReply(connection, 2)
		return
	}
	_ = connection.SetDeadline(time.Time{})
	if err := bridge.openTunnel(connection, host, port); err != nil {
		_ = writeSOCKSReply(connection, 5)
	}
}

func (bridge *Bridge) authenticateSOCKSUserPassword(connection net.Conn) bool {
	var header [2]byte
	if _, err := io.ReadFull(connection, header[:]); err != nil || header[0] != 1 || header[1] == 0 {
		return false
	}
	username := make([]byte, int(header[1]))
	if _, err := io.ReadFull(connection, username); err != nil {
		return false
	}
	var passwordLength [1]byte
	if _, err := io.ReadFull(connection, passwordLength[:]); err != nil || passwordLength[0] == 0 {
		return false
	}
	password := make([]byte, int(passwordLength[0]))
	if _, err := io.ReadFull(connection, password); err != nil {
		return false
	}
	bridge.credentialsMu.RLock()
	valid := subtle.ConstantTimeCompare(username, bridge.localUser) &
		subtle.ConstantTimeCompare(password, bridge.localPass)
	bridge.credentialsMu.RUnlock()
	status := byte(1)
	if valid == 1 {
		status = 0
	}
	if _, err := connection.Write([]byte{1, status}); err != nil {
		return false
	}
	return valid == 1
}

func (bridge *Bridge) requiresSOCKSUserPassword() bool {
	bridge.credentialsMu.RLock()
	defer bridge.credentialsMu.RUnlock()
	return len(bridge.localUser) > 0
}

func (bridge *Bridge) openTunnel(local net.Conn, host string, port uint16) error {
	context, cancel := context.WithCancel(bridge.ctx)
	defer cancel()
	pipeReader, pipeWriter := io.Pipe()
	query := url.Values{"host": []string{host}, "port": []string{strconv.Itoa(int(port))}}
	request, err := http.NewRequestWithContext(context, http.MethodPost, bridge.endpoint("/v1/tunnel/connect", query), pipeReader)
	if err != nil {
		pipeReader.Close()
		pipeWriter.Close()
		return err
	}
	request.Header.Set("Content-Type", "application/octet-stream")
	request.ContentLength = -1
	uplink := make(chan error, 1)
	go func() {
		_, copyErr := io.Copy(pipeWriter, local)
		_ = pipeWriter.CloseWithError(copyErr)
		uplink <- copyErr
	}()
	response, err := bridge.client.Do(request)
	if err != nil {
		cancel()
		_ = pipeReader.CloseWithError(err)
		_ = pipeWriter.CloseWithError(err)
		return err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		cancel()
		_ = pipeReader.Close()
		_ = pipeWriter.Close()
		return fmt.Errorf("node data plane rejected the device connection: %d", response.StatusCode)
	}
	bridge.connected.Store(true)
	if err := writeSOCKSReply(local, 0); err != nil {
		cancel()
		_ = pipeReader.CloseWithError(err)
		return err
	}
	downlinkErr := make(chan error, 1)
	go func() {
		_, copyErr := io.Copy(local, response.Body)
		downlinkErr <- copyErr
	}()
	select {
	case <-context.Done():
	case <-downlinkErr:
		cancel()
	}
	_ = local.Close()
	cancel()
	_ = pipeReader.Close()
	_ = pipeWriter.Close()
	<-uplink
	return nil
}

func (bridge *Bridge) endpoint(path string, query url.Values) string {
	endpoint := *bridge.remoteURL
	endpoint.Path = strings.TrimSuffix(endpoint.Path, "/") + path
	if query != nil {
		endpoint.RawQuery = query.Encode()
	}
	return endpoint.String()
}

func readSOCKSHost(connection net.Conn, addressType byte) (string, error) {
	switch addressType {
	case 1:
		var value [4]byte
		if _, err := io.ReadFull(connection, value[:]); err != nil {
			return "", err
		}
		return net.IP(value[:]).String(), nil
	case 3:
		var size [1]byte
		if _, err := io.ReadFull(connection, size[:]); err != nil || size[0] == 0 {
			return "", errors.New("empty SOCKS domain")
		}
		value := make([]byte, int(size[0]))
		if _, err := io.ReadFull(connection, value); err != nil {
			return "", err
		}
		return string(value), nil
	case 4:
		var value [16]byte
		if _, err := io.ReadFull(connection, value[:]); err != nil {
			return "", err
		}
		return net.IP(value[:]).String(), nil
	default:
		return "", errors.New("unsupported SOCKS address type")
	}
}

func writeSOCKSReply(connection net.Conn, reply byte) error {
	_, err := connection.Write([]byte{5, reply, 0, 1, 0, 0, 0, 0, 0, 0})
	return err
}

func isLoopbackHost(host string) bool {
	if strings.EqualFold(host, "localhost") {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}

func fingerprint(value []byte) string {
	digest := sha256.Sum256(value)
	return fmt.Sprintf("%x", digest[:])
}
