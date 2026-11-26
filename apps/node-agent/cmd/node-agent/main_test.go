package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"math/big"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	agent "xlb/node-agent"
)

func TestProductionModeRemainsUnavailable(t *testing.T) {
	t.Setenv("NODE_AGENT_MODE", "production")
	t.Setenv("NODE_AGENT_TLS_IDENTITY_SOURCE", "aws-kms")
	t.Setenv("NODE_AGENT_TLS_KMS_KEY_ID", "arn:aws:kms:us-west-2:123456789012:key/not-created-by-test")

	err := run()
	if err == nil || !strings.Contains(err.Error(), "production mode is not implemented") {
		t.Fatalf("run() error = %v; want production mode to fail closed", err)
	}
}

func TestRuntimeConfigMustMatchIsolatedAgentIdentity(t *testing.T) {
	productionConfig, err := json.Marshal(agent.TrustedNodeRuntimeConfig{
		SchemaVersion: agent.TrustedNodeRuntimeSchemaV2,
		Environment:   agent.RuntimeEnvironmentProd,
		Node:          agent.NodeRuntimeIdentity{ID: "node-a", Provider: "aws", Region: "us-west-2"},
		NodeTLSIdentity: agent.NodeTLSIdentityRuntimeConfig{
			Source: "aws-kms", KeyARN: "arn:aws:kms:us-west-2:123456789012:key/12345678-1234-1234-1234-123456789012",
		},
		ProofBridge: agent.ProofBridgeRuntimeConfig{
			Protocol: "xlb-proof-v1", ListenAddress: "127.0.0.1:10808",
			DeviceProofRequired: true, CredentialSource: agent.RuntimeCredentialSecure,
		},
		ProfileRenderer: agent.ProfileRendererRuntimeConfig{
			Status: agent.RuntimeProfileMihomoBridge, BridgeEndpoint: "https://bridge.example.invalid:9444",
			BridgeServerCAPEM: testRuntimeRendererCA(t),
		},
		QuotaAuthority: agent.QuotaAuthorityRuntimeConfig{
			Endpoint: "https://quota.example.invalid", ServerCAPEM: testRuntimeRendererCA(t),
			GrantBytes: 4096, OutboxLimit: 100, TimeoutSeconds: 5,
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	tests := []struct {
		name   string
		config string
		want   error
	}{
		{
			name:   "matching isolated test node",
			config: `{"schema_version":"xlb-node-runtime/v1","environment":"isolated-test","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"test-file-only"},"profile_renderer":{"status":"unavailable"}}`,
		},
		{
			name:   "production runtime is not enabled",
			config: `{"schema_version":"xlb-node-runtime/v1","environment":"production","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"platform-secure-store"},"profile_renderer":{"status":"unavailable"}}`,
			want:   agent.ErrInvalidNodeRuntimeConfig,
		},
		{
			name:   "production-shaped v2 renderer is not runnable",
			config: string(productionConfig),
			want:   agent.ErrInvalidNodeRuntimeConfig,
		},
		{
			name:   "node identity mismatch",
			config: `{"schema_version":"xlb-node-runtime/v1","environment":"isolated-test","node":{"id":"node-b","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"test-file-only"},"profile_renderer":{"status":"unavailable"}}`,
			want:   agent.ErrInvalidNodeRuntimeConfig,
		},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			path := filepath.Join(t.TempDir(), "runtime.json")
			if err := os.WriteFile(path, []byte(test.config), 0o600); err != nil {
				t.Fatal(err)
			}
			err := validateRuntimeConfig(path, "node-a")
			if test.want == nil && err != nil {
				t.Fatalf("validateRuntimeConfig error = %v", err)
			}
			if test.want != nil && !errors.Is(err, test.want) {
				t.Fatalf("validateRuntimeConfig error = %v; want %v", err, test.want)
			}
		})
	}
}

func TestRuntimeConfigCanSelectIsolatedMihomoRenderer(t *testing.T) {
	caPEM := testRuntimeRendererCA(t)
	config := agent.TrustedNodeRuntimeConfig{
		SchemaVersion: agent.TrustedNodeRuntimeSchemaV1,
		Environment:   agent.RuntimeEnvironmentTest,
		Node:          agent.NodeRuntimeIdentity{ID: "node-a", Provider: "aws", Region: "us-west-2"},
		ProofBridge: agent.ProofBridgeRuntimeConfig{
			Protocol: "xlb-proof-v1", ListenAddress: "127.0.0.1:10808",
			DeviceProofRequired: true, CredentialSource: agent.RuntimeCredentialTestFile,
		},
		ProfileRenderer: agent.ProfileRendererRuntimeConfig{
			Status: agent.RuntimeProfileMihomoBridgeTest, BridgeEndpoint: "https://bridge.example.invalid:9444",
			BridgeServerCAPEM: caPEM,
		},
	}
	content, err := json.Marshal(config)
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "runtime.json")
	if err := os.WriteFile(path, content, 0o600); err != nil {
		t.Fatal(err)
	}

	decoded, err := readRuntimeConfig(path, "node-a")
	if err != nil {
		t.Fatalf("readRuntimeConfig error = %v", err)
	}
	store, err := openPeerStoreForRuntimeConfig(filepath.Join(t.TempDir(), "agent.json"), "node-a", 4, decoded)
	if err != nil {
		t.Fatalf("openPeerStoreForRuntimeConfig error = %v", err)
	}
	if store == nil {
		t.Fatal("openPeerStoreForRuntimeConfig returned a nil store")
	}
	receipt, err := store.EnsurePeer(testRuntimeEnsureRequest(t))
	if err != nil {
		t.Fatalf("EnsurePeer error = %v", err)
	}
	if receipt.ConfirmationStatus != "confirmed_test" || receipt.ProfileArtifact == nil {
		t.Fatalf("renderer receipt status/artifact = %q/%v; want confirmed_test with profile",
			receipt.ConfirmationStatus, receipt.ProfileArtifact != nil)
	}
}

func TestProductionShapedRuntimeSelectsMihomoBridgeRendererAndConfirmedReceipt(t *testing.T) {
	caPEM := testRuntimeRendererCA(t)
	configPayload, err := json.Marshal(agent.TrustedNodeRuntimeConfig{
		SchemaVersion: agent.TrustedNodeRuntimeSchemaV2,
		Environment:   agent.RuntimeEnvironmentProd,
		Node:          agent.NodeRuntimeIdentity{ID: "node-a", Provider: "aws", Region: "us-west-2"},
		NodeTLSIdentity: agent.NodeTLSIdentityRuntimeConfig{
			Source: "aws-kms", KeyARN: "arn:aws:kms:us-west-2:123456789012:key/12345678-1234-1234-1234-123456789012",
		},
		ProofBridge: agent.ProofBridgeRuntimeConfig{
			Protocol: "xlb-proof-v1", ListenAddress: "127.0.0.1:10808",
			DeviceProofRequired: true, CredentialSource: agent.RuntimeCredentialSecure,
		},
		ProfileRenderer: agent.ProfileRendererRuntimeConfig{
			Status: agent.RuntimeProfileMihomoBridge, BridgeEndpoint: "https://bridge.example.invalid:9444",
			BridgeServerCAPEM: caPEM,
		},
		QuotaAuthority: agent.QuotaAuthorityRuntimeConfig{
			Endpoint: "https://quota.example.invalid", ServerCAPEM: caPEM,
			GrantBytes: 4096, OutboxLimit: 100, TimeoutSeconds: 5,
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	config, err := agent.DecodeTrustedNodeRuntimeConfig(strings.NewReader(string(configPayload)))
	if err != nil {
		t.Fatalf("DecodeTrustedNodeRuntimeConfig error = %v", err)
	}
	store, err := openPeerStoreForRuntimeConfig(filepath.Join(t.TempDir(), "agent.json"), "node-a", 4, config)
	if err != nil {
		t.Fatalf("openPeerStoreForRuntimeConfig error = %v", err)
	}

	ensureRequest := testRuntimeEnsureRequest(t)
	receipt, err := store.EnsurePeer(ensureRequest)
	if err != nil {
		t.Fatalf("EnsurePeer error = %v", err)
	}
	if receipt.ConfirmationStatus != "confirmed" || receipt.ProfileArtifact == nil || !receipt.ProfileArtifact.Connectable {
		t.Fatalf("production-shaped renderer receipt = %q/%v; want confirmed connectable artifact",
			receipt.ConfirmationStatus, receipt.ProfileArtifact)
	}
	profileRequest := agent.ProfileRenderRequest{
		NodeID: ensureRequest.NodeID, PeerID: ensureRequest.PeerID, EntitlementID: ensureRequest.EntitlementID,
		RequestID: ensureRequest.RequestID, Generation: ensureRequest.Generation,
		DeviceID: ensureRequest.DeviceID, DeviceKeyFingerprint: ensureRequest.DeviceKeyFingerprint,
		DeviceBindingGeneration: ensureRequest.DeviceBindingGeneration, BridgeProtocol: "xlb-proof-v1",
		BridgeEndpoint: config.ProfileRenderer.BridgeEndpoint, BridgeServerCAPEM: config.ProfileRenderer.BridgeServerCAPEM,
	}
	if err := agent.ValidateProfileArtifact(profileRequest, *receipt.ProfileArtifact); err != nil {
		t.Fatalf("ValidateProfileArtifact error = %v", err)
	}
}

func TestRuntimeConfigPathIsRequiredAtStartup(t *testing.T) {
	t.Setenv("NODE_AGENT_MODE", "isolated-test")
	t.Setenv("NODE_AGENT_NODE_ID", "node-a")
	t.Setenv("NODE_AGENT_RUNTIME_CONFIG", "")

	err := run()
	if err == nil || !strings.Contains(err.Error(), "identity, runtime config, and state path are required") {
		t.Fatalf("run() error = %v; want required runtime config error", err)
	}
}

func TestLoadNodeTLSIdentityUsesFileProviderOnlyForIsolatedTestMode(t *testing.T) {
	now := time.Now()
	serverKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	serverCertificateDER, err := x509.CreateCertificate(rand.Reader, &x509.Certificate{
		SerialNumber: big.NewInt(55), Subject: pkix.Name{CommonName: "localhost"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		DNSNames: []string{"localhost"},
	}, &x509.Certificate{
		SerialNumber: big.NewInt(55), Subject: pkix.Name{CommonName: "localhost"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		DNSNames: []string{"localhost"},
	}, &serverKey.PublicKey, serverKey)
	if err != nil {
		t.Fatal(err)
	}
	clientCAPEM := []byte(testRuntimeRendererCA(t))
	directory := t.TempDir()
	certificatePath := filepath.Join(directory, "node.crt")
	privateKeyPath := filepath.Join(directory, "node.key")
	clientCAPath := filepath.Join(directory, "client-ca.crt")
	if err := os.WriteFile(certificatePath, pem.EncodeToMemory(&pem.Block{
		Type: "CERTIFICATE", Bytes: serverCertificateDER,
	}), 0o600); err != nil {
		t.Fatal(err)
	}
	privateDER, err := x509.MarshalPKCS8PrivateKey(serverKey)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(privateKeyPath, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateDER}), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(clientCAPath, clientCAPEM, 0o600); err != nil {
		t.Fatal(err)
	}
	identity, err := loadNodeTLSIdentity("node-a", "test-files", certificatePath, privateKeyPath, clientCAPath, "", "")
	if err != nil {
		t.Fatalf("test file identity load error = %v", err)
	}
	if identity.Certificate.PrivateKey == nil {
		t.Fatal("isolated test identity did not load its file-backed private key")
	}
	if _, err := loadNodeTLSIdentity("node-a", "aws-kms", certificatePath, privateKeyPath, clientCAPath, "key", "us-west-2"); err == nil {
		t.Fatal("AWS KMS source accepted a private-key file path")
	}
	if _, err := loadNodeTLSIdentity("node-a", "unknown", certificatePath, privateKeyPath, clientCAPath, "", ""); err == nil {
		t.Fatal("unknown node identity source was accepted")
	}
}

func TestResolveNodeTLSIdentitySourceUsesTrustedRuntimeARNInProduction(t *testing.T) {
	keyARN := "arn:aws:kms:us-west-2:123456789012:key/mrk-12345678-1234-1234-1234-123456789012"
	productionConfig := agent.TrustedNodeRuntimeConfig{
		SchemaVersion: agent.TrustedNodeRuntimeSchemaV2,
		Environment:   agent.RuntimeEnvironmentProd,
		Node:          agent.NodeRuntimeIdentity{ID: "node-a", Provider: "aws", Region: "us-west-2"},
		NodeTLSIdentity: agent.NodeTLSIdentityRuntimeConfig{
			Source: "aws-kms", KeyARN: keyARN,
		},
	}
	selection, err := resolveNodeTLSIdentitySource(productionConfig, "", "")
	if err != nil {
		t.Fatalf("resolveNodeTLSIdentitySource error = %v", err)
	}
	if selection.source != "aws-kms" || selection.keyARN != keyARN || selection.region != "us-west-2" {
		t.Fatalf("resolved source/ARN/region=%q/%q/%q; want aws-kms/%q/us-west-2",
			selection.source, selection.keyARN, selection.region, keyARN)
	}
	if _, err := resolveNodeTLSIdentitySource(productionConfig, "aws-kms", keyARN); err == nil {
		t.Fatal("production runtime accepted environment overrides for its trusted KMS key")
	}
}

func TestResolveNodeTLSIdentitySourceKeepsIsolatedTestDefaultsAndExplicitKMSSelection(t *testing.T) {
	isolatedConfig := agent.TrustedNodeRuntimeConfig{
		SchemaVersion: agent.TrustedNodeRuntimeSchemaV1,
		Environment:   agent.RuntimeEnvironmentTest,
		Node:          agent.NodeRuntimeIdentity{ID: "node-a", Provider: "aws", Region: "us-west-2"},
	}
	selection, err := resolveNodeTLSIdentitySource(isolatedConfig, "", "")
	if err != nil || selection.source != "test-files" || selection.keyARN != "" || selection.region != "" {
		t.Fatalf("default isolated-test source=%q/%q/%q err=%v",
			selection.source, selection.keyARN, selection.region, err)
	}
	keyARN := "arn:aws:kms:us-west-2:123456789012:key/12345678-1234-1234-1234-123456789012"
	selection, err = resolveNodeTLSIdentitySource(isolatedConfig, "aws-kms", keyARN)
	if err != nil || selection.source != "aws-kms" || selection.keyARN != keyARN || selection.region != "us-west-2" {
		t.Fatalf("explicit isolated-test KMS source=%q/%q/%q err=%v",
			selection.source, selection.keyARN, selection.region, err)
	}
	wrongRegionARN := "arn:aws:kms:us-east-1:123456789012:key/12345678-1234-1234-1234-123456789012"
	if _, err := resolveNodeTLSIdentitySource(isolatedConfig, "aws-kms", wrongRegionARN); err == nil {
		t.Fatal("isolated-test KMS source accepted a key ARN from another node region")
	}
}

func TestProbeListenerMustBeDistinctLoopback(t *testing.T) {
	tests := []struct {
		name    string
		address string
		wantErr bool
	}{
		{name: "disabled by default"},
		{name: "loopback IP", address: "127.0.0.1:9445"},
		{name: "localhost", address: "localhost:9445"},
		{name: "wildcard bind", address: "0.0.0.0:9445", wantErr: true},
		{name: "public bind", address: "203.0.113.7:9445", wantErr: true},
		{name: "management address collision", address: "127.0.0.1:9443", wantErr: true},
		{name: "normalized management port collision", address: "localhost:09443", wantErr: true},
		{name: "data plane address collision", address: "127.0.0.1:9444", wantErr: true},
		{name: "invalid address", address: "not-an-address", wantErr: true},
		{name: "invalid port", address: "127.0.0.1:94450", wantErr: true},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			err := validateProbeListenerAddress(test.address, "127.0.0.1:9443", "127.0.0.1:9444")
			if test.wantErr && err == nil {
				t.Fatal("expected probe listener address to be rejected")
			}
			if !test.wantErr && err != nil {
				t.Fatalf("probe listener address rejected: %v", err)
			}
		})
	}
}

func TestProbeServerIsNotConstructedWhenDisabled(t *testing.T) {
	server, err := newDataPlaneProbeServer("", "node-a", agent.NodeTLSIdentity{}, agent.DataPlaneProbeRuntimeConfig{})
	if err != nil {
		t.Fatalf("disabled probe listener returned error: %v", err)
	}
	if server != nil {
		t.Fatal("disabled probe listener unexpectedly constructed a server")
	}
}

func TestConfiguredCanaryRequiresExplicitProbeListener(t *testing.T) {
	config := agent.DataPlaneProbeRuntimeConfig{Status: agent.RuntimeProbeHTTPSCanary}
	if _, err := newDataPlaneProbeServer("", "node-a", agent.NodeTLSIdentity{}, config); err == nil ||
		!strings.Contains(err.Error(), "requires NODE_AGENT_PROBE_LISTEN") {
		t.Fatalf("newDataPlaneProbeServer error = %v; want explicit probe-listener requirement", err)
	}
}

func testRuntimeRendererCA(t *testing.T) string {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	template := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "isolated test CA"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), IsCA: true,
		BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
	}
	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
}

func testRuntimeEnsureRequest(t *testing.T) agent.EnsurePeerRequest {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	publicKeyDER, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	fingerprint := sha256.Sum256(publicKeyDER)
	return agent.EnsurePeerRequest{
		NodeID: "node-a", PeerID: "peer-a", EntitlementID: "entitlement-a", RequestID: "request-a",
		Generation: 1, DeviceID: "device-a", DeviceName: "isolated test device",
		DevicePublicKey:      base64.RawURLEncoding.EncodeToString(publicKeyDER),
		DeviceKeyFingerprint: hex.EncodeToString(fingerprint[:]), DeviceBindingGeneration: 2,
		SubscriptionID: "subscription-a", SubscriptionToken: "test-token-a", SubscriptionGeneration: 1,
		PeerGeneration: 1, DeviceGeneration: 2, ExpiresAt: time.Now().Add(time.Hour), Credential: "test-peer-credential",
	}
}
