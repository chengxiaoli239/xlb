package com.xlb.vpn.presentation;

final class MemberVpnPermissionRecoveryPolicy {
    private MemberVpnPermissionRecoveryPolicy() {}

    static boolean shouldResynchronize(boolean memberRequest, boolean permissionApproved,
            boolean grantRetainedInProcess) {
        return memberRequest && permissionApproved && !grantRetainedInProcess;
    }
}
