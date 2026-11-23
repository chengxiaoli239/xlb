package com.xlb.vpn.tunnel;

import com.xlb.vpn.data.MemberTunnelGrant;

/** Process-local, single-use handoff from the authenticated app to its VPN service. */
public final class MemberTunnelGrantStore {
    private static MemberTunnelGrant pendingGrant;

    private MemberTunnelGrantStore() {}

    public static synchronized void publish(MemberTunnelGrant grant) {
        pendingGrant = grant;
    }

    static synchronized MemberTunnelGrant consume() {
        MemberTunnelGrant grant = pendingGrant;
        pendingGrant = null;
        return grant;
    }

    public static synchronized void clear() {
        pendingGrant = null;
    }
}
