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
		{name: "production cannot use test schema", input: strings.Replace(valid, `"environment":"isolated-test"`, `"environment":"production"`, 1)},
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

func TestDecodeTrustedNodeRuntimeConfigAcceptsExplicitTestMihomoRenderer(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	input := rendererRuntimeConfigJSON(RuntimeEnvironmentTest, RuntimeProfileMihomoBridgeTest,
		"https://bridge.example.invalid:9444", string(caPEM))

	config, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(input))
	if err != nil {
		t.Fatalf("DecodeTrustedNodeRuntimeConfig error = %v", err)
	}
	if config.ProfileRenderer.Status != RuntimeProfileMihomoBridgeTest {
		t.Fatalf("renderer status = %q; want %q", config.ProfileRenderer.Status, RuntimeProfileMihomoBridgeTest)
	}
}

func TestDecodeTrustedNodeRuntimeConfigAcceptsProductionRendererContractV2(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	input := productionRendererRuntimeConfigJSON("mihomo-bridge-v1", "https://bridge.example.invalid:9444", string(caPEM))

	config, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(input))
	if err != nil {
		t.Fatalf("DecodeTrustedNodeRuntimeConfig error = %v", err)
	}
	if config.SchemaVersion != TrustedNodeRuntimeSchemaV2 || config.Environment != RuntimeEnvironmentProd ||
		config.ProfileRenderer.Status != RuntimeProfileMihomoBridge {
		t.Fatalf("decoded renderer contract = %q/%q/%q; want production v2 Mihomo bridge",
			config.SchemaVersion, config.Environment, config.ProfileRenderer.Status)
	}
}

func TestProductionRuntimeV2RequiresQuotaAuthorityConfiguration(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	valid := productionRendererRuntimeConfigJSON(RuntimeProfileMihomoBridge,
		"https://bridge.example.invalid:9444", string(caPEM))
	tests := []struct {
		name   string
		mutate func(map[string]any)
	}{
		{
			name: "missing quota authority",
			mutate: func(config map[string]any) {
				delete(config, "quota_authority")
			},
		},
		{
			name: "insecure authority endpoint",
			mutate: func(config map[string]any) {
				config["quota_authority"].(map[string]any)["endpoint"] = "http://quota.example.invalid"
			},
		},
		{
			name: "authority endpoint without a hostname",
			mutate: func(config map[string]any) {
				config["quota_authority"].(map[string]any)["endpoint"] = "https://:443"
			},
		},
		{
			name: "invalid authority trust bundle",
			mutate: func(config map[string]any) {
				config["quota_authority"].(map[string]any)["server_ca_pem"] = "not-a-certificate"
			},
		},
		{
			name: "non-positive grant",
			mutate: func(config map[string]any) {
				config["quota_authority"].(map[string]any)["grant_bytes"] = 0
			},
		},
		{
			name: "outbox limit above recovery bound",
			mutate: func(config map[string]any) {
				config["quota_authority"].(map[string]any)["outbox_limit"] = 1001
			},
		},
		{
			name: "unbounded timeout",
			mutate: func(config map[string]any) {
				config["quota_authority"].(map[string]any)["timeout_seconds"] = 61
			},
		},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			var config map[string]any
			if err := json.Unmarshal([]byte(valid), &config); err != nil {
				t.Fatal(err)
			}
			test.mutate(config)
			payload, err := json.Marshal(config)
			if err != nil {
				t.Fatal(err)
			}
			if _, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(string(payload))); !errors.Is(err, ErrInvalidNodeRuntimeConfig) {
				t.Fatalf("DecodeTrustedNodeRuntimeConfig error = %v; want ErrInvalidNodeRuntimeConfig", err)
			}
		})
	}
}

func TestDecodeTrustedNodeRuntimeConfigRejectsUnsafeProductionRendererContractV2(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	valid := productionRendererRuntimeConfigJSON(RuntimeProfileMihomoBridge,
		"https://bridge.example.invalid:9444", string(caPEM))
	var config map[string]any
	if err := json.Unmarshal([]byte(valid), &config); err != nil {
		t.Fatal(err)
	}
	config["data_plane_probe"] = map[string]any{
		"status":          RuntimeProbeHTTPSCanary,
		"canary_endpoint": "https://canary.example.invalid" + nodeProbeCanaryPath,
		"server_ca_pem":   string(caPEM),
	}
	productionCanary, err := json.Marshal(config)
	if err != nil {
		t.Fatal(err)
	}
	tests := []struct {
		name  string
		input string
	}{
		{name: "missing endpoint", input: productionRendererRuntimeConfigJSON(RuntimeProfileMihomoBridge, "", string(caPEM))},
		{name: "invalid CA", input: productionRendererRuntimeConfigJSON(RuntimeProfileMihomoBridge,
			"https://bridge.example.invalid:9444", "not-a-certificate")},
		{name: "test renderer is not production renderer", input: strings.Replace(valid,
			RuntimeProfileMihomoBridge, RuntimeProfileMihomoBridgeTest, 1)},
		{name: "secure-store declaration required", input: strings.Replace(valid,
			RuntimeCredentialSecure, RuntimeCredentialTestFile, 1)},
		{name: "test-only canary is not in production contract", input: string(productionCanary)},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if _, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(test.input)); !errors.Is(err, ErrInvalidNodeRuntimeConfig) {
				t.Fatalf("DecodeTrustedNodeRuntimeConfig error = %v; want ErrInvalidNodeRuntimeConfig", err)
			}
		})
	}
}

func TestTrustedNodeRuntimeV2RequiresNodeScopedKMSKeyARN(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	valid := productionRendererRuntimeConfigJSON(RuntimeProfileMihomoBridge,
		"https://bridge.example.invalid:9444", string(caPEM))
	for _, invalidARN := range []string{
		"",
		"arn:aws:kms:us-west-2:123456789012:alias/node-key",
		"arn:aws:kms:us-west-2:123456789012:key/123456789012",
		"arn:aws-fake:kms:us-west-2:123456789012:key/12345678-1234-1234-1234-123456789012",
		"arn:aws:kms:us-east-1:123456789012:key/12345678-1234-1234-1234-123456789012",
		"arn:aws-cn:kms:cn-north-1:123456789012:key/12345678-1234-1234-1234-123456789012",
	} {
		t.Run(invalidARN, func(t *testing.T) {
			var config map[string]any
			if err := json.Unmarshal([]byte(valid), &config); err != nil {
				t.Fatal(err)
			}
			config["node_tls_identity"] = map[string]any{"source": "aws-kms", "key_arn": invalidARN}
			payload, err := json.Marshal(config)
			if err != nil {
				t.Fatal(err)
			}
			if _, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(string(payload))); err == nil {
				t.Fatalf("runtime config accepted invalid KMS key ARN %q", invalidARN)
			}
		})
	}
}

func TestTrustedNodeRuntimeV2AcceptsMultiRegionKMSKeyARN(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	input := productionRendererRuntimeConfigJSON(RuntimeProfileMihomoBridge,
		"https://bridge.example.invalid:9444", string(caPEM))
	input = strings.Replace(input,
		"key/12345678-1234-1234-1234-123456789012",
		"key/mrk-12345678-1234-1234-1234-123456789012", 1)
	if _, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(input)); err != nil {
		t.Fatalf("DecodeTrustedNodeRuntimeConfig rejected a valid multi-region KMS key ARN: %v", err)
	}
}

func TestDecodeTrustedNodeRuntimeConfigRejectsUnsafeMihomoRendererDeclarations(t *testing.T) {
	_, _, caPEM := createTestCertificates(t)
	tests := []struct {
		name        string
		environment string
		status      string
		endpoint    string
		caPEM       string
	}{
		{name: "production renderer", environment: RuntimeEnvironmentProd, status: RuntimeProfileMihomoBridgeTest, endpoint: "https://bridge.example.invalid:9444", caPEM: string(caPEM)},
		{name: "missing endpoint", environment: RuntimeEnvironmentTest, status: RuntimeProfileMihomoBridgeTest, caPEM: string(caPEM)},
		{name: "insecure endpoint", environment: RuntimeEnvironmentTest, status: RuntimeProfileMihomoBridgeTest, endpoint: "http://bridge.example.invalid:9444", caPEM: string(caPEM)},
		{name: "invalid CA", environment: RuntimeEnvironmentTest, status: RuntimeProfileMihomoBridgeTest, endpoint: "https://bridge.example.invalid:9444", caPEM: "not-a-certificate"},
		{name: "descriptor supplied while unavailable", environment: RuntimeEnvironmentTest, status: RuntimeProfileUnavailable, endpoint: "https://bridge.example.invalid:9444", caPEM: string(caPEM)},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			input := rendererRuntimeConfigJSON(test.environment, test.status, test.endpoint, test.caPEM)
			if _, err := DecodeTrustedNodeRuntimeConfig(strings.NewReader(input)); !errors.Is(err, ErrInvalidNodeRuntimeConfig) {
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

func rendererRuntimeConfigJSON(environment, status, endpoint, caPEM string) string {
	endpointJSON, _ := json.Marshal(endpoint)
	caPEMJSON, _ := json.Marshal(caPEM)
	credentialSource := RuntimeCredentialTestFile
	if environment == RuntimeEnvironmentProd {
		credentialSource = RuntimeCredentialSecure
	}
	return `{"schema_version":"xlb-node-runtime/v1","environment":"` + environment +
		`","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"` + credentialSource +
		`"},"profile_renderer":{"status":"` + status + `","bridge_endpoint":` + string(endpointJSON) + `,"bridge_server_ca_pem":` + string(caPEMJSON) + `}}`
}

func productionRendererRuntimeConfigJSON(status, endpoint, caPEM string) string {
	endpointJSON, _ := json.Marshal(endpoint)
	caPEMJSON, _ := json.Marshal(caPEM)
	return `{"schema_version":"xlb-node-runtime/v2","environment":"production","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"node_tls_identity":{"source":"aws-kms","key_arn":"arn:aws:kms:us-west-2:123456789012:key/12345678-1234-1234-1234-123456789012"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"platform-secure-store"},"profile_renderer":{"status":"` + status + `","bridge_endpoint":` + string(endpointJSON) + `,"bridge_server_ca_pem":` + string(caPEMJSON) + `},"quota_authority":{"endpoint":"https://quota.example.invalid","server_ca_pem":` + string(caPEMJSON) + `,"grant_bytes":4096,"outbox_limit":100,"timeout_seconds":5}}`
}
