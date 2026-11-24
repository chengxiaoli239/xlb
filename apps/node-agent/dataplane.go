package agent

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/netip"
	"strconv"
	"strings"
	"sync"
	"time"
)

var ErrTargetDenied = errors.New("node data plane target is not allowed")

const maxTunnelStreamsPerDevice = 64

type TargetDialer interface {
	DialTarget(context.Context, string, uint16) (net.Conn, error)
}

type PublicTargetDialer struct {
	AllowedPorts map[uint16]struct{}
}

func NewPublicTargetDialer() PublicTargetDialer {
	return PublicTargetDialer{AllowedPorts: map[uint16]struct{}{80: {}, 443: {}}}
}

func (dialer PublicTargetDialer) DialTarget(ctx context.Context, host string, port uint16) (net.Conn, error) {
	if _, allowed := dialer.AllowedPorts[port]; !allowed || !validTargetHost(host) {
		return nil, ErrTargetDenied
	}
	addresses, err := net.DefaultResolver.LookupIPAddr(ctx, host)
	if err != nil || len(addresses) == 0 {
		return nil, fmt.Errorf("resolve target: %w", ErrTargetDenied)
	}
	for _, address := range addresses {
		if !isPublicTargetIP(address.IP) {
			return nil, ErrTargetDenied
		}
	}
	return (&net.Dialer{Timeout: 8 * time.Second, KeepAlive: 30 * time.Second}).DialContext(
		ctx, "tcp", net.JoinHostPort(addresses[0].IP.String(), strconv.Itoa(int(port))),
	)
}

type trackedDataConnection struct {
	connection  net.Conn
	peerID      string
	sessionID   string
	identity    DeviceCertificateIdentity
	fingerprint string
	peerExpires time.Time
	active      int
	closed      bool
	done        chan struct{}
	streams     sync.WaitGroup
}

type dataConnectionContextKey struct{}

type DataPlane struct {
	store         *PeerStore
	dialer        TargetDialer
	quotaProvider QuotaLeaseProvider
	mu            sync.Mutex
	connections   map[net.Conn]*trackedDataConnection
	activeStreams map[string]map[*trackedDataConnection]struct{}
	pending       map[string][]*trackedDataConnection
}

func NewQuotaEnforcedDataPlane(store *PeerStore, dialer TargetDialer, provider QuotaLeaseProvider) (*DataPlane, error) {
	if provider == nil {
		return nil, ErrInvalid
	}
	plane, err := NewDataPlane(store, dialer)
	if err != nil {
		return nil, err
	}
	plane.quotaProvider = provider
	return plane, nil
}

func NewRecoveredQuotaAuthorityDataPlane(
	ctx context.Context,
	store *PeerStore,
	dialer TargetDialer,
	authority QuotaAuthority,
	grantBytes int64,
	outboxLimit int,
) (*DataPlane, error) {
	if store == nil || dialer == nil {
		return nil, ErrInvalid
	}
	provider, err := NewDurableQuotaLeaseProvider(authority, store, grantBytes)
	if err != nil {
		return nil, err
	}
	if outboxLimit < 1 || outboxLimit > 1000 {
		return nil, ErrInvalid
	}
	if err := ResumeQuotaOutboxes(ctx, store, authority, outboxLimit); err != nil {
		return nil, err
	}
	return NewQuotaEnforcedDataPlane(store, dialer, provider)
}

func NewDataPlane(store *PeerStore, dialer TargetDialer) (*DataPlane, error) {
	if store == nil || dialer == nil {
		return nil, ErrInvalid
	}
	return &DataPlane{
		store: store, dialer: dialer, connections: make(map[net.Conn]*trackedDataConnection),
		activeStreams: make(map[string]map[*trackedDataConnection]struct{}), pending: make(map[string][]*trackedDataConnection),
	}, nil
}

func (plane *DataPlane) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(writer http.ResponseWriter, request *http.Request) {
		writeJSON(writer, http.StatusOK, apiResponse{Data: map[string]string{"status": "isolated-test"}})
	})
	mux.HandleFunc("POST /v1/tunnel/heartbeat", plane.handleHeartbeat)
	mux.HandleFunc("POST /v1/tunnel/disconnect", plane.handleDisconnect)
	mux.HandleFunc("POST /v1/tunnel/connect", plane.handleConnect)
	return http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.TLS == nil || len(request.TLS.PeerCertificates) == 0 {
			writeFailure(writer, ErrUnauthorized)
			return
		}
		mux.ServeHTTP(writer, request)
	})
}

func (plane *DataPlane) ConnContext(ctx context.Context, connection net.Conn) context.Context {
	tracked := &trackedDataConnection{connection: connection, done: make(chan struct{})}
	plane.mu.Lock()
	plane.connections[connection] = tracked
	plane.mu.Unlock()
	return context.WithValue(ctx, dataConnectionContextKey{}, tracked)
}

func (plane *DataPlane) ConnState(connection net.Conn, state http.ConnState) {
	if state != http.StateClosed && state != http.StateHijacked {
		return
	}
	plane.mu.Lock()
	tracked := plane.connections[connection]
	if tracked != nil {
		tracked.closed = true
		delete(plane.connections, connection)
		close(tracked.done)
	}
	sessionID := ""
	if tracked != nil {
		sessionID = tracked.sessionID
	}
	plane.mu.Unlock()
	if sessionID != "" {
		_ = plane.store.Disconnect(sessionID)
	}
}

func (plane *DataPlane) RevokePeer(request RevokePeerRequest) (RevocationReceipt, error) {
	plane.mu.Lock()
	if err := plane.store.BeginRevocation(request); err != nil {
		plane.mu.Unlock()
		return RevocationReceipt{}, err
	}
	var active []*trackedDataConnection
	for _, connection := range plane.connections {
		if connection.peerID != request.PeerID {
			continue
		}
		active = appendUniqueConnection(active, connection)
	}
	for _, connection := range plane.pending[request.PeerID] {
		active = appendUniqueConnection(active, connection)
	}
	for connection := range plane.activeStreams[request.PeerID] {
		active = appendUniqueConnection(active, connection)
	}
	for _, connection := range active {
		connection.closed = true
		_ = connection.connection.Close()
	}
	plane.pending[request.PeerID] = active
	plane.mu.Unlock()

	waited := make(chan struct{})
	go func() {
		for _, connection := range active {
			connection.streams.Wait()
		}
		close(waited)
	}()
	select {
	case <-waited:
	case <-time.After(5 * time.Second):
		return RevocationReceipt{}, ErrRevocationPending
	}
	if err := plane.store.DisconnectPeerSessions(request.PeerID); err != nil {
		return RevocationReceipt{}, err
	}
	receipt, err := plane.store.CompleteRevocation(request)
	if err == nil {
		plane.mu.Lock()
		delete(plane.pending, request.PeerID)
		plane.mu.Unlock()
	}
	return receipt, err
}

func appendUniqueConnection(connections []*trackedDataConnection, candidate *trackedDataConnection) []*trackedDataConnection {
	for _, connection := range connections {
		if connection == candidate {
			return connections
		}
	}
	return append(connections, candidate)
}

func (plane *DataPlane) handleHeartbeat(writer http.ResponseWriter, request *http.Request) {
	tracked, sessionID, err := plane.authorizeConnection(request)
	if err != nil {
		writeFailure(writer, err)
		return
	}
	if _, err := plane.store.RenewSession(sessionID); err != nil {
		plane.closeConnection(tracked)
		writeFailure(writer, err)
		return
	}
	writeJSON(writer, http.StatusOK, apiResponse{Data: map[string]string{"status": "active"}})
}

func (plane *DataPlane) handleDisconnect(writer http.ResponseWriter, request *http.Request) {
	tracked, sessionID, err := plane.authorizeConnection(request)
	if err != nil {
		writeFailure(writer, err)
		return
	}
	if err := plane.store.Disconnect(sessionID); err != nil {
		writeFailure(writer, err)
		return
	}
	writeJSON(writer, http.StatusOK, apiResponse{Data: map[string]string{"status": "disconnected"}})
	if flusher, ok := writer.(http.Flusher); ok {
		flusher.Flush()
	}
	plane.closeConnection(tracked)
}

func (plane *DataPlane) handleConnect(writer http.ResponseWriter, request *http.Request) {
	host, port, err := tunnelTarget(request)
	if err != nil {
		writeFailure(writer, err)
		return
	}
	tracked, sessionID, err := plane.beginStream(request)
	if err != nil {
		writeFailure(writer, err)
		return
	}
	defer plane.endStream(tracked)
	var quotaLease *QuotaLease
	quotaFinalized := false
	quotaAcknowledgementID := randomID()
	if plane.quotaProvider != nil {
		identity, identityErr := plane.store.PeerQuotaIdentity(tracked.peerID)
		if identityErr != nil {
			writeFailure(writer, ErrUnauthorized)
			return
		}
		quotaLease, err = plane.quotaProvider.OpenLease(request.Context(), identity)
		if err != nil || quotaLease == nil {
			if errors.Is(err, ErrQuotaExhausted) {
				writeFailure(writer, ErrQuotaExhausted)
			} else {
				writeFailure(writer, ErrQuotaUnavailable)
			}
			return
		}
		defer func() {
			if quotaFinalized {
				return
			}
			settlementContext, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer cancel()
			_ = quotaLease.Finalize(settlementContext, quotaAcknowledgementID)
		}()
	}
	if _, err := plane.store.RenewSession(sessionID); err != nil {
		plane.closeConnection(tracked)
		writeFailure(writer, err)
		return
	}

	target, err := plane.dialer.DialTarget(request.Context(), host, port)
	if err != nil {
		if errors.Is(err, ErrTargetDenied) {
			writeFailure(writer, ErrTargetDenied)
			return
		}
		writeJSON(writer, http.StatusBadGateway, apiResponse{Error: &apiError{Code: "target_unavailable", Message: "Target connection failed."}})
		return
	}
	defer target.Close()
	writer.Header().Set("Content-Type", "application/octet-stream")
	writer.Header().Set("Cache-Control", "no-store")
	writer.WriteHeader(http.StatusOK)
	if flusher, ok := writer.(http.Flusher); ok {
		flusher.Flush()
	}

	uplink := make(chan copyResult, 1)
	go func() {
		var bytes int64
		var copyErr error
		if quotaLease != nil {
			bytes, copyErr = CopyWithQuota(target, request.Body, quotaLease, "upload")
		} else {
			bytes, copyErr = io.Copy(target, request.Body)
		}
		if halfCloser, ok := target.(interface{ CloseWrite() error }); ok {
			_ = halfCloser.CloseWrite()
		}
		if errors.Is(copyErr, ErrQuotaExhausted) {
			_ = target.Close()
		}
		uplink <- copyResult{bytes: bytes, err: copyErr}
	}()
	targetDone := make(chan struct{})
	go func() {
		select {
		case <-request.Context().Done():
			_ = target.Close()
		case <-targetDone:
		}
	}()
	var downlinkBytes int64
	var downlinkErr error
	if quotaLease != nil {
		downlinkBytes, downlinkErr = CopyWithQuota(flushingWriter{writer}, target, quotaLease, "download")
	} else {
		downlinkBytes, downlinkErr = io.Copy(flushingWriter{writer}, target)
	}
	_ = target.Close()
	close(targetDone)
	uplinkResult := <-uplink
	_ = plane.store.AddTraffic(tracked.peerID, uplinkResult.bytes, downlinkBytes)
	if quotaLease != nil {
		if err := quotaLease.Finalize(request.Context(), quotaAcknowledgementID); err != nil {
			return
		}
		quotaFinalized = true
	}
	if uplinkResult.err != nil && !isClosedNetworkError(uplinkResult.err) {
		return
	}
	if downlinkErr != nil && !isClosedNetworkError(downlinkErr) {
		return
	}
}

type copyResult struct {
	bytes int64
	err   error
}

type flushingWriter struct {
	http.ResponseWriter
}

func (writer flushingWriter) Write(value []byte) (int, error) {
	count, err := writer.ResponseWriter.Write(value)
	if flusher, ok := writer.ResponseWriter.(http.Flusher); ok {
		flusher.Flush()
	}
	return count, err
}

func (plane *DataPlane) beginStream(request *http.Request) (*trackedDataConnection, string, error) {
	tracked, err := plane.authorizeConnectionLocked(request)
	if err != nil {
		return nil, "", err
	}
	plane.mu.Lock()
	defer plane.mu.Unlock()
	if tracked.closed || tracked.sessionID == "" {
		return nil, "", ErrUnauthorized
	}
	if tracked.active >= maxTunnelStreamsPerDevice {
		return nil, "", ErrCapacity
	}
	tracked.active++
	tracked.streams.Add(1)
	if plane.activeStreams[tracked.peerID] == nil {
		plane.activeStreams[tracked.peerID] = make(map[*trackedDataConnection]struct{})
	}
	plane.activeStreams[tracked.peerID][tracked] = struct{}{}
	return tracked, tracked.sessionID, nil
}

func (plane *DataPlane) authorizeConnection(request *http.Request) (*trackedDataConnection, string, error) {
	tracked, err := plane.authorizeConnectionLocked(request)
	if err != nil {
		return nil, "", err
	}
	plane.mu.Lock()
	defer plane.mu.Unlock()
	if tracked.closed || tracked.sessionID == "" {
		return nil, "", ErrUnauthorized
	}
	return tracked, tracked.sessionID, nil
}

func (plane *DataPlane) authorizeConnectionLocked(request *http.Request) (*trackedDataConnection, error) {
	tracked, ok := request.Context().Value(dataConnectionContextKey{}).(*trackedDataConnection)
	if !ok || request.TLS == nil || len(request.TLS.PeerCertificates) == 0 {
		return nil, ErrUnauthorized
	}
	certificate := request.TLS.PeerCertificates[0]
	identity, err := DeviceIdentityFromCertificate(certificate)
	if err != nil {
		return nil, err
	}
	keyFingerprint := fingerprint(certificate.RawSubjectPublicKeyInfo)
	plane.mu.Lock()
	defer plane.mu.Unlock()
	if tracked.closed {
		return nil, ErrUnauthorized
	}
	if tracked.sessionID != "" {
		if tracked.peerID != identity.PeerID || tracked.fingerprint != keyFingerprint || tracked.identity != identity {
			return nil, ErrUnauthorized
		}
		return tracked, nil
	}
	session, err := plane.store.OpenDeviceSession(identity, certificate.RawSubjectPublicKeyInfo)
	if err != nil {
		return nil, err
	}
	tracked.peerID = session.PeerID
	tracked.sessionID = session.SessionID
	tracked.identity = identity
	tracked.fingerprint = keyFingerprint
	tracked.peerExpires = session.PeerExpiresAt
	go plane.expireConnection(tracked)
	return tracked, nil
}

func (plane *DataPlane) endStream(tracked *trackedDataConnection) {
	plane.mu.Lock()
	if tracked.active > 0 {
		tracked.active--
	}
	if tracked.active == 0 {
		delete(plane.activeStreams[tracked.peerID], tracked)
		if len(plane.activeStreams[tracked.peerID]) == 0 {
			delete(plane.activeStreams, tracked.peerID)
		}
	}
	plane.mu.Unlock()
	tracked.streams.Done()
}

func (plane *DataPlane) expireConnection(tracked *trackedDataConnection) {
	duration := time.Until(tracked.peerExpires)
	if duration <= 0 {
		plane.closeConnection(tracked)
		return
	}
	timer := time.NewTimer(duration)
	defer timer.Stop()
	select {
	case <-tracked.done:
	case <-timer.C:
		plane.closeConnection(tracked)
	}
}

func (plane *DataPlane) closeConnection(tracked *trackedDataConnection) {
	plane.mu.Lock()
	if !tracked.closed {
		tracked.closed = true
		_ = tracked.connection.Close()
	}
	plane.mu.Unlock()
}

func tunnelTarget(request *http.Request) (string, uint16, error) {
	if request.URL.Query().Get("host") == "" {
		return "", 0, ErrInvalid
	}
	host := strings.TrimSuffix(strings.TrimSpace(request.URL.Query().Get("host")), ".")
	portValue, err := strconv.ParseUint(request.URL.Query().Get("port"), 10, 16)
	if err != nil || portValue == 0 || !validTargetHost(host) {
		return "", 0, ErrInvalid
	}
	port := uint16(portValue)
	if port != 80 && port != 443 {
		return "", 0, ErrTargetDenied
	}
	return host, port, nil
}

func validTargetHost(host string) bool {
	if len(host) == 0 || len(host) > 253 || strings.ContainsAny(host, "/\\\x00:@?#[]") {
		return false
	}
	if ip := net.ParseIP(host); ip != nil {
		return true
	}
	for _, label := range strings.Split(host, ".") {
		if len(label) == 0 || len(label) > 63 || label[0] == '-' || label[len(label)-1] == '-' {
			return false
		}
		for _, character := range label {
			if !(character >= 'a' && character <= 'z') && !(character >= 'A' && character <= 'Z') &&
				!(character >= '0' && character <= '9') && character != '-' {
				return false
			}
		}
	}
	return true
}

func isPublicTargetIP(address net.IP) bool {
	parsed, ok := netip.AddrFromSlice(address)
	if !ok {
		return false
	}
	parsed = parsed.Unmap()
	if !parsed.IsValid() || !parsed.IsGlobalUnicast() || parsed.IsPrivate() || parsed.IsLoopback() ||
		parsed.IsLinkLocalUnicast() || parsed.IsMulticast() || parsed.IsUnspecified() {
		return false
	}
	for _, value := range []string{
		"100.64.0.0/10", "192.0.0.0/24", "192.0.2.0/24", "192.88.99.0/24", "198.18.0.0/15",
		"198.51.100.0/24", "203.0.113.0/24", "240.0.0.0/4", "2001:db8::/32", "2001::/23", "64:ff9b:1::/48",
	} {
		prefix := netip.MustParsePrefix(value)
		if prefix.Contains(parsed) {
			return false
		}
	}
	return true
}

func isClosedNetworkError(err error) bool {
	return errors.Is(err, net.ErrClosed) || errors.Is(err, context.Canceled) ||
		strings.Contains(strings.ToLower(err.Error()), "closed network connection")
}

func NewDataPlaneServer(address, certificatePath, privateKeyPath string, plane *DataPlane) (*http.Server, error) {
	if plane == nil || !isLoopbackAddress(address) {
		return nil, ErrInvalid
	}
	certificate, err := tls.LoadX509KeyPair(certificatePath, privateKeyPath)
	if err != nil {
		return nil, fmt.Errorf("load node data plane server certificate: %w", err)
	}
	return &http.Server{
		Addr:              address,
		Handler:           plane.Handler(),
		ReadHeaderTimeout: 5 * time.Second,
		IdleTimeout:       35 * time.Second,
		MaxHeaderBytes:    16 * 1024,
		ConnContext:       plane.ConnContext,
		ConnState:         plane.ConnState,
		TLSConfig: &tls.Config{
			MinVersion:   tls.VersionTLS13,
			Certificates: []tls.Certificate{certificate},
			ClientAuth:   tls.RequireAnyClientCert,
			NextProtos:   []string{"h2", "http/1.1"},
		},
	}, nil
}

func isLoopbackAddress(address string) bool {
	host, _, err := net.SplitHostPort(address)
	if err != nil {
		return false
	}
	if strings.EqualFold(host, "localhost") {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}
