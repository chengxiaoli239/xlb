package agent

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"strconv"
	"strings"
)

const (
	TrustedNodeRuntimeSchemaV1     = "xlb-node-runtime/v1"
	RuntimeEnvironmentTest         = "isolated-test"
	RuntimeEnvironmentProd         = "production"
	RuntimeCredentialTestFile      = "test-file-only"
	RuntimeCredentialSecure        = "platform-secure-store"
	RuntimeProfileUnavailable      = "unavailable"
	RuntimeProfileMihomoBridgeTest = "mihomo-bridge-isolated-test"
	RuntimeProbeUnavailable        = "unavailable"
	RuntimeProbeHTTPSCanary        = "https-canary"
	maxNodeRuntimeConfigBytes      = 64 * 1024
)

var ErrInvalidNodeRuntimeConfig = errors.New("node runtime configuration is invalid")

type TrustedNodeRuntimeConfig struct {
	SchemaVersion   string                       `json:"schema_version"`
	Environment     string                       `json:"environment"`
	Node            NodeRuntimeIdentity          `json:"node"`
	ProofBridge     ProofBridgeRuntimeConfig     `json:"proof_bridge"`
	ProfileRenderer ProfileRendererRuntimeConfig `json:"profile_renderer"`
	DataPlaneProbe  DataPlaneProbeRuntimeConfig  `json:"data_plane_probe,omitempty"`
}

type NodeRuntimeIdentity struct {
	ID       string `json:"id"`
	Provider string `json:"provider"`
	Region   string `json:"region"`
}

type ProofBridgeRuntimeConfig struct {
	Protocol            string `json:"protocol"`
	ListenAddress       string `json:"listen_address"`
	DeviceProofRequired bool   `json:"device_proof_required"`
	CredentialSource    string `json:"credential_source"`
}

type ProfileRendererRuntimeConfig struct {
	Status            string `json:"status"`
	BridgeEndpoint    string `json:"bridge_endpoint,omitempty"`
	BridgeServerCAPEM string `json:"bridge_server_ca_pem,omitempty"`
}

// DataPlaneProbeRuntimeConfig selects an explicit isolated-test probe target.
// Empty or unavailable status keeps the probe listener fail closed.
type DataPlaneProbeRuntimeConfig struct {
	Status         string `json:"status"`
	CanaryEndpoint string `json:"canary_endpoint,omitempty"`
	ServerCAPEM    string `json:"server_ca_pem,omitempty"`
}

func DecodeTrustedNodeRuntimeConfig(reader io.Reader) (TrustedNodeRuntimeConfig, error) {
	var config TrustedNodeRuntimeConfig
	decoder := json.NewDecoder(io.LimitReader(reader, maxNodeRuntimeConfigBytes+1))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&config); err != nil {
		return TrustedNodeRuntimeConfig{}, fmt.Errorf("decode node runtime config: %w", ErrInvalidNodeRuntimeConfig)
	}
	if err := decoder.Decode(new(any)); !errors.Is(err, io.EOF) {
		return TrustedNodeRuntimeConfig{}, fmt.Errorf("node runtime config has trailing data: %w", ErrInvalidNodeRuntimeConfig)
	}
	if err := config.Validate(); err != nil {
		return TrustedNodeRuntimeConfig{}, err
	}
	return config, nil
}

func (config TrustedNodeRuntimeConfig) Validate() error {
	if config.SchemaVersion != TrustedNodeRuntimeSchemaV1 ||
		(config.Environment != RuntimeEnvironmentTest && config.Environment != RuntimeEnvironmentProd) ||
		!validRuntimeToken(config.Node.ID, 64) || !validRuntimeToken(config.Node.Provider, 32) ||
		!validRuntimeToken(config.Node.Region, 64) || config.ProofBridge.Protocol != "xlb-proof-v1" ||
		!config.ProofBridge.DeviceProofRequired || !isLoopbackListener(config.ProofBridge.ListenAddress) ||
		validateProfileRendererRuntimeConfig(config.Environment, config.ProofBridge, config.ProfileRenderer) != nil ||
		validateDataPlaneProbeRuntimeConfig(config.Environment, config.DataPlaneProbe) != nil {
		return ErrInvalidNodeRuntimeConfig
	}

	wantCredentialSource := RuntimeCredentialTestFile
	if config.Environment == RuntimeEnvironmentProd {
		wantCredentialSource = RuntimeCredentialSecure
	}
	if config.ProofBridge.CredentialSource != wantCredentialSource {
		return ErrInvalidNodeRuntimeConfig
	}

	return nil
}

func validateProfileRendererRuntimeConfig(environment string, bridge ProofBridgeRuntimeConfig, renderer ProfileRendererRuntimeConfig) error {
	switch renderer.Status {
	case RuntimeProfileUnavailable:
		if renderer.BridgeEndpoint != "" || renderer.BridgeServerCAPEM != "" {
			return ErrInvalidNodeRuntimeConfig
		}
	case RuntimeProfileMihomoBridgeTest:
		if environment != RuntimeEnvironmentTest {
			return ErrInvalidNodeRuntimeConfig
		}
		if _, err := NewMihomoBridgeProfileRenderer(bridge.ListenAddress, renderer.BridgeEndpoint, renderer.BridgeServerCAPEM); err != nil {
			return ErrInvalidNodeRuntimeConfig
		}
	default:
		return ErrInvalidNodeRuntimeConfig
	}
	return nil
}

func validRuntimeToken(value string, maxLength int) bool {
	if value == "" || len(value) > maxLength || strings.TrimSpace(value) != value {
		return false
	}
	for _, character := range value {
		if !((character >= 'a' && character <= 'z') || (character >= 'A' && character <= 'Z') ||
			(character >= '0' && character <= '9') || character == '-' || character == '_' || character == '.') {
			return false
		}
	}
	return true
}

func isLoopbackListener(address string) bool {
	host, portValue, err := net.SplitHostPort(address)
	if err != nil {
		return false
	}
	port, err := strconv.Atoi(portValue)
	if err != nil || port < 1 || port > 65535 {
		return false
	}
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}
