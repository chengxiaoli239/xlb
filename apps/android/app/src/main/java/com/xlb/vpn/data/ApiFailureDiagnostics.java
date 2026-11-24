package com.xlb.vpn.data;

/** Maps allowlisted API error codes and HTTP status boundaries to safe support codes. */
final class ApiFailureDiagnostics {
    private ApiFailureDiagnostics() {}

    static String codeFor(String apiErrorCode, int httpStatus) {
        if ("device_limit_reached".equals(apiErrorCode)) return "device_limit_reached";
        if ("active_entitlement_required".equals(apiErrorCode)) return "membership_required";
        if ("device_registration_revoked".equals(apiErrorCode)) return "device_revoked";
        if ("device_key_mismatch".equals(apiErrorCode)) return "device_identity_mismatch";
        if ("device_binding_challenge_invalid".equals(apiErrorCode)
                || "device_binding_challenge_used".equals(apiErrorCode)
                || "device_binding_challenge_expired".equals(apiErrorCode)
                || "device_binding_proof_invalid".equals(apiErrorCode)) {
            return "device_proof_rejected";
        }
        if (httpStatus == 401 || httpStatus == 419) return "session_expired";
        if (httpStatus == 403) return "authorization_denied";
        if (httpStatus == 404) return "resource_unavailable";
        if (httpStatus == 429) return "rate_limited";
        if (httpStatus >= 500) return "service_unavailable";
        if (httpStatus < 0) return "service_unreachable";

        return "api_request_failed";
    }

    static String codeForAuthorizationStatus(PlatformApi.NodeAuthorizationStatus status) {
        if (status == PlatformApi.NodeAuthorizationStatus.PROVISIONING) return "member_authorization_pending";
        if (status == PlatformApi.NodeAuthorizationStatus.UNAVAILABLE) return "member_authorization_unavailable";
        return null;
    }
}
