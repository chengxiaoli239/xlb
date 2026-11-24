package com.xlb.vpn.tunnel;

public final class MemberTunnelAdmissionPolicy {
    public static final boolean MEMBER_PROOF_BRIDGE_INTEGRATED = false;

    private MemberTunnelAdmissionPolicy() {}

    public static boolean mayStart(boolean debugBuild, boolean debugTestRoute) {
        return debugTestRoute ? debugBuild : MEMBER_PROOF_BRIDGE_INTEGRATED;
    }

    public static boolean mayStartMemberTunnel() {
        return MEMBER_PROOF_BRIDGE_INTEGRATED;
    }

    /** Allows only an explicit fixture run in a debug build without changing the release admission gate. */
    public static boolean mayStartMemberTunnel(boolean debugBuild, boolean isolatedTestRequest) {
        if (isolatedTestRequest) return debugBuild;
        return MEMBER_PROOF_BRIDGE_INTEGRATED;
    }
}
