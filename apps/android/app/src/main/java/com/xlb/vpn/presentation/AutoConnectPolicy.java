package com.xlb.vpn.presentation;

import com.xlb.vpn.data.MemberTunnelGrant;

final class AutoConnectPolicy {
    private AutoConnectPolicy() {}

    static boolean shouldStart(boolean enabled, boolean vpnPermissionGranted,
                               boolean entitlementActive, MemberTunnelGrant memberGrant,
                               boolean tunnelAlreadyActive) {
        return enabled && vpnPermissionGranted && entitlementActive
                && memberGrant != null && !tunnelAlreadyActive;
    }
}
