package agent

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"
)

func TestDecodeTrustedNodeRuntimeConfigAcceptsTestAndSecureProductionInputs(t *testing.T) {
	for _, test := range []struct {
		name   string
		config string
	}{
		{
			name:   "isolated test",
			config: `{"schema_version":"xlb-node-runtime/v1","environment":"isolated-test","node":{"id":"xlb-lab-usw2","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"test-file-only"},"profile_renderer":{"status":"unavailable"}}`,
		},
		{
			name:   "production secure-key declaration",
			config: `{"schema_version":"xlb-node-runtime/v1","environment":"production","node":{"id":"node-a","provider":"provider-b","region":"region-c"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"[::1]:10808","device_proof_required":true,"credential_source":"platform-secure-store"},"profile_renderer":{"status":"unavailable"}}`,
		},
	} {
		t.Run(test.name, func(t *testing.T) {
			config, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(test.config))
			if err != nil {
				t.Fatalf("DecodeTrustedNodeRuntimeConfig error = %v", err)
			}
			if config.ProfileRenderer.Status != RuntimeProfileUnavailable {
				t.Fatalf("profile renderer status = %q; want unavailable", config.ProfileRenderer.Status)
			}
		})
	}
}

func TestDecodeTrustedNodeRuntimeConfigRejectsUnsafeOrUnsupportedInputs(t *testing.T) {
	valid := `{"schema_version":"xlb-node-runtime/v1","environment":"isolated-test","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"test-file-only"},"profile_renderer":{"status":"unavailable"}}`
	tests := []struct {
		name  string
		input string
	}{
		{name: "unknown field", input: strings.TrimSuffix(valid, "}") + `,"subscription_token":"secret"}`},
		{name: "trailing json", input: valid + `{}`},
		{name: "unsupported schema", input: strings.Replace(valid, TrustedNodeRuntimeSchemaV1, "xlb-node-runtime/v2", 1)},
		{name: "public bridge listener", input: strings.Replace(valid, "127.0.0.1:10808", "0.0.0.0:10808", 1)},
		{name: "hostname listener", input: strings.Replace(valid, "127.0.0.1:10808", "localhost:10808", 1)},
		{name: "device proof disabled", input: strings.Replace(valid, `"device_proof_required":true`, `"device_proof_required":false`, 1)},
		{name: "test config with secure store declaration", input: strings.Replace(valid, RuntimeCredentialTestFile, RuntimeCredentialSecure, 1)},
		{name: "renderer declared ready before implementation", input: strings.Replace(valid, RuntimeProfileUnavailable, "ready", 1)},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if _, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(test.input)); !errors.Is(err, ErrInvalidNodeRuntimeConfig) {
				t.Fatalf("DecodeTrustedNodeRuntimeConfig error = %v; want ErrInvalidNodeRuntimeConfig", err)
			}
		})
	}
}

func TestDecodeTrustedNodeRuntimeConfigRejectsOversizedInput(t *testing.T) {
	input := strings.Repeat(" ", maxNodeRuntimeConfigBytes+1)
	if _, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(input)); !errors.Is(err, ErrInvalidNodeRuntimeConfig) {
		t.Fatalf("DecodeTrustedNodeRuntimeConfig error = %v; want ErrInvalidNodeRuntimeConfig", err)
	}
}

func TestDecodeTrustedNodeRuntimeConfigAcceptsExplicitIsolatedHTTPSCanary(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	config := canaryRuntimeConfigJSON("isolated-test", "https://canary.example.invalid"+nodeProbeCanaryPath, string(caPEM))
	decoded, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(config))
	if err != nil {
		t.Fatalf("DecodeTrustedNodeRuntimeConfig error = %v", err)
	}
	if decoded.DataPlaneProbe.Status != RuntimeProbeHTTPSCanary {
		t.Fatalf("probe status = %q; want %q", decoded.DataPlaneProbe.Status, RuntimeProbeHTTPSCanary)
	}
}

func TestDecodeTrustedNodeRuntimeConfigRejectsUnsafeCanaryDeclarations(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	tests := []struct {
		name        string
		environment string
		endpoint    string
		caPEM       string
	}{
		{name: "private HTTP endpoint", environment: "isolated-test", endpoint: "http://canary.example.invalid" + nodeProbeCanaryPath, caPEM: string(caPEM)},
		{name: "custom destination port", environment: "isolated-test", endpoint: "https://canary.example.invalid:8443" + nodeProbeCanaryPath, caPEM: string(caPEM)},
		{name: "URL query", environment: "isolated-test", endpoint: "https://canary.example.invalid" + nodeProbeCanaryPath + "?target=private", caPEM: string(caPEM)},
		{name: "production canary remains unavailable", environment: "production", endpoint: "https://canary.example.invalid" + nodeProbeCanaryPath, caPEM: string(caPEM)},
		{name: "missing trust bundle", environment: "isolated-test", endpoint: "https://canary.example.invalid" + nodeProbeCanaryPath},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			input := canaryRuntimeConfigJSON(test.environment, test.endpoint, test.caPEM)
			if _, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(input)); !errors.Is(err, ErrInvalidNodeRuntimeConfig) {
				t.Fatalf("DecodeTrustedNodeRuntimeConfig error = %v; want ErrInvalidNodeRuntimeConfig", err)
			}
		})
	}
}

func canaryRuntimeConfigJSON(environment, endpoint, caPEM string) string {
	endpointJSON, _ := json.Marshal(endpoint)
	caPEMJSON, _ := json.Marshal(caPEM)
	credentialSource := RuntimeCredentialTestFile
	if environment == RuntimeEnvironmentProd {
		credentialSource = RuntimeCredentialSecure
	}
	return `{"schema_version":"xlb-node-runtime/v1","environment":"` + environment + `","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"` + credentialSource + `"},"profile_renderer":{"status":"unavailable"},"data_plane_probe":{"status":"https-canary","canary_endpoint":` + string(endpointJSON) + `,"server_ca_pem":` + string(caPEMJSON) + `}}`
}
