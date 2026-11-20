package agent

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"regexp"
	"strings"
)

var ErrProductionProfileRendererUnavailable = errors.New("production profile renderer is unavailable")

var rendererVersionPattern = regexp.MustCompile(`^[0-9]+\.[0-9]+\.[0-9]+$`)

type ProfileRenderRequest struct {
	NodeID                  string `json:"node_id"`
	PeerID                  string `json:"peer_id"`
	EntitlementID           string `json:"entitlement_id"`
	RequestID               string `json:"request_id"`
	Generation              int    `json:"generation"`
	DeviceID                string `json:"device_id"`
	DeviceKeyFingerprint    string `json:"device_key_fingerprint"`
	DeviceBindingGeneration int    `json:"device_binding_generation"`
	BridgeProtocol          string `json:"bridge_protocol"`
}

type ProfileArtifact struct {
	NodeID                  string `json:"node_id"`
	PeerID                  string `json:"peer_id"`
	EntitlementID           string `json:"entitlement_id"`
	RequestID               string `json:"request_id"`
	Generation              int    `json:"generation"`
	DeviceID                string `json:"device_id"`
	DeviceKeyFingerprint    string `json:"device_key_fingerprint"`
	DeviceBindingGeneration int    `json:"device_binding_generation"`
	Format                  string `json:"format"`
	RendererID              string `json:"renderer_id"`
	RendererVersion         string `json:"renderer_version"`
	SHA256                  string `json:"sha256"`
	BridgeProtocol          string `json:"bridge_protocol"`
	Connectable             bool   `json:"connectable"`
	Content                 string `json:"content"`
}

type ProfileRenderer interface {
	Render(context.Context, ProfileRenderRequest) (ProfileArtifact, error)
}

type UnavailableProfileRenderer struct{}

func (UnavailableProfileRenderer) Render(context.Context, ProfileRenderRequest) (ProfileArtifact, error) {
	return ProfileArtifact{}, ErrProductionProfileRendererUnavailable
}

func ValidateProfileArtifact(request ProfileRenderRequest, artifact ProfileArtifact) error {
	if artifact.NodeID != request.NodeID || artifact.PeerID != request.PeerID ||
		artifact.EntitlementID != request.EntitlementID || artifact.RequestID != request.RequestID ||
		artifact.Generation != request.Generation || artifact.DeviceID != request.DeviceID ||
		artifact.DeviceKeyFingerprint != request.DeviceKeyFingerprint ||
		artifact.DeviceBindingGeneration != request.DeviceBindingGeneration || artifact.BridgeProtocol != "xlb-proof-v1" ||
		artifact.BridgeProtocol != request.BridgeProtocol || !artifact.Connectable {
		return fmt.Errorf("profile artifact identity or bridge binding is invalid: %w", ErrInvalid)
	}

	expectedRenderer := map[string]string{
		"mihomo-yaml":            "xlb-mihomo-bridge-v1",
		"xlb-client-manifest-v1": "xlb-native-bridge-v1",
	}[artifact.Format]
	if expectedRenderer == "" || artifact.RendererID != expectedRenderer || !rendererVersionPattern.MatchString(artifact.RendererVersion) ||
		len(artifact.Content) == 0 || len(artifact.Content) > 128*1024 || len(artifact.SHA256) != 64 {
		return fmt.Errorf("profile artifact metadata is invalid: %w", ErrInvalid)
	}
	if artifact.Format == "mihomo-yaml" {
		if err := validateMihomoBridgeProfile(artifact.Content); err != nil {
			return err
		}
	}

	digest := sha256.Sum256([]byte(artifact.Content))
	if hex.EncodeToString(digest[:]) != artifact.SHA256 {
		return fmt.Errorf("profile artifact digest does not match content: %w", ErrInvalid)
	}
	normalized := strings.ToLower(artifact.Content)
	for _, secretField := range []string{"private_key", "peer_credential", "peer_secret", "subscription_token"} {
		if strings.Contains(normalized, secretField) {
			return fmt.Errorf("profile artifact contains device secret material: %w", ErrInvalid)
		}
	}

	return nil
}
