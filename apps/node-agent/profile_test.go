package agent

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"math/big"
	"os"
	"reflect"
	"strings"
	"testing"
	"time"

	"go.yaml.in/yaml/v3"
)

func TestUnavailableProfileRendererFailsClosed(t *testing.T) {
	_, err := (UnavailableProfileRenderer{}).Render(context.Background(), ProfileRenderRequest{})
	if !errors.Is(err, ErrProductionProfileRendererUnavailable) {
		t.Fatalf("Render error = %v; want unavailable renderer", err)
	}
}

func TestProfileArtifactRejectsUnboundOrSecretBearingMetadata(t *testing.T) {
	request := ProfileRenderRequest{
		NodeID: "node-1", PeerID: "peer-1", EntitlementID: "entitlement-1",
		RequestID: "request-1", Generation: 2,
		DeviceID: "device-1", DeviceKeyFingerprint: strings.Repeat("a", 64), DeviceBindingGeneration: 3,
		BridgeProtocol: "xlb-proof-v1",
	}
	artifact := testMihomoArtifact(t, &request)
	invalid := []ProfileArtifact{
		{NodeID: "node-other", PeerID: artifact.PeerID, RequestID: artifact.RequestID, Generation: artifact.Generation,
			DeviceKeyFingerprint: artifact.DeviceKeyFingerprint, DeviceBindingGeneration: artifact.DeviceBindingGeneration,
			Format: artifact.Format, RendererID: artifact.RendererID, RendererVersion: artifact.RendererVersion,
			SHA256: artifact.SHA256, BridgeProtocol: artifact.BridgeProtocol, Connectable: artifact.Connectable, Content: artifact.Content},
		{NodeID: artifact.NodeID, PeerID: artifact.PeerID, RequestID: artifact.RequestID, Generation: artifact.Generation,
			DeviceKeyFingerprint: artifact.DeviceKeyFingerprint, DeviceBindingGeneration: artifact.DeviceBindingGeneration,
			Format: artifact.Format, RendererID: artifact.RendererID, RendererVersion: artifact.RendererVersion,
			SHA256: "invalid-digest", BridgeProtocol: artifact.BridgeProtocol, Connectable: artifact.Connectable, Content: artifact.Content},
	}
	secretArtifact := artifact
	secretArtifact.Content = strings.Replace(artifact.Content, "type: socks5", "private_key: forbidden\n  type: socks5", 1)
	secretArtifact.SHA256 = profileContentSHA256([]byte(secretArtifact.Content))
	directArtifact := artifact
	directArtifact.Content = strings.Replace(artifact.Content, "type: socks5", "type: direct", 1)
	directArtifact.SHA256 = profileContentSHA256([]byte(directArtifact.Content))
	invalid = append(invalid, secretArtifact, directArtifact)

	for _, candidate := range invalid {
		if err := ValidateProfileArtifact(request, candidate); !errors.Is(err, ErrInvalid) {
			t.Errorf("ValidateProfileArtifact(%+v) error = %v; want ErrInvalid", candidate, err)
		}
	}
}

func TestProfileArtifactAcceptsTheBoundRendererContract(t *testing.T) {
	request := ProfileRenderRequest{
		NodeID: "node-1", PeerID: "peer-1", EntitlementID: "entitlement-1",
		RequestID: "request-1", Generation: 2,
		DeviceID: "device-1", DeviceKeyFingerprint: strings.Repeat("a", 64), DeviceBindingGeneration: 3,
		BridgeProtocol: "xlb-proof-v1",
	}
	artifact := testMihomoArtifact(t, &request)
	artifact.RendererVersion = "1.2.3"
	if err := ValidateProfileArtifact(request, artifact); err != nil {
		t.Fatalf("ValidateProfileArtifact rejected a valid renderer contract: %v", err)
	}
}

func TestMihomoRendererRequiresAnExplicitHTTPSOriginAndTrustedCACertificate(t *testing.T) {
	ca := testBridgeCA(t)
	invalidEndpoints := []string{
		"http://node.example.invalid:9444",
		"https://user@node.example.invalid:9444",
		"https://node.example.invalid:0",
		"https://node.example.invalid:9444?redirect=1",
		"https://node.example.invalid:9444#fragment",
	}
	for _, endpoint := range invalidEndpoints {
		if _, err := NewMihomoBridgeProfileRenderer("127.0.0.1:17890", endpoint, ca); !errors.Is(err, ErrInvalid) {
			t.Errorf("renderer accepted endpoint %q, error = %v", endpoint, err)
		}
	}
	if _, err := NewMihomoBridgeProfileRenderer("127.0.0.1:17890", "https://node.example.invalid:9444", ""); !errors.Is(err, ErrInvalid) {
		t.Fatalf("renderer accepted an empty CA bundle, error = %v", err)
	}
	if _, err := NewMihomoBridgeProfileRenderer("127.0.0.1:17890", "https://node.example.invalid:9444",
		"-----BEGIN CERTIFICATE-----\nAA==\n-----END CERTIFICATE-----\n"); !errors.Is(err, ErrInvalid) {
		t.Fatalf("renderer accepted malformed CA PEM, error = %v", err)
	}
}

func TestProfileArtifactRejectsChangedNodeBridgeTrustDescriptor(t *testing.T) {
	request := ProfileRenderRequest{
		NodeID: "node-1", PeerID: "peer-1", EntitlementID: "entitlement-1",
		RequestID: "request-1", Generation: 1, DeviceID: "device-1",
		DeviceKeyFingerprint: strings.Repeat("a", 64), DeviceBindingGeneration: 1,
		BridgeProtocol: "xlb-proof-v1",
	}
	artifact := testMihomoArtifact(t, &request)
	artifact.BridgeEndpoint = "http://attacker.invalid:9444"
	if err := ValidateProfileArtifact(request, artifact); !errors.Is(err, ErrInvalid) {
		t.Fatalf("artifact endpoint tampering error = %v; want ErrInvalid", err)
	}
	artifact = testMihomoArtifact(t, &request)
	artifact.BridgeServerCAPEM = "-----BEGIN CERTIFICATE-----\nAA==\n-----END CERTIFICATE-----\n"
	if err := ValidateProfileArtifact(request, artifact); !errors.Is(err, ErrInvalid) {
		t.Fatalf("artifact CA tampering error = %v; want ErrInvalid", err)
	}
}

func testMihomoArtifact(t *testing.T, request *ProfileRenderRequest) ProfileArtifact {
	t.Helper()
	if request.BridgeEndpoint == "" {
		request.BridgeEndpoint = "https://node.example.invalid:9444"
		request.BridgeServerCAPEM = testBridgeCA(t)
	}
	renderer, err := NewMihomoBridgeProfileRenderer("127.0.0.1:17890", request.BridgeEndpoint, request.BridgeServerCAPEM)
	if err != nil {
		t.Fatal(err)
	}
	artifact, err := renderer.Render(context.Background(), *request)
	if err != nil {
		t.Fatal(err)
	}
	return artifact
}

func TestMihomoBridgeProfileRendererProducesFailClosedLocalProxyConfig(t *testing.T) {
	request := ProfileRenderRequest{
		NodeID: "node-1", PeerID: "peer-1", EntitlementID: "entitlement-1",
		RequestID: "request-1", Generation: 2,
		DeviceID: "device-1", DeviceKeyFingerprint: strings.Repeat("a", 64),
		DeviceBindingGeneration: 3, BridgeProtocol: "xlb-proof-v1",
	}
	renderer, err := testMihomoRenderer(t)
	if err != nil {
		t.Fatal(err)
	}
	request.BridgeEndpoint, request.BridgeServerCAPEM = renderer.MemberBridgeDescriptor()
	artifact, err := renderer.Render(context.Background(), request)
	if err != nil {
		t.Fatal(err)
	}
	if artifact.BridgeEndpoint != "https://node.example.invalid:9444" || artifact.BridgeServerCAPEM == "" {
		t.Fatalf("artifact omitted node bridge trust descriptor: %+v", artifact)
	}
	if err := ValidateProfileArtifact(request, artifact); err != nil {
		t.Fatalf("ValidateProfileArtifact rejected renderer output: %v", err)
	}
	if artifact.Format != "mihomo-yaml" || !artifact.Connectable {
		t.Fatalf("artifact format/connectable = %q/%v", artifact.Format, artifact.Connectable)
	}

	var config map[string]any
	if err := yaml.Unmarshal([]byte(artifact.Content), &config); err != nil {
		t.Fatalf("rendered content is not valid YAML: %v", err)
	}
	fixtureContent, err := os.ReadFile("testdata/mihomo-local-bridge-profile.yaml")
	if err != nil {
		t.Fatal(err)
	}
	var fixture map[string]any
	if err := yaml.Unmarshal(fixtureContent, &fixture); err != nil {
		t.Fatalf("shared Mihomo fixture is not valid YAML: %v", err)
	}
	if !reflect.DeepEqual(config, fixture) {
		t.Fatalf("renderer output differs from shared Mihomo fixture:\n%s", artifact.Content)
	}
	proxies, ok := config["proxies"].([]any)
	if !ok || len(proxies) != 1 {
		t.Fatalf("proxies = %#v; want one local bridge proxy", config["proxies"])
	}
	proxy, ok := proxies[0].(map[string]any)
	if !ok || proxy["type"] != "socks5" || proxy["server"] != "127.0.0.1" || proxy["port"] != 17890 || proxy["udp"] != false {
		t.Fatalf("proxy = %#v; want loopback SOCKS5 without UDP", proxies[0])
	}
	if strings.Contains(strings.ToLower(artifact.Content), "direct") ||
		strings.Contains(artifact.Content, request.NodeID) || strings.Contains(artifact.Content, request.PeerID) {
		t.Fatalf("profile contains a direct fallback or server-side identity: %s", artifact.Content)
	}
	if err := ValidateProfileArtifact(request, artifact); err != nil {
		t.Fatalf("renderer artifact contract is invalid: %v", err)
	}
}

func TestMihomoBridgeProfileRendererRejectsInvalidRequestAndListener(t *testing.T) {
	if _, err := NewMihomoBridgeProfileRenderer("0.0.0.0:17890", "https://node.example.invalid:9444", testBridgeCA(t)); !errors.Is(err, ErrInvalid) {
		t.Fatalf("public bridge listener error = %v; want ErrInvalid", err)
	}
	renderer, err := testMihomoRenderer(t)
	if err != nil {
		t.Fatal(err)
	}
	request := ProfileRenderRequest{BridgeProtocol: "unsupported"}
	if artifact, err := renderer.Render(context.Background(), request); !errors.Is(err, ErrInvalid) || artifact.Content != "" {
		t.Fatalf("invalid request result = (%+v, %v); want empty artifact and ErrInvalid", artifact, err)
	}
	canceled, cancel := context.WithCancel(context.Background())
	cancel()
	request = ProfileRenderRequest{
		NodeID: "node-1", PeerID: "peer-1", EntitlementID: "entitlement-1",
		RequestID: "request-1", Generation: 1, DeviceID: "device-1",
		DeviceKeyFingerprint: strings.Repeat("a", 64), DeviceBindingGeneration: 1,
		BridgeProtocol: "xlb-proof-v1", BridgeEndpoint: renderer.bridgeEndpoint,
		BridgeServerCAPEM: renderer.bridgeServerCAPEM,
	}
	if artifact, err := renderer.Render(canceled, request); !errors.Is(err, context.Canceled) || artifact.Content != "" {
		t.Fatalf("canceled request result = (%+v, %v); want empty artifact and canceled", artifact, err)
	}
}

func testMihomoRenderer(t *testing.T) (*MihomoBridgeProfileRenderer, error) {
	t.Helper()
	return NewMihomoBridgeProfileRenderer("127.0.0.1:17890", "https://node.example.invalid:9444", testBridgeCA(t))
}

func testBridgeCA(t *testing.T) string {
	t.Helper()
	privateKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now().UTC()
	template := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "test node data plane CA"},
		NotBefore: now.Add(-time.Minute), NotAfter: now.Add(time.Hour), IsCA: true,
		BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
	}
	der, err := x509.CreateCertificate(rand.Reader, template, template, &privateKey.PublicKey, privateKey)
	if err != nil {
		t.Fatal(err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
}
