package agent

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"errors"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestHTTPQuotaAuthorityUsesMutualTLSAndCanonicalReservationAndSettlement(t *testing.T) {
	request := quotaHTTPTestRequest()
	settlement := QuotaSettlement{
		NodeID: request.NodeID, PeerID: request.PeerID, Generation: request.Generation,
		RequestID: request.RequestID, AcknowledgementID: "ack-http-1",
		UploadBytes: 12, DownloadBytes: 20, Final: true,
	}
	server, clientCertificate := newQuotaHTTPTestServer(t, func(writer http.ResponseWriter, incoming *http.Request) {
		if incoming.TLS == nil || len(incoming.TLS.VerifiedChains) == 0 {
			t.Error("request did not use a verified client certificate")
		}
		switch incoming.URL.Path {
		case "/v1/node-quota/reservations":
			if incoming.Method != http.MethodPost {
				t.Errorf("reservation method = %s; want POST", incoming.Method)
			}
			var got QuotaReservationRequest
			if err := json.NewDecoder(incoming.Body).Decode(&got); err != nil {
				t.Errorf("decode reservation request: %v", err)
			}
			if got != request {
				t.Errorf("reservation request = %+v; want %+v", got, request)
			}
			writeQuotaHTTPData(t, writer, QuotaReservation{
				QuotaReservationRequest: request, ID: "reservation-http-1", Status: "reserved",
				LeaseExpiresAt: time.Now().Add(time.Minute),
			})
		case "/v1/node-quota/reservations/" + request.RequestID + "/settlements":
			var got QuotaSettlement
			if err := json.NewDecoder(incoming.Body).Decode(&got); err != nil {
				t.Errorf("decode settlement request: %v", err)
			}
			if got != settlement {
				t.Errorf("settlement request = %+v; want %+v", got, settlement)
			}
			writeQuotaHTTPData(t, writer, struct {
				QuotaSettlement
				Settled bool `json:"settled"`
			}{QuotaSettlement: settlement, Settled: true})
		default:
			t.Errorf("unexpected quota authority path: %s", incoming.URL.Path)
			http.NotFound(writer, incoming)
		}
	})
	authority := newQuotaHTTPTestAuthority(t, server, clientCertificate, time.Second)

	reservation, err := authority.Reserve(context.Background(), request)
	if err != nil {
		t.Fatalf("Reserve error = %v", err)
	}
	if reservation.QuotaReservationRequest != request || !reservation.LeaseExpiresAt.After(time.Now()) {
		t.Fatalf("unexpected reservation response: %+v", reservation)
	}
	if err := authority.Settle(context.Background(), settlement); err != nil {
		t.Fatalf("Settle error = %v", err)
	}
}

func TestLaravelQuotaFixturesMatchStrictGoWireContract(t *testing.T) {
	reservationBytes, err := os.ReadFile("testdata/laravel-quota-reservation.json")
	if err != nil {
		t.Fatal(err)
	}
	var reservationEnvelope quotaResponseEnvelope
	if err := decodeQuotaJSON(reservationBytes, &reservationEnvelope); err != nil {
		t.Fatalf("decode Laravel reservation envelope: %v", err)
	}
	var reservation QuotaReservation
	if err := decodeQuotaJSON(reservationEnvelope.Data, &reservation); err != nil {
		t.Fatalf("decode Laravel reservation: %v", err)
	}
	wantReservation := QuotaReservationRequest{
		EntitlementID: "entitlement-member-annual", NodeID: "node-test-1", PeerID: "peer-quota-1",
		Generation: 1, RequestID: "quota-ingress-1", GrantBytes: 512,
	}
	if reservation.QuotaReservationRequest != wantReservation || reservation.ID == "" || reservation.Status != "reserved" ||
		!reservation.LeaseExpiresAt.After(time.Now()) {
		t.Fatalf("Laravel reservation = %+v; want request=%+v and a live reserved lease", reservation, wantReservation)
	}

	encoded, err := os.ReadFile("testdata/laravel-quota-settlement-ack.json")
	if err != nil {
		t.Fatal(err)
	}
	var envelope quotaResponseEnvelope
	if err := decodeQuotaJSON(encoded, &envelope); err != nil {
		t.Fatalf("decode Laravel response envelope: %v", err)
	}
	var acknowledgement quotaSettlementAcknowledgement
	if err := decodeQuotaJSON(envelope.Data, &acknowledgement); err != nil {
		t.Fatalf("decode Laravel settlement acknowledgement: %v", err)
	}
	want := QuotaSettlement{
		NodeID: "node-test-1", PeerID: "peer-quota-1", Generation: 1,
		RequestID: "quota-ingress-1", AcknowledgementID: "quota-ingress-ack-1",
		UploadBytes: 100, DownloadBytes: 150, Final: true,
	}
	if acknowledgement.QuotaSettlement != want || !acknowledgement.Settled {
		t.Fatalf("Laravel settlement acknowledgement = %+v; want %+v and settled=true", acknowledgement, want)
	}
}

func TestHTTPQuotaAuthorityAllowsRetryAfterUnknownReservationOutcome(t *testing.T) {
	request := quotaHTTPTestRequest()
	var mu sync.Mutex
	seen := 0
	executions := 0
	var persisted QuotaReservation
	server, clientCertificate := newQuotaHTTPTestServer(t, func(writer http.ResponseWriter, incoming *http.Request) {
		var got QuotaReservationRequest
		if err := json.NewDecoder(incoming.Body).Decode(&got); err != nil {
			t.Errorf("decode reservation request: %v", err)
		}
		if got != request {
			t.Errorf("retry changed the reservation request: %+v", got)
		}
		mu.Lock()
		seen++
		call := seen
		if executions == 0 {
			executions++
			persisted = QuotaReservation{
				QuotaReservationRequest: request, ID: "reservation-http-2", Status: "reserved",
				LeaseExpiresAt: time.Now().Add(time.Minute),
			}
		}
		reservation := persisted
		mu.Unlock()
		if call == 1 {
			time.Sleep(100 * time.Millisecond)
			return
		}
		writeQuotaHTTPData(t, writer, reservation)
	})
	firstAuthority := newQuotaHTTPTestAuthority(t, server, clientCertificate, 20*time.Millisecond)
	if _, err := firstAuthority.Reserve(context.Background(), request); !errors.Is(err, ErrQuotaUnavailable) {
		t.Fatalf("first Reserve error = %v; want unknown outcome", err)
	}

	retryAuthority := newQuotaHTTPTestAuthority(t, server, clientCertificate, time.Second)
	reservation, err := retryAuthority.Reserve(context.Background(), request)
	if err != nil {
		t.Fatalf("same-ID Reserve retry error = %v", err)
	}
	mu.Lock()
	callCount, executionCount := seen, executions
	mu.Unlock()
	if reservation.QuotaReservationRequest != request || callCount != 2 || executionCount != 1 {
		t.Fatalf("retry did not recover the idempotent reservation: reservation=%+v calls=%d executions=%d", reservation, callCount, executionCount)
	}
}

func TestHTTPQuotaAuthorityRejectsMismatchedIdentityAndInsecureConfiguration(t *testing.T) {
	request := quotaHTTPTestRequest()
	server, clientCertificate := newQuotaHTTPTestServer(t, func(writer http.ResponseWriter, _ *http.Request) {
		writeQuotaHTTPData(t, writer, QuotaReservation{
			QuotaReservationRequest: QuotaReservationRequest{
				EntitlementID: request.EntitlementID, NodeID: "another-node", PeerID: request.PeerID,
				Generation: request.Generation, RequestID: request.RequestID, GrantBytes: request.GrantBytes,
			}, ID: "reservation-http-3", Status: "reserved", LeaseExpiresAt: time.Now().Add(time.Minute),
		})
	})
	authority := newQuotaHTTPTestAuthority(t, server, clientCertificate, time.Second)
	if _, err := authority.Reserve(context.Background(), request); !errors.Is(err, ErrQuotaUnavailable) {
		t.Fatalf("mismatched reservation error = %v; want fail-closed unavailable", err)
	}

	if _, err := NewHTTPQuotaAuthority("http://quota.invalid", x509.NewCertPool(), clientCertificate, time.Second); !errors.Is(err, ErrInvalid) {
		t.Fatalf("plain HTTP endpoint error = %v; want invalid configuration", err)
	}
	if _, err := NewHTTPQuotaAuthority("https://quota.invalid", x509.NewCertPool(), clientCertificate, 0); !errors.Is(err, ErrInvalid) {
		t.Fatalf("zero timeout error = %v; want invalid configuration", err)
	}
	serverOnlyCertificate := testQuotaClientCertificateWithUsage(t,
		[]x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}, time.Now().Add(time.Hour))
	if _, err := NewHTTPQuotaAuthority("https://quota.invalid", x509.NewCertPool(), serverOnlyCertificate, time.Second); !errors.Is(err, ErrInvalid) {
		t.Fatalf("server-only certificate error = %v; want invalid configuration", err)
	}
	expiredClientCertificate := testQuotaClientCertificateWithUsage(t,
		[]x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}, time.Now().Add(-time.Minute))
	if _, err := NewHTTPQuotaAuthority("https://quota.invalid", x509.NewCertPool(), expiredClientCertificate, time.Second); !errors.Is(err, ErrInvalid) {
		t.Fatalf("expired client certificate error = %v; want invalid configuration", err)
	}
	mismatchedClientCertificate := clientCertificate
	wrongClientKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	mismatchedClientCertificate.PrivateKey = wrongClientKey
	if _, err := NewHTTPQuotaAuthority("https://quota.invalid", x509.NewCertPool(), mismatchedClientCertificate, time.Second); !errors.Is(err, ErrInvalid) {
		t.Fatalf("mismatched signer error = %v; want invalid configuration", err)
	}
}

func TestHTTPQuotaAuthorityMapsExhaustionAndRejectsMismatchedSettlementReceipt(t *testing.T) {
	request := quotaHTTPTestRequest()
	server, clientCertificate := newQuotaHTTPTestServer(t, func(writer http.ResponseWriter, incoming *http.Request) {
		if incoming.URL.Path == quotaReservationPath {
			writeQuotaHTTPError(t, writer, http.StatusTooManyRequests, "quota_exhausted")
			return
		}
		receipt := QuotaSettlement{
			NodeID: request.NodeID, PeerID: request.PeerID, Generation: request.Generation,
			RequestID: "other-request", AcknowledgementID: "ack-wrong",
			UploadBytes: 1, DownloadBytes: 2, Final: true,
		}
		writeQuotaHTTPData(t, writer, quotaSettlementAcknowledgement{QuotaSettlement: receipt, Settled: true})
	})
	authority := newQuotaHTTPTestAuthority(t, server, clientCertificate, time.Second)
	if _, err := authority.Reserve(context.Background(), request); !errors.Is(err, ErrQuotaExhausted) {
		t.Fatalf("exhausted reservation error = %v; want quota exhausted", err)
	}

	if err := authority.Settle(context.Background(), QuotaSettlement{
		NodeID: request.NodeID, PeerID: request.PeerID, Generation: request.Generation,
		RequestID: request.RequestID, AcknowledgementID: "ack-request",
		UploadBytes: 1, DownloadBytes: 2, Final: true,
	}); !errors.Is(err, ErrQuotaUnavailable) {
		t.Fatalf("mismatched settlement acknowledgement error = %v; want fail-closed unavailable", err)
	}
}

func TestQuotaRequestIDsAreSafeURLSegments(t *testing.T) {
	for _, requestID := range []string{"", "../escape", "request/1", "request?query", strings.Repeat("a", 37)} {
		if validQuotaRequestID(requestID) {
			t.Errorf("request ID %q was accepted as a URL path segment", requestID)
		}
	}
	for _, requestID := range []string{"request-1", "550e8400-e29b-41d4-a716-446655440000"} {
		if !validQuotaRequestID(requestID) {
			t.Errorf("valid request ID %q was rejected", requestID)
		}
	}
}

func quotaHTTPTestRequest() QuotaReservationRequest {
	return QuotaReservationRequest{
		EntitlementID: "entitlement-http-1", NodeID: "node-http-1", PeerID: "peer-http-1",
		Generation: 3, RequestID: "request-http-1", GrantBytes: 4096,
	}
}

func newQuotaHTTPTestServer(t *testing.T, handler http.HandlerFunc) (*httptest.Server, tls.Certificate) {
	t.Helper()
	clientCertificate := testQuotaClientCertificate(t)
	clientPool := x509.NewCertPool()
	clientLeaf, err := x509.ParseCertificate(clientCertificate.Certificate[0])
	if err != nil {
		t.Fatalf("parse test client certificate: %v", err)
	}
	clientPool.AddCert(clientLeaf)
	server := httptest.NewUnstartedServer(handler)
	server.TLS = &tls.Config{
		MinVersion: tls.VersionTLS13, ClientAuth: tls.RequireAndVerifyClientCert, ClientCAs: clientPool,
	}
	server.StartTLS()
	t.Cleanup(server.Close)
	return server, clientCertificate
}

func newQuotaHTTPTestAuthority(t *testing.T, server *httptest.Server, clientCertificate tls.Certificate, timeout time.Duration) *HTTPQuotaAuthority {
	t.Helper()
	roots := x509.NewCertPool()
	roots.AddCert(server.Certificate())
	authority, err := NewHTTPQuotaAuthority(server.URL, roots, clientCertificate, timeout)
	if err != nil {
		t.Fatalf("NewHTTPQuotaAuthority error = %v", err)
	}
	return authority
}

func testQuotaClientCertificate(t *testing.T) tls.Certificate {
	t.Helper()
	return testQuotaClientCertificateWithUsage(t,
		[]x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}, time.Now().Add(time.Hour))
}

func testQuotaClientCertificateWithUsage(t *testing.T, usages []x509.ExtKeyUsage, notAfter time.Time) tls.Certificate {
	t.Helper()
	privateKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatalf("generate test client key: %v", err)
	}
	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
	if err != nil {
		t.Fatalf("generate test certificate serial: %v", err)
	}
	now := time.Now()
	template := &x509.Certificate{
		SerialNumber: serial,
		Subject:      pkix.Name{CommonName: "isolated-test-node"},
		NotBefore:    now.Add(-time.Minute), NotAfter: notAfter,
		KeyUsage:    x509.KeyUsageDigitalSignature | x509.KeyUsageCertSign,
		ExtKeyUsage: usages,
		IsCA:        true, BasicConstraintsValid: true,
	}
	certificateDER, err := x509.CreateCertificate(rand.Reader, template, template, &privateKey.PublicKey, privateKey)
	if err != nil {
		t.Fatalf("create test client certificate: %v", err)
	}
	privateKeyDER, err := x509.MarshalPKCS8PrivateKey(privateKey)
	if err != nil {
		t.Fatalf("marshal test client key: %v", err)
	}
	certificatePEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: certificateDER})
	privateKeyPEM := pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateKeyDER})
	certificate, err := tls.X509KeyPair(certificatePEM, privateKeyPEM)
	if err != nil {
		t.Fatalf("parse test client key pair: %v", err)
	}
	return certificate
}

func writeQuotaHTTPData(t *testing.T, writer http.ResponseWriter, data any) {
	t.Helper()
	writer.Header().Set("Content-Type", "application/json")
	writer.WriteHeader(http.StatusOK)
	if err := json.NewEncoder(writer).Encode(struct {
		Data any `json:"data"`
	}{Data: data}); err != nil {
		t.Errorf("encode response: %v", err)
	}
}

func writeQuotaHTTPError(t *testing.T, writer http.ResponseWriter, statusCode int, code string) {
	t.Helper()
	writer.Header().Set("Content-Type", "application/json")
	writer.WriteHeader(statusCode)
	if err := json.NewEncoder(writer).Encode(map[string]any{
		"error": map[string]string{"code": code, "message": "isolated test error"},
	}); err != nil {
		t.Errorf("encode error response: %v", err)
	}
}
