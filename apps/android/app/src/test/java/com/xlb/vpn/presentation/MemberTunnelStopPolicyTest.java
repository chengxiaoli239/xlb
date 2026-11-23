package com.xlb.vpn.presentation;

import com.xlb.vpn.tunnel.XlbVpnService;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class MemberTunnelStopPolicyTest {
    @Test
    public void expiredEntitlementStopsConnectedOrConnectingTunnel() {
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTED, true, false, false, true));
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTING, true, false, false, true));
    }

    @Test
    public void lostNodeAuthorizationStopsOnlyAMemberTunnel() {
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTED, true, false, true, false));
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTING, true, false, true, false));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTED, false, false, true, false));
    }

    @Test
    public void lostNodeAuthorizationStopsPendingMemberGrantBeforeServiceClaimsTunnel() {
        assertTrue(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTING, false, true, false, true));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTING, false, true, true, true));
    }

    @Test
    public void readyMemberAuthorizationAndInactiveTunnelStatesDoNotTriggerStop() {
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTED, true, false, true, true));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_CONNECTING, true, false, true, true));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_DISCONNECTED, true, true, false, false));
        assertFalse(MemberTunnelStopPolicy.shouldStop(XlbVpnService.STATE_ERROR, true, true, false, false));
    }
}
