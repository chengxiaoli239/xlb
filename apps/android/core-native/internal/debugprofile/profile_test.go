package debugprofile

import (
	"errors"
	"testing"

	"github.com/metacubex/mihomo/config"
	_ "github.com/metacubex/mihomo/hub/executor" // Provides Mihomo's config linkname hook during tests.
)

func TestFirstRouteProfileDiscardsSubscriptionCapabilities(t *testing.T) {
	source := config.DefaultRawConfig()
	source.Port = 7890
	source.ExternalController = "0.0.0.0:9090"
	source.ExternalUI = "remote-ui"
	source.ProxyProvider = map[string]map[string]any{"remote": {"type": "http", "url": "https://example.invalid/provider"}}
	source.RuleProvider = map[string]map[string]any{"remote": {"type": "http", "url": "https://example.invalid/rules"}}
	source.Rule = []string{"RULE-SET,remote,DIRECT"}
	source.Proxy = []map[string]any{{"name": "node-a", "type": "ss"}, {"name": "node-b", "type": "ss"}}

	got, err := FirstRouteProfile(source)
	if err != nil {
		t.Fatal(err)
	}
	if got.Port != 0 || got.ExternalController != "" || got.ExternalUI != "" || got.ExternalUIURL != "" ||
		len(got.ProxyProvider) != 0 || len(got.RuleProvider) != 0 || len(got.Rule) != 0 || len(got.Listeners) != 0 || got.Tun.Enable {
		t.Fatal("subscription capabilities leaked into isolated debug profile")
	}
	if len(got.Proxy) != 1 || got.Proxy[0]["name"] != "node-a" || got.ProxyGroup[0]["name"] != "GLOBAL" ||
		got.DNS.Listen != "" || !got.DNS.Enable || got.Profile.StoreSelected {
		t.Fatal("debug profile does not select a single inline proxy with local DNS")
	}
}

func TestFirstRouteProfileBuildsGlobalRouteFromHttpsProvider(t *testing.T) {
	source := config.DefaultRawConfig()
	source.ProxyProvider = map[string]map[string]any{
		"remote": {"type": "http", "url": "https://provider.example.test/subscription", "interval": 3600},
	}

	got, err := FirstRouteProfile(source)
	if err != nil {
		t.Fatal(err)
	}
	if len(got.Proxy) != 0 || len(got.ProxyProvider) != 1 || got.ProxyProvider["remote"] == nil ||
		got.ProxyGroup[0]["use"].([]string)[0] != "remote" || len(got.Rule) != 0 || len(got.RuleProvider) != 0 {
		t.Fatal("provider-only profile was not reduced to a single global remote route")
	}
	if _, err := config.ParseRawConfig(got); err != nil {
		t.Fatalf("provider route did not parse: %v", err)
	}
}

func TestFirstRouteProfileRejectsInsecureAndLocalProviderUrls(t *testing.T) {
	for _, address := range []string{
		"http://provider.example.test/subscription",
		"https://127.0.0.1/subscription",
		"https://10.0.0.2/subscription",
		"https://user:pass@provider.example.test/subscription",
	} {
		source := config.DefaultRawConfig()
		source.ProxyProvider = map[string]map[string]any{
			"remote": {"type": "http", "url": address},
		}
		if _, err := FirstRouteProfile(source); !errors.Is(err, ErrUnsupportedProxyProvider) {
			t.Fatalf("address %q: got %v, want ErrUnsupportedProxyProvider", address, err)
		}
	}
}

func TestFirstRouteNameMatchesTheNodeUsedByDebugTunnel(t *testing.T) {
	source := config.DefaultRawConfig()
	source.Proxy = []map[string]any{{"name": "香港线路 A", "type": "ss"}, {"name": "香港线路 B", "type": "ss"}}

	got, err := FirstRouteName(source)
	if err != nil {
		t.Fatal(err)
	}
	if got != "香港线路 A" {
		t.Fatalf("got %q, want the first inline node", got)
	}
}

func TestFirstRouteNameRejectsMissingNodeMetadata(t *testing.T) {
	source := config.DefaultRawConfig()
	source.Proxy = []map[string]any{{"type": "ss"}}
	if _, err := FirstRouteName(source); !errors.Is(err, ErrInvalidInlineProxy) {
		t.Fatalf("got %v, want ErrInvalidInlineProxy", err)
	}
}
