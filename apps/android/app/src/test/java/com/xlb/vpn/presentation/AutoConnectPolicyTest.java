package com.xlb.vpn.presentation;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class AutoConnectPolicyTest {
    @Test
    public void startsOnlyWhenEnabledAndAllStartupRequirementsAreMet() {
        assertTrue(AutoConnectPolicy.shouldStart(true, true, true, true, false));
        assertFalse(AutoConnectPolicy.shouldStart(false, true, true, true, false));
        assertFalse(AutoConnectPolicy.shouldStart(true, false, true, true, false));
        assertFalse(AutoConnectPolicy.shouldStart(true, true, false, true, false));
        assertFalse(AutoConnectPolicy.shouldStart(true, true, true, false, false));
        assertFalse(AutoConnectPolicy.shouldStart(true, true, true, true, true));
    }

    @Test
    public void disabledByDefaultDoesNotStart() {
        assertFalse(AutoConnectPolicy.shouldStart(false, true, true, true, false));
    }
}
