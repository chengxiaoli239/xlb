package com.xlb.vpn.presentation;

import com.xlb.vpn.tunnel.MemberTunnelAdmissionPolicy;
import com.xlb.vpn.data.MemberTunnelGrant;
import com.xlb.vpn.data.MemberTunnelGrantTestFixture;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class AutoConnectPolicyTest {
    @Test
    public void startsOnlyWhenEnabledAndAllStartupRequirementsAreMet() {
        MemberTunnelGrant grant = MemberTunnelGrantTestFixture.create();
        assertTrue(AutoConnectPolicy.shouldStart(true, true, true, grant, false));
        assertFalse(AutoConnectPolicy.shouldStart(false, true, true, grant, false));
        assertFalse(AutoConnectPolicy.shouldStart(true, false, true, grant, false));
        assertFalse(AutoConnectPolicy.shouldStart(true, true, false, grant, false));
        assertFalse(AutoConnectPolicy.shouldStart(true, true, true, null, false));
        assertFalse(AutoConnectPolicy.shouldStart(true, true, true, grant, true));
    }

    @Test
    public void disabledByDefaultDoesNotStart() {
        assertFalse(AutoConnectPolicy.shouldStart(false, true, true,
                MemberTunnelGrantTestFixture.create(), false));
    }

    @Test
    public void genericTunnelAdmitsOnlyDebugTestProfilesUntilMemberBridgeIsIntegrated() {
        assertTrue(MemberTunnelAdmissionPolicy.mayStart(true, true));
        assertFalse(MemberTunnelAdmissionPolicy.mayStart(false, true));
        assertFalse(MemberTunnelAdmissionPolicy.mayStart(true, false));
        assertFalse(MemberTunnelAdmissionPolicy.mayStart(false, false));
        assertFalse(MemberTunnelAdmissionPolicy.MEMBER_PROOF_BRIDGE_INTEGRATED);
        assertFalse(MemberTunnelAdmissionPolicy.mayStartMemberTunnel());
        assertTrue(MemberTunnelAdmissionPolicy.mayStartMemberTunnel(true, true));
        assertFalse(MemberTunnelAdmissionPolicy.mayStartMemberTunnel(false, true));
        assertFalse(MemberTunnelAdmissionPolicy.mayStartMemberTunnel(true, false));
    }
}
