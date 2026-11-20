package com.xlb.vpn.tunnel;

import org.junit.Test;

import static org.junit.Assert.assertEquals;

public final class XlbVpnServiceTest {
    @Test
    public void explainsWhenRemoteSubscriptionProviderReturnsNoNodes() {
        assertEquals(
                "订阅没有返回可用线路，请确认订阅有效后重试",
                XlbVpnService.safeMessage(new IllegalStateException("proxy provider has no proxies")));
    }
}
