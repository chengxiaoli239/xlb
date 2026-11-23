package com.xlb.vpn.tunnel;

import com.xlb.vpn.data.MemberTunnelGrant;

/** Process-local, single-use handoff from the authenticated app to its VPN service. */
public final class MemberTunnelGrantStore {
    private static MemberTunnelGrant pendingGrant;
    private static long nextHandoffId;
    private static long pendingHandoffId;

    private MemberTunnelGrantStore() {}

    public static synchronized long publish(MemberTunnelGrant grant) {
        if (grant == null) throw new IllegalArgumentException("verified member tunnel grant is required");
        pendingHandoffId = ++nextHandoffId;
        pendingGrant = grant;
        return pendingHandoffId;
    }

    static synchronized MemberTunnelGrant consume(long handoffId) {
        if (handoffId <= 0 || handoffId != pendingHandoffId) return null;
        MemberTunnelGrant grant = pendingGrant;
        pendingGrant = null;
        pendingHandoffId = 0;
        return grant;
    }

    static synchronized boolean hasPendingHandoff() {
        return pendingGrant != null;
    }

    public static synchronized void clear(long handoffId) {
        if (handoffId <= 0 || handoffId != pendingHandoffId) return;
        pendingGrant = null;
        pendingHandoffId = 0;
    }

    public static synchronized void clear() {
        pendingGrant = null;
        pendingHandoffId = 0;
    }
}
