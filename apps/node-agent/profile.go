package agent

import (
	"context"
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var ErrProductionProfileRendererUnavailable = errors.New("production profile renderer is unavailable")

var rendererVersionPattern = regexp.MustCompile(`^[0-9]+\.[0-9]+\.[0-9]+$`)

func validateMemberBridgeDescriptor(endpoint, caBundle string) error {
	parsed, err := url.Parse(endpoint)
	if err != nil || parsed.Scheme != "https" || parsed.Hostname() == "" || parsed.User != nil ||
		parsed.RawQuery != "" || parsed.Fragment != "" || len(endpoint) > 2048 ||
		len(caBundle) == 0 || len(caBundle) > 64*1024 {
		return fmt.Errorf("member bridge endpoint or server trust bundle is invalid: %w", ErrInvalid)
	}
	if parsed.Port() != "" {
		port, conversionError := strconv.Atoi(parsed.Port())
		if conversionError != nil || port < 1 || port > 65535 {
			return fmt.Errorf("member bridge endpoint port is invalid: %w", ErrInvalid)
		}
	}

	remaining := []byte(caBundle)
	certificateCount := 0
	for len(strings.TrimSpace(string(remaining))) > 0 {
		block, rest := pem.Decode(remaining)
		if block == nil || block.Type != "CERTIFICATE" || len(block.Headers) != 0 {
			return fmt.Errorf("member bridge trust bundle is not certificate-only PEM: %w", ErrInvalid)
		}
		certificate, parseError := x509.ParseCertificate(block.Bytes)
		if parseError != nil || !certificate.IsCA || certificate.KeyUsage&x509.KeyUsageCertSign == 0 {
			return fmt.Errorf("member bridge trust bundle must contain CA certificates: %w", ErrInvalid)
		}
		now := time.Now()
		if now.Before(certificate.NotBefore) || now.After(certificate.NotAfter) {
			return fmt.Errorf("member bridge CA is outside its validity period: %w", ErrInvalid)
		}
		certificateCount++
		if certificateCount > 8 {
			return fmt.Errorf("member bridge trust bundle contains too many certificates: %w", ErrInvalid)
		}
		remaining = rest
	}
	if certificateCount == 0 {
		return fmt.Errorf("member bridge trust bundle is empty: %w", ErrInvalid)
	}
	return nil
}

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
	BridgeEndpoint          string `json:"bridge_endpoint"`
	BridgeServerCAPEM       string `json:"bridge_server_ca_pem"`
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
	BridgeEndpoint          string `json:"bridge_endpoint"`
	BridgeServerCAPEM       string `json:"bridge_server_ca_pem"`
	Connectable             bool   `json:"connectable"`
	Content                 string `json:"content"`
}

type ProfileRenderer interface {
	Render(context.Context, ProfileRenderRequest) (ProfileArtifact, error)
}

type MemberBridgeDescriptorProvider interface {
	MemberBridgeDescriptor() (endpoint, serverCAPEM string)
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
	if err := validateMemberBridgeDescriptor(request.BridgeEndpoint, request.BridgeServerCAPEM); err != nil {
		return err
	}
	if artifact.BridgeEndpoint != request.BridgeEndpoint || artifact.BridgeServerCAPEM != request.BridgeServerCAPEM {
		return fmt.Errorf("profile artifact bridge trust descriptor does not match trusted node runtime: %w", ErrInvalid)
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
