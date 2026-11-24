package com.xlb.vpn.data;

import org.junit.Test;

import static org.junit.Assert.assertEquals;

public final class ApiFailureDiagnosticsTest {
    @Test
    public void mapsDeviceAuthorizationApiCodesToStableSupportCodes() {
        assertEquals("device_limit_reached", ApiFailureDiagnostics.codeFor("device_limit_reached", 409));
        assertEquals("membership_required", ApiFailureDiagnostics.codeFor("active_entitlement_required", 403));
        assertEquals("device_revoked", ApiFailureDiagnostics.codeFor("device_registration_revoked", 409));
        assertEquals("device_identity_mismatch", ApiFailureDiagnostics.codeFor("device_key_mismatch", 409));
        assertEquals("device_proof_rejected", ApiFailureDiagnostics.codeFor("device_binding_challenge_expired", 409));
    }

    @Test
    public void mapsHttpBoundariesWithoutExposingUnknownServerCodes() {
        assertEquals("session_expired", ApiFailureDiagnostics.codeFor("unknown_internal_code", 401));
        assertEquals("session_expired", ApiFailureDiagnostics.codeFor(null, 419));
        assertEquals("rate_limited", ApiFailureDiagnostics.codeFor(null, 429));
        assertEquals("service_unavailable", ApiFailureDiagnostics.codeFor("private_exception_name", 503));
        assertEquals("api_request_failed", ApiFailureDiagnostics.codeFor("secret_detail", 400));
        assertEquals("service_unreachable", ApiFailureDiagnostics.codeFor(null, -1));
    }

    @Test
    public void mapsMemberAuthorizationStatesToClientObservedCodes() {
        assertEquals("member_authorization_pending",
                ApiFailureDiagnostics.codeForAuthorizationStatus(PlatformApi.NodeAuthorizationStatus.PROVISIONING));
        assertEquals("member_authorization_unavailable",
                ApiFailureDiagnostics.codeForAuthorizationStatus(PlatformApi.NodeAuthorizationStatus.UNAVAILABLE));
        assertEquals(null,
                ApiFailureDiagnostics.codeForAuthorizationStatus(PlatformApi.NodeAuthorizationStatus.READY));
    }
}
