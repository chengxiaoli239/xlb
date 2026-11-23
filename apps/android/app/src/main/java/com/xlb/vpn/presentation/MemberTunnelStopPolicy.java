package com.xlb.vpn.presentation;

import com.xlb.vpn.tunnel.XlbVpnService;

final class MemberTunnelStopPolicy {
    private MemberTunnelStopPolicy() {}

    static boolean shouldStop(String tunnelState, boolean memberTunnelActive,
            boolean activeEntitlement, boolean nodeAuthorizationReady) {
        boolean tunnelInProgress = XlbVpnService.STATE_CONNECTED.equals(tunnelState)
                || XlbVpnService.STATE_CONNECTING.equals(tunnelState);
        return tunnelInProgress && (!activeEntitlement
                || (memberTunnelActive && !nodeAuthorizationReady));
    }
}
