package com.xlb.vpn.presentation;

import com.xlb.vpn.tunnel.XlbVpnService;

final class MemberTunnelStopPolicy {
    private MemberTunnelStopPolicy() {}

    static boolean shouldStop(String tunnelState, boolean activeEntitlement) {
        return !activeEntitlement && (XlbVpnService.STATE_CONNECTED.equals(tunnelState)
                || XlbVpnService.STATE_CONNECTING.equals(tunnelState));
    }
}
