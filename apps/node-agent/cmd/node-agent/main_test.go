package main

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
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
	"runtime"
	"strings"
	"testing"
	"time"

	agent "xlb/node-agent"
)

func TestProductionModeRequiresTrustedStartupConfigurationBeforeAWSAccess(t *testing.T) {
	t.Setenv("NODE_AGENT_MODE", "production")

	err := run()
	if err == nil || !strings.Contains(err.Error(), "identity, runtime config, and state path are required") {
		t.Fatalf("run() error = %v; want production mode to require trusted startup inputs before AWS access", err)
	}
}

func TestRuntimeEnvironmentForModeAcceptsOnlyExplicitModes(t *testing.T) {
	tests := []struct {
		mode        string
		wantEnv     string
		wantFailure bool
	}{
		{mode: "isolated-test", wantEnv: agent.RuntimeEnvironmentTest},
		{mode: "production", wantEnv: agent.RuntimeEnvironmentProd},
		{mode: "", wantFailure: true},
		{mode: "staging", wantFailure: true},
	}

	for _, test := range tests {
		t.Run(test.mode, func(t *testing.T) {
			got, err := runtimeEnvironmentForMode(test.mode)
			if test.wantFailure {
				if err == nil {
					t.Fatalf("runtimeEnvironmentForMode(%q) unexpectedly succeeded", test.mode)
				}
				return
			}
			if err != nil || got != test.wantEnv {
				t.Fatalf("runtimeEnvironmentForMode(%q) = %q, %v; want %q", test.mode, got, err, test.wantEnv)
			}
		})
	}
}

func TestRuntimeConfigMustMatchModeAndAgentIdentity(t *testing.T) {
	productionConfig, err := json.Marshal(agent.TrustedNodeRuntimeConfig{
		SchemaVersion: agent.TrustedNodeRuntimeSchemaV2,
		Environment:   agent.RuntimeEnvironmentProd,
		Node:          agent.NodeRuntimeIdentity{ID: "node-a", Provider: "aws", Region: "us-west-2"},
		NodeTLSIdentity: agent.NodeTLSIdentityRuntimeConfig{
			Source: "aws-kms", KeyARN: "arn:aws:kms:us-west-2:123456789012:key/12345678-1234-1234-1234-123456789012",
			CertificateChainPath: "/etc/xlb/node-chain.pem", ClientCAPEMPath: "/etc/xlb/client-ca.pem",
		},
		AgentListeners: agent.AgentListenerRuntimeConfig{
			ManagementListenAddress: "127.0.0.1:9443", DataPlaneListenAddress: "127.0.0.1:9444",
		},
		ProofBridge: agent.ProofBridgeRuntimeConfig{
			Protocol: "xlb-proof-v1", ListenAddress: "127.0.0.1:17890",
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
			name:   "production schema v1 is invalid",
			config: `{"schema_version":"xlb-node-runtime/v1","environment":"production","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"platform-secure-store"},"profile_renderer":{"status":"unavailable"}}`,
			want:   agent.ErrInvalidNodeRuntimeConfig,
		},
		{
			name:   "production v2 config is rejected in isolated-test mode",
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
			err := validateRuntimeConfig(path, "node-a", agent.RuntimeEnvironmentTest)
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
			Protocol: "xlb-proof-v1", ListenAddress: "127.0.0.1:17890",
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

	decoded, err := readRuntimeConfig(path, "node-a", agent.RuntimeEnvironmentTest)
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

func TestReadRuntimeConfigAcceptsTrustedProductionV2OnlyForProductionMode(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("production trusted-file ownership validation is not implemented on Windows")
	}
	configPayload, err := json.Marshal(productionNodeRuntimeConfig(t))
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "production-runtime.json")
	if err := os.WriteFile(path, configPayload, 0o600); err != nil {
		t.Fatal(err)
	}

	config, err := readRuntimeConfig(path, "node-a", agent.RuntimeEnvironmentProd)
	if err != nil {
		t.Fatalf("readRuntimeConfig production v2 error = %v", err)
	}
	if config.SchemaVersion != agent.TrustedNodeRuntimeSchemaV2 || config.Environment != agent.RuntimeEnvironmentProd {
		t.Fatalf("runtime config identity = %q/%q; want trusted production v2", config.SchemaVersion, config.Environment)
	}
	if _, err := readRuntimeConfig(path, "node-a", agent.RuntimeEnvironmentTest); err == nil {
		t.Fatal("production runtime config was accepted in isolated-test mode")
	}
	if _, err := readRuntimeConfig(path, "node-b", agent.RuntimeEnvironmentProd); err == nil {
		t.Fatal("production runtime config was accepted for another node identity")
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
			CertificateChainPath: "/etc/xlb/node-chain.pem", ClientCAPEMPath: "/etc/xlb/client-ca.pem",
		},
		AgentListeners: agent.AgentListenerRuntimeConfig{
			ManagementListenAddress: "127.0.0.1:9443", DataPlaneListenAddress: "127.0.0.1:9444",
		},
		ProofBridge: agent.ProofBridgeRuntimeConfig{
			Protocol: "xlb-proof-v1", ListenAddress: "127.0.0.1:17890",
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

func TestRuntimeConfigRejectsSymlinkAndGroupWritableFiles(t *testing.T) {
	validConfig := `{"schema_version":"xlb-node-runtime/v1","environment":"isolated-test","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"test-file-only"},"profile_renderer":{"status":"unavailable"}}`
	directory := t.TempDir()
	protectedPath := filepath.Join(directory, "runtime.json")
	if err := os.WriteFile(protectedPath, []byte(validConfig), 0o600); err != nil {
		t.Fatal(err)
	}
	symlinkPath := filepath.Join(directory, "runtime-link.json")
	if err := os.Symlink(protectedPath, symlinkPath); err != nil {
		t.Fatal(err)
	}
	if _, err := readRuntimeConfig(symlinkPath, "node-a", agent.RuntimeEnvironmentTest); err == nil {
		t.Fatal("runtime config loader accepted a symlink")
	}
	if err := os.Chmod(protectedPath, 0o660); err != nil {
		t.Fatal(err)
	}
	if _, err := readRuntimeConfig(protectedPath, "node-a", agent.RuntimeEnvironmentTest); err == nil {
		t.Fatal("runtime config loader accepted a group-writable file")
	}
}

func TestProductionModeRejectsUntrustedRuntimeFilesBeforeKMSAccess(t *testing.T) {
	configPayload, err := json.Marshal(productionNodeRuntimeConfig(t))
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "runtime.json")
	if err := os.WriteFile(path, configPayload, 0o660); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(path, 0o660); err != nil {
		t.Fatal(err)
	}

	t.Setenv("NODE_AGENT_MODE", "production")
	t.Setenv("NODE_AGENT_NODE_ID", "node-a")
	t.Setenv("NODE_AGENT_RUNTIME_CONFIG", path)
	t.Setenv("NODE_AGENT_STATE_PATH", filepath.Join(t.TempDir(), "state"))
	t.Setenv("NODE_AGENT_CAPACITY", "4")
	if err := run(); err == nil || !strings.Contains(err.Error(), "protected regular file") {
		t.Fatalf("run() error = %v; want untrusted runtime file to fail before KMS access", err)
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
			CertificateChainPath: "/etc/xlb/node-chain.pem", ClientCAPEMPath: "/etc/xlb/client-ca.pem",
		},
		AgentListeners: agent.AgentListenerRuntimeConfig{
			ManagementListenAddress: "127.0.0.1:9443", DataPlaneListenAddress: "127.0.0.1:9444",
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

func TestProductionRuntimeResolvesTrustedListenerAndCertificatePathsOnly(t *testing.T) {
	config := productionNodeRuntimeConfig(t)
	listeners, err := resolveNodeAgentListenAddresses(config, "", "", "")
	if err != nil {
		t.Fatalf("resolveNodeAgentListenAddresses error = %v", err)
	}
	wantListeners := nodeAgentListenAddresses{
		management: "127.0.0.1:9443",
		dataPlane:  "127.0.0.1:9444",
	}
	if listeners != wantListeners {
		t.Fatalf("resolved listeners = %+v; want %+v", listeners, wantListeners)
	}
	if _, err := resolveNodeAgentListenAddresses(config, "127.0.0.1:10443", "", ""); err == nil {
		t.Fatal("production runtime accepted an environment override for its management listener")
	}
	if _, err := resolveNodeAgentListenAddresses(config, "", "0.0.0.0:10444", ""); err == nil {
		t.Fatal("production runtime accepted an environment override for its data-plane listener")
	}
	if _, err := resolveNodeAgentListenAddresses(config, "", "", "127.0.0.1:10445"); err == nil {
		t.Fatal("production runtime accepted an environment override for its probe listener")
	}

	certificate, privateKey, clientCA, err := resolveNodeTLSCertificateFiles(config, "", "", "")
	if err != nil {
		t.Fatalf("resolveNodeTLSCertificateFiles error = %v", err)
	}
	if certificate != config.NodeTLSIdentity.CertificateChainPath || privateKey != "" ||
		clientCA != config.NodeTLSIdentity.ClientCAPEMPath {
		t.Fatalf("resolved TLS files = %q/%q/%q; want trusted public files and no private-key path",
			certificate, privateKey, clientCA)
	}
	if _, _, _, err := resolveNodeTLSCertificateFiles(config, "/tmp/override.pem", "", ""); err == nil {
		t.Fatal("production runtime accepted an environment override for its certificate chain")
	}
	if _, _, _, err := resolveNodeTLSCertificateFiles(config, "", "", "/tmp/override-ca.pem"); err == nil {
		t.Fatal("production runtime accepted an environment override for its client CA")
	}
	if _, _, _, err := resolveNodeTLSCertificateFiles(config, "", "/tmp/private-key.pem", ""); err == nil {
		t.Fatal("production runtime accepted a file-backed private-key path")
	}
}

func TestIsolatedRuntimeKeepsLoopbackDefaultsAndEnvironmentOverrides(t *testing.T) {
	config := agent.TrustedNodeRuntimeConfig{
		SchemaVersion: agent.TrustedNodeRuntimeSchemaV1,
		Environment:   agent.RuntimeEnvironmentTest,
		Node:          agent.NodeRuntimeIdentity{ID: "node-a", Provider: "aws", Region: "us-west-2"},
	}
	listeners, err := resolveNodeAgentListenAddresses(config, "", "", "")
	if err != nil {
		t.Fatalf("resolveNodeAgentListenAddresses error = %v", err)
	}
	want := nodeAgentListenAddresses{management: "127.0.0.1:9443", dataPlane: "127.0.0.1:9444"}
	if listeners != want {
		t.Fatalf("default listeners = %+v; want %+v", listeners, want)
	}
	listeners, err = resolveNodeAgentListenAddresses(config, "localhost:19443", "127.0.0.1:19444", "")
	if err != nil {
		t.Fatalf("resolveNodeAgentListenAddresses with isolated overrides error = %v", err)
	}
	if listeners.management != "localhost:19443" || listeners.dataPlane != "127.0.0.1:19444" {
		t.Fatalf("isolated listeners = %+v; want requested loopback addresses", listeners)
	}
	if _, err := resolveNodeAgentListenAddresses(config, "0.0.0.0:19443", "", ""); err == nil {
		t.Fatal("isolated runtime accepted a non-loopback management listener")
	}
}

func TestProductionRuntimeAssemblyBuildsLoopbackMTLSServersWithoutBinding(t *testing.T) {
	config := productionNodeRuntimeConfig(t)
	listeners, err := resolveNodeAgentListenAddresses(config, "", "", "")
	if err != nil {
		t.Fatalf("resolveNodeAgentListenAddresses error = %v", err)
	}
	store, err := openPeerStoreForRuntimeConfig(filepath.Join(t.TempDir(), "peer-store.json"),
		config.Node.ID, 4, config)
	if err != nil {
		t.Fatalf("openPeerStoreForRuntimeConfig error = %v", err)
	}
	servers, err := buildRuntimeHTTPServers(context.Background(), config, listeners, store, testRuntimeNodeTLSIdentity(t))
	if err != nil {
		t.Fatalf("buildRuntimeHTTPServers error = %v", err)
	}
	if len(servers) != 2 {
		t.Fatalf("runtime server count = %d; want management and data-plane servers", len(servers))
	}
	if servers[0].Addr != listeners.management || servers[1].Addr != listeners.dataPlane {
		t.Fatalf("runtime server addresses = %q/%q; want %q/%q",
			servers[0].Addr, servers[1].Addr, listeners.management, listeners.dataPlane)
	}
	for _, server := range servers {
		if server.Handler == nil || server.TLSConfig == nil || server.TLSConfig.MinVersion != tls.VersionTLS13 {
			t.Fatalf("runtime server is missing its handler or TLS 1.3 config: %+v", server)
		}
	}
	if servers[0].TLSConfig.ClientAuth != tls.RequireAndVerifyClientCert ||
		servers[1].TLSConfig.ClientAuth != tls.RequireAnyClientCert {
		t.Fatalf("management/data client-auth modes = %v/%v; want verified control and proof-checked data certificates",
			servers[0].TLSConfig.ClientAuth, servers[1].TLSConfig.ClientAuth)
	}
	overriddenListeners := listeners
	overriddenListeners.management = "127.0.0.1:19443"
	if _, err := buildRuntimeHTTPServers(context.Background(), config, overriddenListeners, store,
		testRuntimeNodeTLSIdentity(t)); err == nil {
		t.Fatal("production runtime assembly accepted a listener override outside the trusted config")
	}
}

func TestRuntimeAssemblyRejectsIsolatedWildcardListener(t *testing.T) {
	config := agent.TrustedNodeRuntimeConfig{
		SchemaVersion: agent.TrustedNodeRuntimeSchemaV1,
		Environment:   agent.RuntimeEnvironmentTest,
		Node:          agent.NodeRuntimeIdentity{ID: "node-a", Provider: "aws", Region: "us-west-2"},
		ProofBridge: agent.ProofBridgeRuntimeConfig{
			Protocol: "xlb-proof-v1", ListenAddress: "127.0.0.1:17890",
			DeviceProofRequired: true, CredentialSource: agent.RuntimeCredentialTestFile,
		},
		ProfileRenderer: agent.ProfileRendererRuntimeConfig{Status: agent.RuntimeProfileUnavailable},
	}
	store, err := agent.OpenPeerStore(filepath.Join(t.TempDir(), "peer-store.json"), config.Node.ID, 4)
	if err != nil {
		t.Fatalf("OpenPeerStore error = %v", err)
	}
	listeners := nodeAgentListenAddresses{management: "0.0.0.0:9443", dataPlane: "127.0.0.1:9444"}
	if _, err := buildRuntimeHTTPServers(context.Background(), config, listeners, store, agent.NodeTLSIdentity{}); err == nil {
		t.Fatal("runtime assembly accepted a wildcard management listener")
	}
}

func productionNodeRuntimeConfig(t *testing.T) agent.TrustedNodeRuntimeConfig {
	t.Helper()
	caPEM := testRuntimeRendererCA(t)
	return agent.TrustedNodeRuntimeConfig{
		SchemaVersion: agent.TrustedNodeRuntimeSchemaV2,
		Environment:   agent.RuntimeEnvironmentProd,
		Node:          agent.NodeRuntimeIdentity{ID: "node-a", Provider: "aws", Region: "us-west-2"},
		NodeTLSIdentity: agent.NodeTLSIdentityRuntimeConfig{
			Source: "aws-kms", KeyARN: "arn:aws:kms:us-west-2:123456789012:key/12345678-1234-1234-1234-123456789012",
			CertificateChainPath: "/etc/xlb/node-chain.pem", ClientCAPEMPath: "/etc/xlb/client-ca.pem",
		},
		AgentListeners: agent.AgentListenerRuntimeConfig{
			ManagementListenAddress: "127.0.0.1:9443", DataPlaneListenAddress: "127.0.0.1:9444",
		},
		ProofBridge: agent.ProofBridgeRuntimeConfig{
			Protocol: "xlb-proof-v1", ListenAddress: "127.0.0.1:17890",
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
	}
}

func testRuntimeNodeTLSIdentity(t *testing.T) agent.NodeTLSIdentity {
	t.Helper()
	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	leafKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	caTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(10), Subject: pkix.Name{CommonName: "runtime assembly test CA"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), IsCA: true,
		BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
	}
	caDER, err := x509.CreateCertificate(rand.Reader, caTemplate, caTemplate, &caKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	caCertificate, err := x509.ParseCertificate(caDER)
	if err != nil {
		t.Fatal(err)
	}
	leafTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(11), Subject: pkix.Name{CommonName: "runtime assembly test node"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour),
		KeyUsage:    x509.KeyUsageDigitalSignature,
		ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth, x509.ExtKeyUsageClientAuth},
	}
	leafDER, err := x509.CreateCertificate(rand.Reader, leafTemplate, caCertificate, &leafKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	leafCertificate, err := x509.ParseCertificate(leafDER)
	if err != nil {
		t.Fatal(err)
	}
	clientCAs := x509.NewCertPool()
	clientCAs.AddCert(caCertificate)
	return agent.NodeTLSIdentity{
		Certificate: tls.Certificate{
			Certificate: [][]byte{leafDER, caDER}, PrivateKey: leafKey, Leaf: leafCertificate,
		},
		ClientCAs: clientCAs,
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
