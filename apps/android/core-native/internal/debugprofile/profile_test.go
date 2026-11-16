package debugprofile

import (
	"errors"
	"testing"

	"github.com/metacubex/mihomo/config"
)

func TestFirstInlineProxyDiscardsSubscriptionCapabilities(t *testing.T) {
	source := config.DefaultRawConfig()
	source.Port = 7890
	source.ExternalController = "0.0.0.0:9090"
	source.ExternalUI = "remote-ui"
	source.ProxyProvider = map[string]map[string]any{"remote": {"type": "http", "url": "https://example.invalid/provider"}}
	source.RuleProvider = map[string]map[string]any{"remote": {"type": "http", "url": "https://example.invalid/rules"}}
	source.Rule = []string{"RULE-SET,remote,DIRECT"}
	source.Proxy = []map[string]any{{"name": "node-a", "type": "ss"}, {"name": "node-b", "type": "ss"}}

	got, err := FirstInlineProxy(source)
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

func TestFirstInlineProxyRejectsProviderOnlyProfiles(t *testing.T) {
	source := config.DefaultRawConfig()
	source.ProxyProvider = map[string]map[string]any{"remote": {"type": "http"}}
	if _, err := FirstInlineProxy(source); !errors.Is(err, ErrNoInlineProxy) {
		t.Fatalf("got %v, want ErrNoInlineProxy", err)
	}
}
