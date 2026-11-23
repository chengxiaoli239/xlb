package debugprofile

import (
	"bytes"
	"errors"
	"io"
	"strings"

	"github.com/metacubex/mihomo/config"
	"go.yaml.in/yaml/v3"
)

var ErrInvalidMemberBridgeProfile = errors.New("member bridge profile is invalid")

type memberBridgeProfileDocument struct {
	MixedPort   int                 `yaml:"mixed-port"`
	AllowLan    bool                `yaml:"allow-lan"`
	BindAddress string              `yaml:"bind-address"`
	Mode        string              `yaml:"mode"`
	LogLevel    string              `yaml:"log-level"`
	Proxies     []memberBridgeProxy `yaml:"proxies"`
	ProxyGroups []memberBridgeGroup `yaml:"proxy-groups"`
	Rules       []string            `yaml:"rules"`
}

type memberBridgeProxy struct {
	Name     string `yaml:"name"`
	Type     string `yaml:"type"`
	Server   string `yaml:"server"`
	Port     int    `yaml:"port"`
	UDP      bool   `yaml:"udp"`
	Username string `yaml:"username,omitempty"`
	Password string `yaml:"password,omitempty"`
}

type memberBridgeGroup struct {
	Name    string   `yaml:"name"`
	Type    string   `yaml:"type"`
	Proxies []string `yaml:"proxies"`
}

// MemberBridgeProfile validates the server-rendered loopback-only route and injects
// short-lived local SOCKS credentials without weakening the generic import path.
func MemberBridgeProfile(profile []byte, username, password string) ([]byte, error) {
	if len(profile) == 0 || len(profile) > 128*1024 || !validLocalCredential(username) || !validLocalCredential(password) {
		return nil, ErrInvalidMemberBridgeProfile
	}
	decoder := yaml.NewDecoder(bytes.NewReader(profile))
	decoder.KnownFields(true)
	var document memberBridgeProfileDocument
	if err := decoder.Decode(&document); err != nil {
		return nil, ErrInvalidMemberBridgeProfile
	}
	var trailing any
	if err := decoder.Decode(&trailing); err != io.EOF {
		return nil, ErrInvalidMemberBridgeProfile
	}
	if document.MixedPort != 7890 || document.AllowLan || document.BindAddress != "127.0.0.1" ||
		!strings.EqualFold(document.Mode, "global") || !strings.EqualFold(document.LogLevel, "warning") ||
		len(document.Proxies) != 1 || len(document.ProxyGroups) != 1 || len(document.Rules) != 1 ||
		document.Rules[0] != "MATCH,GLOBAL" {
		return nil, ErrInvalidMemberBridgeProfile
	}
	proxy := &document.Proxies[0]
	if proxy.Name != "XLB proof bridge" || !strings.EqualFold(proxy.Type, "socks5") ||
		proxy.Server != "127.0.0.1" || proxy.Port != 17890 || proxy.UDP || proxy.Username != "" || proxy.Password != "" {
		return nil, ErrInvalidMemberBridgeProfile
	}
	group := document.ProxyGroups[0]
	if group.Name != "GLOBAL" || !strings.EqualFold(group.Type, "select") ||
		len(group.Proxies) != 1 || group.Proxies[0] != proxy.Name {
		return nil, ErrInvalidMemberBridgeProfile
	}
	proxy.Username = username
	proxy.Password = password
	// Mihomo's mixed inbound listener is unnecessary for the TUN route and is
	// reachable by other local Android applications. Disable it in the runtime
	// profile while keeping the authenticated outbound SOCKS peer.
	document.MixedPort = 0

	profileWithCredentials, err := yaml.Marshal(document)
	if err != nil {
		return nil, ErrInvalidMemberBridgeProfile
	}
	raw, err := config.UnmarshalRawConfig(profileWithCredentials)
	if err != nil {
		return nil, ErrInvalidMemberBridgeProfile
	}
	if _, err := config.ParseRawConfig(raw); err != nil {
		return nil, ErrInvalidMemberBridgeProfile
	}
	return profileWithCredentials, nil
}

func validLocalCredential(value string) bool {
	if len(value) == 0 || len(value) > 255 {
		return false
	}
	return !strings.ContainsRune(value, '\x00')
}
