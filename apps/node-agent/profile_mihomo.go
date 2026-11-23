package agent

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net"
	"strconv"
	"strings"

	"go.yaml.in/yaml/v3"
)

const (
	mihomoBridgeProxyName = "XLB proof bridge"
	mihomoBridgeGroupName = "GLOBAL"
	mihomoBridgeVersion   = "1.0.0"
)

type MihomoBridgeProfileRenderer struct {
	listenAddress     string
	bridgeEndpoint    string
	bridgeServerCAPEM string
}

type mihomoBridgeConfig struct {
	MixedPort   int                      `yaml:"mixed-port"`
	AllowLAN    bool                     `yaml:"allow-lan"`
	BindAddress string                   `yaml:"bind-address"`
	Mode        string                   `yaml:"mode"`
	LogLevel    string                   `yaml:"log-level"`
	Proxies     []mihomoBridgeProxy      `yaml:"proxies"`
	ProxyGroups []mihomoBridgeProxyGroup `yaml:"proxy-groups"`
	Rules       []string                 `yaml:"rules"`
}

type mihomoBridgeProxy struct {
	Name   string `yaml:"name"`
	Type   string `yaml:"type"`
	Server string `yaml:"server"`
	Port   int    `yaml:"port"`
	UDP    bool   `yaml:"udp"`
}

type mihomoBridgeProxyGroup struct {
	Name    string   `yaml:"name"`
	Type    string   `yaml:"type"`
	Proxies []string `yaml:"proxies"`
}

func NewMihomoBridgeProfileRenderer(listenAddress, bridgeEndpoint, bridgeServerCAPEM string) (*MihomoBridgeProfileRenderer, error) {
	host, portValue, err := net.SplitHostPort(listenAddress)
	if err != nil {
		return nil, ErrInvalid
	}
	ip := net.ParseIP(host)
	port, err := strconv.Atoi(portValue)
	if ip == nil || !ip.IsLoopback() || err != nil || port < 1 || port > 65535 {
		return nil, ErrInvalid
	}
	if err := validateMemberBridgeDescriptor(bridgeEndpoint, bridgeServerCAPEM); err != nil {
		return nil, err
	}
	return &MihomoBridgeProfileRenderer{
		listenAddress:  net.JoinHostPort(host, strconv.Itoa(port)),
		bridgeEndpoint: bridgeEndpoint, bridgeServerCAPEM: bridgeServerCAPEM,
	}, nil
}

func (renderer *MihomoBridgeProfileRenderer) MemberBridgeDescriptor() (string, string) {
	if renderer == nil {
		return "", ""
	}
	return renderer.bridgeEndpoint, renderer.bridgeServerCAPEM
}

func (renderer *MihomoBridgeProfileRenderer) Render(ctx context.Context, request ProfileRenderRequest) (ProfileArtifact, error) {
	if renderer == nil || ctx == nil || renderer.listenAddress == "" {
		return ProfileArtifact{}, ErrInvalid
	}
	if request.BridgeEndpoint != renderer.bridgeEndpoint || request.BridgeServerCAPEM != renderer.bridgeServerCAPEM {
		return ProfileArtifact{}, ErrInvalid
	}
	if err := ctx.Err(); err != nil {
		return ProfileArtifact{}, err
	}
	if !validRuntimeToken(request.NodeID, 64) || !validRuntimeToken(request.PeerID, 64) ||
		!validRuntimeToken(request.EntitlementID, 64) || !validRuntimeToken(request.RequestID, 64) ||
		!validRuntimeToken(request.DeviceID, 64) || request.Generation < 1 ||
		request.DeviceBindingGeneration < 1 || request.BridgeProtocol != "xlb-proof-v1" ||
		!validSHA256Fingerprint(request.DeviceKeyFingerprint) {
		return ProfileArtifact{}, ErrInvalid
	}

	bridgeHost, bridgePortValue, err := net.SplitHostPort(renderer.listenAddress)
	if err != nil {
		return ProfileArtifact{}, ErrInvalid
	}
	bridgePort, err := strconv.Atoi(bridgePortValue)
	if err != nil {
		return ProfileArtifact{}, ErrInvalid
	}
	config := mihomoBridgeConfig{
		MixedPort:   7890,
		AllowLAN:    false,
		BindAddress: "127.0.0.1",
		Mode:        "global",
		LogLevel:    "warning",
		Proxies: []mihomoBridgeProxy{{
			Name: mihomoBridgeProxyName, Type: "socks5", Server: bridgeHost,
			Port: bridgePort, UDP: false,
		}},
		ProxyGroups: []mihomoBridgeProxyGroup{{
			Name: mihomoBridgeGroupName, Type: "select", Proxies: []string{mihomoBridgeProxyName},
		}},
		Rules: []string{"MATCH,GLOBAL"},
	}
	content, err := yaml.Marshal(config)
	if err != nil {
		return ProfileArtifact{}, errors.Join(ErrInvalid, err)
	}
	artifact := ProfileArtifact{
		NodeID: request.NodeID, PeerID: request.PeerID, EntitlementID: request.EntitlementID,
		RequestID: request.RequestID, Generation: request.Generation, DeviceID: request.DeviceID,
		DeviceKeyFingerprint: request.DeviceKeyFingerprint, DeviceBindingGeneration: request.DeviceBindingGeneration,
		Format: "mihomo-yaml", RendererID: "xlb-mihomo-bridge-v1", RendererVersion: mihomoBridgeVersion,
		SHA256: profileContentSHA256(content), BridgeProtocol: request.BridgeProtocol,
		BridgeEndpoint: renderer.bridgeEndpoint, BridgeServerCAPEM: renderer.bridgeServerCAPEM,
		Connectable: true, Content: string(content),
	}
	if err := ValidateProfileArtifact(request, artifact); err != nil {
		return ProfileArtifact{}, err
	}
	return artifact, nil
}

func validateMihomoBridgeProfile(content string) error {
	decoder := yaml.NewDecoder(strings.NewReader(content))
	decoder.KnownFields(true)
	var config mihomoBridgeConfig
	if err := decoder.Decode(&config); err != nil {
		return fmt.Errorf("decode Mihomo bridge profile: %w", ErrInvalid)
	}
	if err := decoder.Decode(new(any)); err == nil {
		return fmt.Errorf("Mihomo bridge profile contains multiple documents: %w", ErrInvalid)
	} else if !errors.Is(err, io.EOF) {
		return fmt.Errorf("Mihomo bridge profile has trailing data: %w", ErrInvalid)
	}
	if config.MixedPort != 7890 || config.AllowLAN || config.BindAddress != "127.0.0.1" ||
		config.Mode != "global" || config.LogLevel != "warning" || len(config.Proxies) != 1 ||
		len(config.ProxyGroups) != 1 || len(config.Rules) != 1 || config.Rules[0] != "MATCH,GLOBAL" {
		return fmt.Errorf("Mihomo bridge profile settings are invalid: %w", ErrInvalid)
	}
	proxy := config.Proxies[0]
	if proxy.Name != mihomoBridgeProxyName || proxy.Type != "socks5" || proxy.UDP || !isLiteralLoopbackIP(proxy.Server) || proxy.Port < 1 || proxy.Port > 65535 {
		return fmt.Errorf("Mihomo bridge proxy must be loopback SOCKS5: %w", ErrInvalid)
	}
	group := config.ProxyGroups[0]
	if group.Name != mihomoBridgeGroupName || group.Type != "select" || len(group.Proxies) != 1 || group.Proxies[0] != mihomoBridgeProxyName {
		return fmt.Errorf("Mihomo bridge group must select only the local bridge: %w", ErrInvalid)
	}
	return nil
}

func validSHA256Fingerprint(value string) bool {
	if len(value) != sha256.Size*2 {
		return false
	}
	_, err := hex.DecodeString(value)
	return err == nil
}

func isLiteralLoopbackIP(host string) bool {
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}

func profileContentSHA256(content []byte) string {
	digest := sha256.Sum256(content)
	return hex.EncodeToString(digest[:])
}
