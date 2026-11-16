package debugprofile

import (
	"errors"
	"strings"

	"github.com/metacubex/mihomo/config"
	"github.com/metacubex/mihomo/tunnel"
)

var ErrNoInlineProxy = errors.New("profile has no inline proxies")
var ErrInvalidInlineProxy = errors.New("profile has no usable inline proxy")

// FirstInlineProxy builds an isolated debug profile. The subscription's listeners,
// rules, remote providers, controller, DNS and TUN settings are never applied.
func FirstInlineProxy(source *config.RawConfig) (*config.RawConfig, error) {
	if source == nil || len(source.Proxy) == 0 {
		return nil, ErrNoInlineProxy
	}
	first := source.Proxy[0]
	name, hasName := first["name"].(string)
	proxyType, hasType := first["type"].(string)
	if !hasName || !hasType || strings.TrimSpace(name) == "" || strings.TrimSpace(proxyType) == "" {
		return nil, ErrInvalidInlineProxy
	}

	isolated := config.DefaultRawConfig()
	isolated.Mode = tunnel.Global
	isolated.Proxy = []map[string]any{first}
	isolated.ProxyGroup = []map[string]any{{
		"name":    "GLOBAL",
		"type":    "select",
		"proxies": []string{name},
	}}
	isolated.DNS.Enable = true
	isolated.DNS.Listen = ""
	isolated.ExternalUIURL = ""
	isolated.Profile.StoreSelected = false
	return isolated, nil
}
