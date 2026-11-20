package agent

import (
	"context"
	"errors"
	"os"
	"reflect"
	"strings"
	"testing"

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
	artifact := testMihomoArtifact(t, request)
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
	artifact := testMihomoArtifact(t, request)
	artifact.RendererVersion = "1.2.3"
	if err := ValidateProfileArtifact(request, artifact); err != nil {
		t.Fatalf("ValidateProfileArtifact rejected a valid renderer contract: %v", err)
	}
}

func testMihomoArtifact(t *testing.T, request ProfileRenderRequest) ProfileArtifact {
	t.Helper()
	renderer, err := NewMihomoBridgeProfileRenderer("127.0.0.1:17890")
	if err != nil {
		t.Fatal(err)
	}
	artifact, err := renderer.Render(context.Background(), request)
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
	renderer, err := NewMihomoBridgeProfileRenderer("127.0.0.1:17890")
	if err != nil {
		t.Fatal(err)
	}
	artifact, err := renderer.Render(context.Background(), request)
	if err != nil {
		t.Fatal(err)
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
	if _, err := NewMihomoBridgeProfileRenderer("0.0.0.0:17890"); !errors.Is(err, ErrInvalid) {
		t.Fatalf("public bridge listener error = %v; want ErrInvalid", err)
	}
	renderer, err := NewMihomoBridgeProfileRenderer("127.0.0.1:17890")
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
		BridgeProtocol: "xlb-proof-v1",
	}
	if artifact, err := renderer.Render(canceled, request); !errors.Is(err, context.Canceled) || artifact.Content != "" {
		t.Fatalf("canceled request result = (%+v, %v); want empty artifact and canceled", artifact, err)
	}
}
