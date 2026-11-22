package debugprofile

import (
	"errors"
	"net/netip"
	"net/url"
	"sort"
	"strings"

	"github.com/metacubex/mihomo/config"
	"github.com/metacubex/mihomo/tunnel"
)

var ErrNoRoute = errors.New("profile has no usable proxies or providers")
var ErrInvalidInlineProxy = errors.New("profile has no usable inline proxy")
var ErrUnsupportedProxyProvider = errors.New("profile has no supported remote proxy provider")
var ErrLoopbackProxyRequiresAuthorization = errors.New("loopback proxy requires verified member authorization")

// FirstRouteProfile builds an isolated debug profile from the first inline proxy or
// the first HTTPS proxy provider. Subscription rules, listeners, controller, DNS
// and TUN settings are never applied.
func FirstRouteProfile(source *config.RawConfig) (*config.RawConfig, error) {
	name, err := FirstRouteName(source)
	if err != nil {
		return nil, err
	}

	isolated := config.DefaultRawConfig()
	isolated.Mode = tunnel.Global
	group := map[string]any{"name": "GLOBAL", "type": "select"}
	if len(source.Proxy) > 0 {
		isolated.Proxy = []map[string]any{source.Proxy[0]}
		group["proxies"] = []string{name}
	} else {
		provider := source.ProxyProvider[name]
		isolated.ProxyProvider = map[string]map[string]any{name: provider}
		group["use"] = []string{name}
	}
	isolated.ProxyGroup = []map[string]any{group}
	isolated.DNS.Enable = true
	isolated.DNS.Listen = ""
	isolated.ExternalUIURL = ""
	isolated.Profile.StoreSelected = false
	return isolated, nil
}

func FirstRouteName(source *config.RawConfig) (string, error) {
	if source == nil {
		return "", ErrNoRoute
	}
	if len(source.Proxy) > 0 {
		first := source.Proxy[0]
		name, hasName := first["name"].(string)
		proxyType, hasType := first["type"].(string)
		if !hasName || !hasType || strings.TrimSpace(name) == "" || strings.TrimSpace(proxyType) == "" {
			return "", ErrInvalidInlineProxy
		}
		if isLoopbackSocksProxy(first) {
			return "", ErrLoopbackProxyRequiresAuthorization
		}
		return name, nil
	}
	if len(source.ProxyProvider) == 0 {
		return "", ErrNoRoute
	}
	names := make([]string, 0, len(source.ProxyProvider))
	for name := range source.ProxyProvider {
		names = append(names, name)
	}
	sort.Strings(names)
	for _, name := range names {
		provider := source.ProxyProvider[name]
		if err := validateRemoteProvider(provider); err == nil {
			return name, nil
		}
	}
	return "", ErrUnsupportedProxyProvider
}

func isLoopbackSocksProxy(proxy map[string]any) bool {
	proxyType, _ := proxy["type"].(string)
	server, _ := proxy["server"].(string)
	if !strings.EqualFold(proxyType, "socks5") {
		return false
	}
	server = strings.TrimRight(strings.ToLower(strings.TrimSpace(server)), ".")
	if server == "localhost" || strings.HasSuffix(server, ".localhost") || strings.HasSuffix(server, ".localdomain") {
		return true
	}
	address, err := netip.ParseAddr(server)
	return err == nil && address.Unmap().IsLoopback()
}

func validateRemoteProvider(provider map[string]any) error {
	typeName, _ := provider["type"].(string)
	address, _ := provider["url"].(string)
	if !strings.EqualFold(typeName, "http") || strings.TrimSpace(address) == "" {
		return ErrUnsupportedProxyProvider
	}
	uri, err := url.Parse(strings.TrimSpace(address))
	if err != nil || !strings.EqualFold(uri.Scheme, "https") || uri.Hostname() == "" || uri.User != nil {
		return ErrUnsupportedProxyProvider
	}
	if address, err := netip.ParseAddr(uri.Hostname()); err == nil &&
		(address.IsPrivate() || address.IsLoopback() || address.IsLinkLocalUnicast() || address.IsUnspecified() || address.IsMulticast()) {
		return ErrUnsupportedProxyProvider
	}
	return nil
}
