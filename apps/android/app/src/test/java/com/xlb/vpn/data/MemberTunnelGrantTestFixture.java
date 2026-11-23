package com.xlb.vpn.data;

public final class MemberTunnelGrantTestFixture {
    private MemberTunnelGrantTestFixture() {}

    public static MemberTunnelGrant create() {
        try {
            return MemberTunnelGrantTest.createFixture();
        } catch (Exception exception) {
            throw new IllegalStateException("test member grant could not be constructed", exception);
        }
    }
}
