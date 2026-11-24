package com.xlb.vpn.tunnel;

import org.junit.Test;

import static org.junit.Assert.assertEquals;

public final class TunnelFailureDiagnosticsTest {
    @Test
    public void classifiesFailuresByObservedClientBoundary() {
        assertEquals("vpn_permission_required", TunnelFailureDiagnostics.codeFor("系统 VPN 授权已失效"));
        assertEquals("route_candidates_empty", TunnelFailureDiagnostics.codeFor("订阅没有返回可用线路，请确认订阅有效后重试"));
        assertEquals("profile_incompatible", TunnelFailureDiagnostics.codeFor("测试配置的 DNS 设置暂不兼容"));
        assertEquals("startup_timeout", TunnelFailureDiagnostics.codeFor("VPN 初始化超时，请检查订阅规则或网络"));
        assertEquals("connection_unverified", TunnelFailureDiagnostics.codeFor("线路连接或网络验证失败，请重试"));
        assertEquals("resource_cleanup_pending", TunnelFailureDiagnostics.codeFor("VPN 资源清理尚未完成，请重试"));
        assertEquals("system_tunnel_unavailable", TunnelFailureDiagnostics.codeFor("系统未能建立 VPN 通道"));
        assertEquals("member_tunnel_unavailable", TunnelFailureDiagnostics.codeFor("会员安全线路尚未接入，未启动 VPN。"));
        assertEquals("member_route_unavailable", TunnelFailureDiagnostics.codeFor("会员线路配置不可用"));
    }

    @Test
    public void unknownFailuresUseGenericCodeWithoutEchoingInput() {
        String privateError = "TLS failed for https://user:secret@example.invalid/path";

        assertEquals("startup_failed", TunnelFailureDiagnostics.codeFor(privateError));
    }
}
