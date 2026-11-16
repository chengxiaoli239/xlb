package profileerrors

import "strings"

func ValidationMessage(err error) string {
	if err == nil {
		return "测试配置校验失败"
	}

	message := strings.ToLower(err.Error())
	switch {
	case strings.Contains(message, "rule"):
		return ruleValidationMessage(message)
	case strings.Contains(message, "dns"), strings.Contains(message, "nameserver"):
		return "测试配置的 DNS 设置暂不兼容"
	case strings.Contains(message, "proxy provider"), strings.Contains(message, "proxy-provider"),
		strings.Contains(message, "provider"):
		return "测试配置的代理提供者暂不兼容"
	case strings.Contains(message, "proxy group"), strings.Contains(message, "proxygroup"),
		strings.Contains(message, "global proxy group"):
		return "测试配置的代理组暂不兼容"
	case strings.Contains(message, "listener"):
		return "测试配置包含暂不支持的监听器设置"
	case strings.Contains(message, "rule"):
		return "测试配置的规则或规则集暂不兼容"
	case strings.Contains(message, "proxy"):
		return "测试配置中的节点设置暂不兼容"
	default:
		return "测试配置格式或字段暂不兼容"
	}
}

func ruleValidationMessage(message string) string {
	switch {
	case strings.Contains(message, "circular"), strings.Contains(message, "cycle"):
		return "测试配置的规则存在循环引用"
	case strings.Contains(message, "rule provider"), strings.Contains(message, "rule-provider"):
		if strings.Contains(message, "not found") || strings.Contains(message, "not find") || strings.Contains(message, "missing") {
			return "测试配置引用的规则集提供者缺失"
		}
		if strings.Contains(message, "type error") || strings.Contains(message, "behavior") {
			return "测试配置的规则集类型不匹配"
		}
		return "测试配置的规则集提供者暂不兼容"
	case strings.Contains(message, "rule-set"), strings.Contains(message, "rule set"):
		if strings.Contains(message, "not found") || strings.Contains(message, "not find") || strings.Contains(message, "missing") {
			return "测试配置引用的规则集缺失"
		}
		if strings.Contains(message, "type error") || strings.Contains(message, "behavior") {
			return "测试配置的规则集类型不匹配"
		}
		return "测试配置的规则集设置暂不兼容"
	case strings.Contains(message, "sub-rule") && (strings.Contains(message, "not found") || strings.Contains(message, "not find")):
		return "测试配置引用的子规则缺失"
	case strings.Contains(message, "format invalid"):
		return "测试配置中的规则格式不受支持"
	case strings.Contains(message, "proxy") && (strings.Contains(message, "not found") || strings.Contains(message, "not find")):
		return "测试配置中的规则引用了不存在的策略"
	case strings.Contains(message, "not found") || strings.Contains(message, "not find") || strings.Contains(message, "missing"):
		return "测试配置的规则引用缺失"
	default:
		return "测试配置的规则或规则集暂不兼容"
	}
}
