package agent

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"testing"
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
		DeviceID: "device-1", DeviceKeyFingerprint: "fingerprint-1", DeviceBindingGeneration: 3,
		BridgeProtocol: "xlb-proof-v1",
	}
	content := "contract-test-sentinel"
	digest := sha256.Sum256([]byte(content))
	artifact := ProfileArtifact{
		NodeID: "node-1", PeerID: "peer-1", EntitlementID: "entitlement-1",
		RequestID: "request-1", Generation: 2,
		DeviceID: "device-1", DeviceKeyFingerprint: "fingerprint-1", DeviceBindingGeneration: 3,
		Format: "mihomo-yaml", RendererID: "xlb-mihomo-bridge-v1", RendererVersion: "1.0.0",
		SHA256: hex.EncodeToString(digest[:]), BridgeProtocol: "xlb-proof-v1", Connectable: true, Content: content,
	}
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
	secretContent := "private_key: forbidden"
	secretDigest := sha256.Sum256([]byte(secretContent))
	secretArtifact := artifact
	secretArtifact.Content = secretContent
	secretArtifact.SHA256 = hex.EncodeToString(secretDigest[:])
	invalid = append(invalid, secretArtifact)

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
		DeviceID: "device-1", DeviceKeyFingerprint: "fingerprint-1", DeviceBindingGeneration: 3,
		BridgeProtocol: "xlb-proof-v1",
	}
	content := "profile-renderer-contract-test-payload"
	digest := sha256.Sum256([]byte(content))
	artifact := ProfileArtifact{
		NodeID: request.NodeID, PeerID: request.PeerID, EntitlementID: request.EntitlementID,
		RequestID:  request.RequestID,
		Generation: request.Generation, DeviceID: request.DeviceID,
		DeviceKeyFingerprint:    request.DeviceKeyFingerprint,
		DeviceBindingGeneration: request.DeviceBindingGeneration,
		Format:                  "mihomo-yaml", RendererID: "xlb-mihomo-bridge-v1", RendererVersion: "1.2.3",
		SHA256: hex.EncodeToString(digest[:]), BridgeProtocol: request.BridgeProtocol,
		Connectable: true, Content: content,
	}
	if err := ValidateProfileArtifact(request, artifact); err != nil {
		t.Fatalf("ValidateProfileArtifact rejected a valid renderer contract: %v", err)
	}
}
