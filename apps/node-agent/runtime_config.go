package agent

import (
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/url"
	"strconv"
	"strings"
	"time"
)

const (
	TrustedNodeRuntimeSchemaV1     = "xlb-node-runtime/v1"
	TrustedNodeRuntimeSchemaV2     = "xlb-node-runtime/v2"
	RuntimeEnvironmentTest         = "isolated-test"
	RuntimeEnvironmentProd         = "production"
	RuntimeCredentialTestFile      = "test-file-only"
	RuntimeCredentialSecure        = "platform-secure-store"
	RuntimeProfileUnavailable      = "unavailable"
	RuntimeProfileMihomoBridgeTest = "mihomo-bridge-isolated-test"
	RuntimeProfileMihomoBridge     = "mihomo-bridge-v1"
	RuntimeProbeUnavailable        = "unavailable"
	RuntimeProbeHTTPSCanary        = "https-canary"
	maxNodeRuntimeConfigBytes      = 64 * 1024
)

var ErrInvalidNodeRuntimeConfig = errors.New("node runtime configuration is invalid")

type TrustedNodeRuntimeConfig struct {
	SchemaVersion   string                       `json:"schema_version"`
	Environment     string                       `json:"environment"`
	Node            NodeRuntimeIdentity          `json:"node"`
	NodeTLSIdentity NodeTLSIdentityRuntimeConfig `json:"node_tls_identity,omitempty"`
	ProofBridge     ProofBridgeRuntimeConfig     `json:"proof_bridge"`
	ProfileRenderer ProfileRendererRuntimeConfig `json:"profile_renderer"`
	DataPlaneProbe  DataPlaneProbeRuntimeConfig  `json:"data_plane_probe,omitempty"`
	QuotaAuthority  QuotaAuthorityRuntimeConfig  `json:"quota_authority,omitempty"`
}

type NodeTLSIdentityRuntimeConfig struct {
	Source string `json:"source,omitempty"`
	KeyARN string `json:"key_arn,omitempty"`
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

type QuotaAuthorityRuntimeConfig struct {
	Endpoint       string `json:"endpoint,omitempty"`
	ServerCAPEM    string `json:"server_ca_pem,omitempty"`
	GrantBytes     int64  `json:"grant_bytes,omitempty"`
	OutboxLimit    int    `json:"outbox_limit,omitempty"`
	TimeoutSeconds int    `json:"timeout_seconds,omitempty"`
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
	validSchemaEnvironment := (config.SchemaVersion == TrustedNodeRuntimeSchemaV1 &&
		config.Environment == RuntimeEnvironmentTest) ||
		(config.SchemaVersion == TrustedNodeRuntimeSchemaV2 && config.Environment == RuntimeEnvironmentProd)
	if !validSchemaEnvironment ||
		!validRuntimeToken(config.Node.ID, 64) || !validRuntimeToken(config.Node.Provider, 32) ||
		!validRuntimeToken(config.Node.Region, 64) || config.ProofBridge.Protocol != "xlb-proof-v1" ||
		!config.ProofBridge.DeviceProofRequired || !isLoopbackListener(config.ProofBridge.ListenAddress) ||
		validateProfileRendererRuntimeConfig(config.SchemaVersion, config.Environment, config.ProofBridge, config.ProfileRenderer) != nil ||
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
	if config.SchemaVersion == TrustedNodeRuntimeSchemaV2 {
		if config.NodeTLSIdentity.Source != "aws-kms" || !ValidKMSKeyARN(config.NodeTLSIdentity.KeyARN) ||
			kmsKeyARNRegion(config.NodeTLSIdentity.KeyARN) != config.Node.Region ||
			validateQuotaAuthorityRuntimeConfig(config.QuotaAuthority) != nil {
			return ErrInvalidNodeRuntimeConfig
		}
	} else if config.NodeTLSIdentity.Source != "" || config.NodeTLSIdentity.KeyARN != "" ||
		config.QuotaAuthority != (QuotaAuthorityRuntimeConfig{}) {
		return ErrInvalidNodeRuntimeConfig
	}

	return nil
}

func validateQuotaAuthorityRuntimeConfig(config QuotaAuthorityRuntimeConfig) error {
	endpoint, err := url.Parse(config.Endpoint)
	if err != nil || config.Endpoint == "" || strings.TrimSpace(config.Endpoint) != config.Endpoint ||
		endpoint.Scheme != "https" || endpoint.Host == "" || endpoint.Hostname() == "" || endpoint.User != nil || endpoint.RawQuery != "" ||
		endpoint.ForceQuery || endpoint.Fragment != "" || endpoint.Opaque != "" ||
		(config.GrantBytes < 1) || config.OutboxLimit < 1 || config.OutboxLimit > 1000 ||
		config.TimeoutSeconds < 1 || config.TimeoutSeconds > 60 {
		return ErrInvalidNodeRuntimeConfig
	}
	if endpoint.Port() != "" {
		port, err := strconv.Atoi(endpoint.Port())
		if err != nil || port < 1 || port > 65535 {
			return ErrInvalidNodeRuntimeConfig
		}
	}

	_, certificates, err := parseNodeTLSCertificatePEM([]byte(config.ServerCAPEM))
	if err != nil || len(certificates) == 0 {
		return ErrInvalidNodeRuntimeConfig
	}
	now := time.Now()
	for _, certificate := range certificates {
		if !certificate.IsCA || certificate.KeyUsage&x509.KeyUsageCertSign == 0 ||
			now.Before(certificate.NotBefore) || !now.Before(certificate.NotAfter) {
			return ErrInvalidNodeRuntimeConfig
		}
	}
	return nil
}

func ValidKMSKeyARN(value string) bool {
	if len(value) < 70 || len(value) > 2048 || strings.TrimSpace(value) != value ||
		strings.ContainsAny(value, " \t\r\n") {
		return false
	}
	parts := strings.SplitN(value, ":", 6)
	if len(parts) != 6 || parts[0] != "arn" ||
		!validKMSPartitionRegion(parts[1], parts[3]) || parts[2] != "kms" ||
		!validRuntimeToken(parts[3], 32) || len(parts[4]) != 12 ||
		parts[5] == "" || !strings.HasPrefix(parts[5], "key/") {
		return false
	}
	for _, digit := range parts[4] {
		if digit < '0' || digit > '9' {
			return false
		}
	}
	keyID := strings.TrimPrefix(parts[5], "key/")
	keyID = strings.TrimPrefix(keyID, "mrk-")
	if len(keyID) != 36 {
		return false
	}
	for index, character := range keyID {
		if index == 8 || index == 13 || index == 18 || index == 23 {
			if character != '-' {
				return false
			}
			continue
		}
		if !((character >= '0' && character <= '9') || (character >= 'a' && character <= 'f') ||
			(character >= 'A' && character <= 'F')) {
			return false
		}
	}
	return true
}

func validKMSPartitionRegion(partition, region string) bool {
	if !validRuntimeToken(region, 32) {
		return false
	}
	switch partition {
	case "aws":
		return !strings.HasPrefix(region, "cn-") && !strings.HasPrefix(region, "us-gov-")
	case "aws-us-gov":
		return strings.HasPrefix(region, "us-gov-")
	case "aws-cn":
		return strings.HasPrefix(region, "cn-")
	default:
		return false
	}
}

func kmsKeyARNRegion(value string) string {
	parts := strings.SplitN(value, ":", 6)
	if len(parts) != 6 {
		return ""
	}
	return parts[3]
}

func validateProfileRendererRuntimeConfig(schemaVersion, environment string, bridge ProofBridgeRuntimeConfig,
	renderer ProfileRendererRuntimeConfig) error {
	switch renderer.Status {
	case RuntimeProfileUnavailable:
		if renderer.BridgeEndpoint != "" || renderer.BridgeServerCAPEM != "" {
			return ErrInvalidNodeRuntimeConfig
		}
	case RuntimeProfileMihomoBridgeTest:
		if schemaVersion != TrustedNodeRuntimeSchemaV1 || environment != RuntimeEnvironmentTest {
			return ErrInvalidNodeRuntimeConfig
		}
		if _, err := NewMihomoBridgeProfileRenderer(bridge.ListenAddress, renderer.BridgeEndpoint, renderer.BridgeServerCAPEM); err != nil {
			return ErrInvalidNodeRuntimeConfig
		}
	case RuntimeProfileMihomoBridge:
		if schemaVersion != TrustedNodeRuntimeSchemaV2 || environment != RuntimeEnvironmentProd {
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
