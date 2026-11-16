package profileerrors

import (
	"errors"
	"strings"
	"testing"
)

func TestValidationMessageClassifiesWithoutEchoingDetails(t *testing.T) {
	tests := []struct {
		name string
		err  string
		want string
	}{
		{"dns", "DNS NameServer[0] format error: private.example", "测试配置的 DNS 设置暂不兼容"},
		{"provider", "parse proxy provider SECRET-NAME error: invalid path /private/file", "测试配置的代理提供者暂不兼容"},
		{"rule provider", "parse rule provider PRIVATE-RULES error: missing URL", "测试配置引用的规则集提供者缺失"},
		{"missing rule set", "rules[0] rule set [SECRET-SET] not found", "测试配置引用的规则集缺失"},
		{"rule type", "rule provider type error, expect domain, actual PRIVATE-TYPE", "测试配置的规则集类型不匹配"},
		{"rule cycle", "sub-rule error: circular references SECRET-RULE", "测试配置的规则存在循环引用"},
		{"rule format", "rules[0] error: format invalid: PRIVATE-RULE", "测试配置中的规则格式不受支持"},
		{"missing strategy", "rules[0] proxy [SECRET-POLICY] not found", "测试配置中的规则引用了不存在的策略"},
		{"proxy group", "ProxyGroup MY-PRIVATE-GROUP: duplicate group name", "测试配置的代理组暂不兼容"},
		{"listener", "listener 0: invalid address 10.0.0.1:1234", "测试配置包含暂不支持的监听器设置"},
		{"rule", "sub-rule[0:PRIVATE-DOMAIN] error: adapter not found", "测试配置引用的子规则缺失"},
		{"proxy", "proxy SECRET-NODE dial failed", "测试配置中的节点设置暂不兼容"},
		{"generic", "SECRET-VALUE: invalid configuration field", "测试配置格式或字段暂不兼容"},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got := ValidationMessage(errors.New(test.err))
			if got != test.want {
				t.Fatalf("got %q, want %q", got, test.want)
			}
			if strings.Contains(got, "SECRET") || strings.Contains(got, "private") || strings.Contains(got, "10.0.0.1") {
				t.Fatalf("validation message contains source details: %q", got)
			}
		})
	}
}

func TestValidationMessageHandlesNil(t *testing.T) {
	if got := ValidationMessage(nil); got != "测试配置校验失败" {
		t.Fatalf("got %q", got)
	}
}
