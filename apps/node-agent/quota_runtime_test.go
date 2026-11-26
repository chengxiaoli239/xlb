package agent

import (
	"context"
	"errors"
	"strings"
	"testing"
)

func TestNewRuntimeDataPlaneEnablesQuotaOnlyForTrustedProductionConfig(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	configJSON := productionRendererRuntimeConfigJSON(RuntimeProfileMihomoBridge,
		"https://bridge.example.invalid:9444", string(caPEM))
	config, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(configJSON))
	if err != nil {
		t.Fatal(err)
	}
	store, err := OpenPeerStore(t.TempDir()+"/production-agent.json", config.Node.ID, 2)
	if err != nil {
		t.Fatal(err)
	}
	identity := NodeTLSIdentity{Certificate: testQuotaClientCertificate(t)}

	dataPlane, err := NewRuntimeDataPlane(context.Background(), store, NewPublicTargetDialer(), identity, config)
	if err != nil {
		t.Fatalf("NewRuntimeDataPlane error = %v", err)
	}
	if dataPlane.quotaProvider == nil {
		t.Fatal("production-shaped runtime returned a data plane without quota enforcement")
	}
}

func TestNewRuntimeDataPlaneFailsClosedForInvalidQuotaClientIdentity(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	configJSON := productionRendererRuntimeConfigJSON(RuntimeProfileMihomoBridge,
		"https://bridge.example.invalid:9444", string(caPEM))
	config, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(configJSON))
	if err != nil {
		t.Fatal(err)
	}
	store, err := OpenPeerStore(t.TempDir()+"/production-agent.json", config.Node.ID, 2)
	if err != nil {
		t.Fatal(err)
	}
	serverCertificate, _, _ := createTestCertificates(t)

	if _, err := NewRuntimeDataPlane(context.Background(), store, NewPublicTargetDialer(),
		NodeTLSIdentity{Certificate: serverCertificate}, config); !errors.Is(err, ErrInvalidNodeRuntimeConfig) {
		t.Fatalf("NewRuntimeDataPlane error = %v; want invalid client-auth identity", err)
	}
}

func TestNewRuntimeDataPlaneKeepsIsolatedTestWithoutOnlineQuota(t *testing.T) {
	config, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(
		`{"schema_version":"xlb-node-runtime/v1","environment":"isolated-test","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"test-file-only"},"profile_renderer":{"status":"unavailable"}}`,
	))
	if err != nil {
		t.Fatal(err)
	}
	store, err := OpenPeerStore(t.TempDir()+"/test-agent.json", config.Node.ID, 2)
	if err != nil {
		t.Fatal(err)
	}
	dataPlane, err := NewRuntimeDataPlane(context.Background(), store, NewPublicTargetDialer(), NodeTLSIdentity{}, config)
	if err != nil {
		t.Fatalf("NewRuntimeDataPlane error = %v", err)
	}
	if dataPlane.quotaProvider != nil {
		t.Fatal("isolated-test runtime unexpectedly enabled online quota authority")
	}
}
