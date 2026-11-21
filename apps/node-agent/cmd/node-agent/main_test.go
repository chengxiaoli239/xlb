package main

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	agent "xlb/node-agent"
)

func TestProductionModeRemainsUnavailable(t *testing.T) {
	t.Setenv("NODE_AGENT_MODE", "production")

	err := run()
	if err == nil || !strings.Contains(err.Error(), "production mode is not implemented") {
		t.Fatalf("run() error = %v; want production mode to fail closed", err)
	}
}

func TestRuntimeConfigMustMatchIsolatedAgentIdentity(t *testing.T) {
	tests := []struct {
		name   string
		config string
		want   error
	}{
		{
			name:   "matching isolated test node",
			config: `{"schema_version":"xlb-node-runtime/v1","environment":"isolated-test","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"test-file-only"},"profile_renderer":{"status":"unavailable"}}`,
		},
		{
			name:   "production runtime is not enabled",
			config: `{"schema_version":"xlb-node-runtime/v1","environment":"production","node":{"id":"node-a","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"platform-secure-store"},"profile_renderer":{"status":"unavailable"}}`,
			want:   agent.ErrInvalidNodeRuntimeConfig,
		},
		{
			name:   "node identity mismatch",
			config: `{"schema_version":"xlb-node-runtime/v1","environment":"isolated-test","node":{"id":"node-b","provider":"aws","region":"us-west-2"},"proof_bridge":{"protocol":"xlb-proof-v1","listen_address":"127.0.0.1:10808","device_proof_required":true,"credential_source":"test-file-only"},"profile_renderer":{"status":"unavailable"}}`,
			want:   agent.ErrInvalidNodeRuntimeConfig,
		},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			path := filepath.Join(t.TempDir(), "runtime.json")
			if err := os.WriteFile(path, []byte(test.config), 0o600); err != nil {
				t.Fatal(err)
			}
			err := validateRuntimeConfig(path, "node-a")
			if test.want == nil && err != nil {
				t.Fatalf("validateRuntimeConfig error = %v", err)
			}
			if test.want != nil && !errors.Is(err, test.want) {
				t.Fatalf("validateRuntimeConfig error = %v; want %v", err, test.want)
			}
		})
	}
}

func TestRuntimeConfigPathIsRequiredAtStartup(t *testing.T) {
	t.Setenv("NODE_AGENT_MODE", "isolated-test")
	t.Setenv("NODE_AGENT_NODE_ID", "node-a")
	t.Setenv("NODE_AGENT_RUNTIME_CONFIG", "")

	err := run()
	if err == nil || !strings.Contains(err.Error(), "identity, state, and mTLS files are required") {
		t.Fatalf("run() error = %v; want required runtime config error", err)
	}
}

func TestProbeListenerMustBeDistinctLoopback(t *testing.T) {
	tests := []struct {
		name    string
		address string
		wantErr bool
	}{
		{name: "disabled by default"},
		{name: "loopback IP", address: "127.0.0.1:9445"},
		{name: "localhost", address: "localhost:9445"},
		{name: "wildcard bind", address: "0.0.0.0:9445", wantErr: true},
		{name: "public bind", address: "203.0.113.7:9445", wantErr: true},
		{name: "management address collision", address: "127.0.0.1:9443", wantErr: true},
		{name: "normalized management port collision", address: "localhost:09443", wantErr: true},
		{name: "data plane address collision", address: "127.0.0.1:9444", wantErr: true},
		{name: "invalid address", address: "not-an-address", wantErr: true},
		{name: "invalid port", address: "127.0.0.1:94450", wantErr: true},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			err := validateProbeListenerAddress(test.address, "127.0.0.1:9443", "127.0.0.1:9444")
			if test.wantErr && err == nil {
				t.Fatal("expected probe listener address to be rejected")
			}
			if !test.wantErr && err != nil {
				t.Fatalf("probe listener address rejected: %v", err)
			}
		})
	}
}

func TestProbeServerIsNotConstructedWhenDisabled(t *testing.T) {
	server, err := newDataPlaneProbeServer("", "node-a", "", "", "")
	if err != nil {
		t.Fatalf("disabled probe listener returned error: %v", err)
	}
	if server != nil {
		t.Fatal("disabled probe listener unexpectedly constructed a server")
	}
}
