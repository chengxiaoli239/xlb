package com.xlb.vpn.data;

import org.junit.Test;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertSame;

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

    @Test
    public void overviewEnrichmentPreservesProvisioningAuthorization() {
        assertAuthorizationSurvivesOverview(PlatformApi.NodeAuthorizationStatus.PROVISIONING);
    }

    @Test
    public void overviewEnrichmentPreservesReadyAuthorization() {
        assertAuthorizationSurvivesOverview(PlatformApi.NodeAuthorizationStatus.READY);
    }

    private void assertAuthorizationSurvivesOverview(PlatformApi.NodeAuthorizationStatus status) {
        PlatformApi.MemberAccount account = new PlatformApi.MemberAccount("member-1", "Member", "", "member");
        PlatformApi.DeviceSnapshot device = new PlatformApi.DeviceSnapshot(1, 5, true, java.util.Collections.emptyList());
        PlatformApi.ClientSnapshot current = new PlatformApi.ClientSnapshot(
                account, device, null, null, 0, null, status);
        PlatformApi.EntitlementSummary entitlement = new PlatformApi.EntitlementSummary(
                "Annual", "2026-01-01", "2027-01-01", 1500, 10, 5);

        PlatformApi.ClientSnapshot enriched = current.withOverview(entitlement, 2);

        assertSame(account, enriched.account);
        assertSame(device, enriched.device);
        assertSame(entitlement, enriched.entitlement);
        assertEquals(2, enriched.subscriptionCount);
        assertEquals(status, enriched.nodeAuthorizationStatus);
    }
}
