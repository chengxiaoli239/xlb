package com.xlb.vpn.presentation;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class MemberVpnPermissionRecoveryPolicyTest {
    @Test
    public void resynchronizesOnlyWhenApprovedMemberRequestLostItsProcessLocalGrant() {
        assertTrue(MemberVpnPermissionRecoveryPolicy.shouldResynchronize(true, true, false));
        assertFalse(MemberVpnPermissionRecoveryPolicy.shouldResynchronize(true, true, true));
        assertFalse(MemberVpnPermissionRecoveryPolicy.shouldResynchronize(true, false, false));
        assertFalse(MemberVpnPermissionRecoveryPolicy.shouldResynchronize(false, true, false));
    }
}
