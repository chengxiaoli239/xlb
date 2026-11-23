package debugprofile

import (
	"bytes"
	"errors"
	"strings"
	"testing"

	"github.com/metacubex/mihomo/config"
	_ "github.com/metacubex/mihomo/hub/executor"
	"github.com/metacubex/mihomo/listener"
	"github.com/metacubex/mihomo/tunnel"
)

const proofBridgeFixture = "mixed-port: 7890\nallow-lan: false\nbind-address: 127.0.0.1\nmode: global\nlog-level: warning\nproxies:\n  - name: XLB proof bridge\n    type: socks5\n    server: 127.0.0.1\n    port: 17890\n    udp: false\nproxy-groups:\n  - name: GLOBAL\n    type: select\n    proxies:\n      - XLB proof bridge\nrules:\n  - MATCH,GLOBAL\n"

func TestMemberBridgeProfileInjectsLocalAuthenticationIntoAllowlistedRoute(t *testing.T) {
	profile, err := MemberBridgeProfile([]byte(proofBridgeFixture), "user-token", "password-token")
	if err != nil {
		t.Fatalf("MemberBridgeProfile rejected the renderer fixture: %v", err)
	}
	if bytes.Contains(profile, []byte("peer_credential")) || bytes.Contains(profile, []byte("subscription_token")) {
		t.Fatal("local Mihomo profile contains a server credential")
	}
	raw, err := config.UnmarshalRawConfig(profile)
	if err != nil {
		t.Fatalf("Mihomo rejected the local profile: %v", err)
	}
	parsed, err := config.ParseRawConfig(raw)
	if err != nil {
		t.Fatalf("Mihomo could not parse the local profile: %v", err)
	}
	proxy := parsed.Proxies["XLB proof bridge"]
	if proxy == nil {
		t.Fatal("local authenticated proxy is missing")
	}
	if parsed.General.MixedPort != 0 {
		t.Fatalf("member profile mixed inbound port = %d, want it disabled", parsed.General.MixedPort)
	}
	listener.ReCreateMixed(parsed.General.MixedPort, tunnel.Tunnel)
	if actual := listener.GetPorts().MixedPort; actual != 0 {
		t.Fatalf("Mihomo mixed inbound is listening on port %d", actual)
	}
	if _, err := FirstRouteProfile(raw); !errors.Is(err, ErrLoopbackProxyRequiresAuthorization) {
		t.Fatalf("generic profile import accepted an authorized member loopback route: %v", err)
	}
	if !strings.Contains(string(profile), "username: user-token") || !strings.Contains(string(profile), "password: password-token") {
		t.Fatal("local SOCKS credentials were not injected")
	}
}

func TestMemberBridgeProfileRejectsUntrustedCapabilities(t *testing.T) {
	invalidProfiles := []string{
		strings.Replace(proofBridgeFixture, "server: 127.0.0.1", "server: 192.0.2.1", 1),
		strings.Replace(proofBridgeFixture, "port: 17890", "port: 1080", 1),
		strings.Replace(proofBridgeFixture, "allow-lan: false", "allow-lan: true", 1),
		proofBridgeFixture + "external-controller: 0.0.0.0:9090\n",
		strings.Replace(proofBridgeFixture, "udp: false", "udp: true", 1),
		strings.Replace(proofBridgeFixture, "udp: false", "username: old\n    password: old\n    udp: false", 1),
		proofBridgeFixture + "---\nmode: global\n",
	}
	for index, invalid := range invalidProfiles {
		if _, err := MemberBridgeProfile([]byte(invalid), "user-token", "password-token"); err == nil {
			t.Fatalf("profile %d with an untrusted capability was accepted", index)
		}
	}
	if _, err := MemberBridgeProfile([]byte(proofBridgeFixture), "", "password-token"); err == nil {
		t.Fatal("empty local username was accepted")
	}
}
