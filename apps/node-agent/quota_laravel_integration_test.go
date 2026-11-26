package agent

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"net/http"
	"net/http/cgi"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestHTTPQuotaAuthorityAgainstLaravelCGI(t *testing.T) {
	if os.Getenv("XLB_LARAVEL_CGI_INTEGRATION") != "1" {
		t.Skip("set by the Laravel feature test to run the local CGI/mTLS integration")
	}

	phpRoot := requiredIntegrationEnv(t, "XLB_LARAVEL_PHP_API_ROOT")
	databasePath := requiredIntegrationEnv(t, "XLB_LARAVEL_DB_PATH")
	clientCertificatePath := requiredIntegrationEnv(t, "XLB_LARAVEL_CLIENT_CERT")
	clientKeyPath := requiredIntegrationEnv(t, "XLB_LARAVEL_CLIENT_KEY")
	requestID := requiredIntegrationEnv(t, "XLB_LARAVEL_REQUEST_ID")
	appKey := requiredIntegrationEnv(t, "XLB_LARAVEL_APP_KEY")
	if !filepath.IsAbs(phpRoot) || !filepath.IsAbs(databasePath) {
		t.Fatal("Laravel root and SQLite database path must be absolute")
	}

	phpCGI, err := exec.LookPath("php-cgi")
	if err != nil {
		t.Fatalf("locate php-cgi: %v", err)
	}
	clientCertificate, err := tls.LoadX509KeyPair(clientCertificatePath, clientKeyPath)
	if err != nil {
		t.Fatalf("load Laravel node client identity: %v", err)
	}
	clientLeaf, err := x509.ParseCertificate(clientCertificate.Certificate[0])
	if err != nil {
		t.Fatalf("parse Laravel node client certificate: %v", err)
	}
	clientRoots := x509.NewCertPool()
	clientRoots.AddCert(clientLeaf)

	scriptPath := filepath.Join(phpRoot, "public", "index.php")
	baseCGIEnvironment := []string{
		"REDIRECT_STATUS=200",
		"APP_KEY=" + appKey,
		"APP_ENV=testing",
		"DB_CONNECTION=sqlite",
		"DB_DATABASE=" + databasePath,
		"SCRIPT_FILENAME=" + scriptPath,
	}
	cgiHandler := &cgi.Handler{
		Path: phpCGI,
		Args: []string{scriptPath},
		Dir:  phpRoot,
	}
	var cgiEnvironmentLock sync.Mutex
	handler := http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.TLS == nil || len(request.TLS.VerifiedChains) == 0 || len(request.TLS.PeerCertificates) == 0 {
			http.Error(writer, "verified node certificate required", http.StatusUnauthorized)
			return
		}
		verifiedCertificate := pem.EncodeToMemory(&pem.Block{
			Type: "CERTIFICATE", Bytes: request.TLS.PeerCertificates[0].Raw,
		})
		if len(verifiedCertificate) == 0 {
			http.Error(writer, "verified node certificate unavailable", http.StatusUnauthorized)
			return
		}

		cgiEnvironmentLock.Lock()
		defer cgiEnvironmentLock.Unlock()
		cgiHandler.Env = append(append([]string(nil), baseCGIEnvironment...),
			"SSL_CLIENT_VERIFY=SUCCESS", "SSL_CLIENT_CERT="+strings.TrimSpace(string(verifiedCertificate)))
		cgiHandler.ServeHTTP(writer, request)
	})
	quotaServerCertificate, _, quotaServerCAPEM := createTestCertificates(t)
	server := httptest.NewUnstartedServer(handler)
	server.TLS = &tls.Config{
		MinVersion:   tls.VersionTLS13,
		Certificates: []tls.Certificate{quotaServerCertificate},
		ClientAuth:   tls.RequireAndVerifyClientCert,
		ClientCAs:    clientRoots,
	}
	server.StartTLS()
	t.Cleanup(server.Close)

	serverRoots := testRootPool(t, quotaServerCAPEM)
	unauthenticatedClient := &http.Client{Transport: &http.Transport{
		TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS13, RootCAs: serverRoots},
	}}
	if _, err := unauthenticatedClient.Post(server.URL+quotaReservationPath, "application/json", strings.NewReader("{}")); err == nil {
		t.Fatal("Laravel CGI mTLS ingress accepted a client without a certificate")
	}

	_, _, rendererCAPEM := createTestCertificates(t)
	runtimeConfig := TrustedNodeRuntimeConfig{
		SchemaVersion: TrustedNodeRuntimeSchemaV2,
		Environment:   RuntimeEnvironmentProd,
		Node:          NodeRuntimeIdentity{ID: "node-test-1", Provider: "aws", Region: "us-west-2"},
		NodeTLSIdentity: NodeTLSIdentityRuntimeConfig{
			Source: "aws-kms", KeyARN: "arn:aws:kms:us-west-2:123456789012:key/12345678-1234-1234-1234-123456789012",
			CertificateChainPath: "/etc/xlb/node-chain.pem", ClientCAPEMPath: "/etc/xlb/client-ca.pem",
		},
		AgentListeners: AgentListenerRuntimeConfig{
			ManagementListenAddress: "127.0.0.1:9443", DataPlaneListenAddress: "127.0.0.1:9444",
		},
		ProofBridge: ProofBridgeRuntimeConfig{
			Protocol: "xlb-proof-v1", ListenAddress: "127.0.0.1:10808",
			DeviceProofRequired: true, CredentialSource: RuntimeCredentialSecure,
		},
		ProfileRenderer: ProfileRendererRuntimeConfig{
			Status: RuntimeProfileMihomoBridge, BridgeEndpoint: "https://bridge.example.invalid:9444",
			BridgeServerCAPEM: string(rendererCAPEM),
		},
		QuotaAuthority: QuotaAuthorityRuntimeConfig{
			Endpoint: server.URL, ServerCAPEM: string(quotaServerCAPEM),
			GrantBytes: 512, OutboxLimit: 20, TimeoutSeconds: 10,
		},
	}
	quotaStore, err := OpenPeerStore(filepath.Join(t.TempDir(), "quota-agent.json"), runtimeConfig.Node.ID, 4)
	if err != nil {
		t.Fatal(err)
	}
	runtimeDataPlane, err := NewRuntimeDataPlane(context.Background(), quotaStore, NewPublicTargetDialer(),
		NodeTLSIdentity{Certificate: clientCertificate, ClientCAs: clientRoots}, runtimeConfig)
	if err != nil {
		t.Fatalf("initialize runtime quota data plane against Laravel CGI: %v", err)
	}

	authority, err := NewHTTPQuotaAuthority(server.URL, serverRoots, clientCertificate, 10*time.Second)
	if err != nil {
		t.Fatalf("create Laravel quota authority client: %v", err)
	}
	reservationRequest := QuotaReservationRequest{
		EntitlementID: "entitlement-member-annual", NodeID: "node-test-1", PeerID: "peer-quota-1",
		Generation: 1, RequestID: requestID, GrantBytes: 512,
	}
	wrongNodeRequest := reservationRequest
	wrongNodeRequest.NodeID = "another-node"
	if _, err := authority.Reserve(context.Background(), wrongNodeRequest); !errors.Is(err, ErrQuotaUnavailable) {
		t.Fatalf("certificate-to-node mismatch error = %v; want a fail-closed authorization error", err)
	}
	reservation, err := authority.Reserve(context.Background(), reservationRequest)
	if err != nil {
		t.Fatalf("reserve through Laravel CGI/mTLS: %v", err)
	}
	if reservation.QuotaReservationRequest != reservationRequest || reservation.Status != "reserved" {
		t.Fatalf("Laravel reservation response does not match request: %+v", reservation)
	}
	retry, err := authority.Reserve(context.Background(), reservationRequest)
	if err != nil || retry.ID != reservation.ID || !retry.LeaseExpiresAt.Equal(reservation.LeaseExpiresAt) {
		t.Fatalf("same request ID did not return its original lease: first=%+v retry=%+v err=%v", reservation, retry, err)
	}

	settlement := QuotaSettlement{
		NodeID: reservationRequest.NodeID, PeerID: reservationRequest.PeerID, Generation: reservationRequest.Generation,
		RequestID: requestID, AcknowledgementID: requestID + "-final", UploadBytes: 17, DownloadBytes: 23, Final: true,
	}
	if err := authority.Settle(context.Background(), settlement); err != nil {
		t.Fatalf("settle through Laravel CGI/mTLS: %v", err)
	}
	if err := authority.Settle(context.Background(), settlement); err != nil {
		t.Fatalf("idempotent final settlement retry: %v", err)
	}
	runtimeLease, err := runtimeDataPlane.quotaProvider.OpenLease(context.Background(), PeerQuotaIdentity{
		NodeID: runtimeConfig.Node.ID, EntitlementID: reservationRequest.EntitlementID,
		PeerID: reservationRequest.PeerID, Generation: reservationRequest.Generation,
	})
	if err != nil {
		t.Fatalf("reserve through runtime quota initializer: %v", err)
	}
	permit, err := runtimeLease.Permit("upload", 17)
	if err != nil {
		t.Fatal(err)
	}
	if err := permit.Commit(17); err != nil {
		t.Fatal(err)
	}
	if err := runtimeLease.Finalize(context.Background(), requestID+"-runtime-final"); err != nil {
		t.Fatalf("settle runtime quota lease through Laravel CGI: %v", err)
	}
}

func requiredIntegrationEnv(t *testing.T, name string) string {
	t.Helper()
	value := os.Getenv(name)
	if value == "" {
		t.Fatalf("required integration environment variable %s is missing", name)
	}
	return value
}
