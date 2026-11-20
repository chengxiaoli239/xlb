package com.xlb.vpn.presentation;

final class AutoConnectPolicy {
    private AutoConnectPolicy() {}

    static boolean shouldStart(boolean enabled, boolean vpnPermissionGranted,
                               boolean entitlementActive, boolean accountRouteAvailable,
                               boolean tunnelAlreadyActive) {
        return enabled && vpnPermissionGranted && entitlementActive
                && accountRouteAvailable && !tunnelAlreadyActive;
    }
}
