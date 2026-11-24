package com.xlb.vpn.tunnel;

/** Maps safe client-facing failure messages to stable, non-sensitive support codes. */
final class TunnelFailureDiagnostics {
    private TunnelFailureDiagnostics() {}

    static String codeFor(String safeMessage) {
        if (safeMessage == null || safeMessage.isBlank()) return "startup_failed";
        if (safeMessage.equals("系统 VPN 授权已失效")) return "vpn_permission_required";
        if (safeMessage.contains("没有返回可用线路")
                || safeMessage.contains("没有可用节点")
                || safeMessage.equals("没有可用的线路配置")
                || safeMessage.equals("proxy provider has no proxies")
                || safeMessage.equals("profile has no proxies")) {
            return "route_candidates_empty";
        }
        if (safeMessage.startsWith("测试配置") || safeMessage.startsWith("测试订阅")) {
            return "profile_incompatible";
        }
        if (safeMessage.contains("初始化超时")) return "startup_timeout";
        if (safeMessage.contains("连接或网络验证失败")) return "connection_unverified";
        if (safeMessage.contains("资源") && safeMessage.contains("清理")) return "resource_cleanup_pending";
        if (safeMessage.contains("VPN 通道")) {
            return "system_tunnel_unavailable";
        }
        if (safeMessage.contains("内核")) return "core_unavailable";
        if (safeMessage.contains("会员安全线路尚未接入")) return "member_tunnel_unavailable";
        if (safeMessage.contains("会员线路配置不可用")) return "member_route_unavailable";

        return "startup_failed";
    }
}
