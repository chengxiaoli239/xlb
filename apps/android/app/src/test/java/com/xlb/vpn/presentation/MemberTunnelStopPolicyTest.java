package com.xlb.vpn.presentation;

import com.xlb.vpn.tunnel.XlbVpnService;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class MemberTunnelStopPolicyTest {
    @Test
    public void expiredEntitlementStopsConnectedOrConnectingTunnel() {
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTED, false));
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTING, false));
    }

    @Test
    public void activeEntitlementAndInactiveTunnelStatesDoNotTriggerStop() {
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTED, true));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTING, true));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_DISCONNECTED, false));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_ERROR, false));
    }
}
