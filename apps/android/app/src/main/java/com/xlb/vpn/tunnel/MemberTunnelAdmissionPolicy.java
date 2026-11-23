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
}
