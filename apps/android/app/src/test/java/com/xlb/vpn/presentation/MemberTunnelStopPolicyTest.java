package com.xlb.vpn.presentation;

import com.xlb.vpn.tunnel.XlbVpnService;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class MemberTunnelStopPolicyTest {
    @Test
    public void expiredEntitlementStopsConnectedOrConnectingTunnel() {
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTED, true, false, true));
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTING, true, false, true));
    }

    @Test
    public void lostNodeAuthorizationStopsOnlyAMemberTunnel() {
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTED, true, true, false));
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTING, true, true, false));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTED, false, true, false));
    }

    @Test
    public void readyMemberAuthorizationAndInactiveTunnelStatesDoNotTriggerStop() {
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTED, true, true, true));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTING, true, true, true));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_DISCONNECTED, true, false, false));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_ERROR, true, false, false));
    }
}
