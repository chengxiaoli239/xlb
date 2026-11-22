package com.xlb.vpn.data;

import org.junit.Test;

import static org.junit.Assert.assertEquals;

public final class NodeAuthorizationStatusTest {
    @Test
    public void acceptsOnlyKnownAuthorizationStates() {
        assertEquals(PlatformApi.NodeAuthorizationStatus.UNAVAILABLE,
                PlatformApi.NodeAuthorizationStatus.fromStatus("unavailable"));
        assertEquals(PlatformApi.NodeAuthorizationStatus.PROVISIONING,
                PlatformApi.NodeAuthorizationStatus.fromStatus("provisioning"));
        assertEquals(PlatformApi.NodeAuthorizationStatus.READY,
                PlatformApi.NodeAuthorizationStatus.fromStatus("ready"));
    }

    @Test
    public void missingOrUnknownAuthorizationStateFailsClosed() {
        assertEquals(PlatformApi.NodeAuthorizationStatus.UNAVAILABLE,
                PlatformApi.NodeAuthorizationStatus.fromStatus(null));
        assertEquals(PlatformApi.NodeAuthorizationStatus.UNAVAILABLE,
                PlatformApi.NodeAuthorizationStatus.fromStatus(""));
        assertEquals(PlatformApi.NodeAuthorizationStatus.UNAVAILABLE,
                PlatformApi.NodeAuthorizationStatus.fromStatus("READY"));
        assertEquals(PlatformApi.NodeAuthorizationStatus.UNAVAILABLE,
                PlatformApi.NodeAuthorizationStatus.fromStatus("connected"));
    }

    @Test
    public void normalizationReturnsOnlyAClosedStatusValue() {
        PlatformApi.NodeAuthorizationStatus normalized = PlatformApi.NodeAuthorizationStatus.fromStatus("ready");
        assertEquals(PlatformApi.NodeAuthorizationStatus.READY,
                normalized);
    }
}
