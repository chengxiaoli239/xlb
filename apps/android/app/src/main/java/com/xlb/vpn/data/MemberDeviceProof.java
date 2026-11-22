package com.xlb.vpn.data;

import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.util.Locale;
import java.util.regex.Pattern;

/** Opaque server-authorized device capability. Peer credentials and profile content are never retained. */
public final class MemberDeviceProof {
    private static final Pattern UUID = Pattern.compile(
            "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$");
    private static final int MAX_PROFILE_BYTES = 128 * 1024;

    public interface Signer {
        String publicKeySpkiBase64Url() throws GeneralSecurityException;
        String signChallengeBase64Url(byte[] challenge) throws GeneralSecurityException;
    }

    private final String memberId;
    private final String installationId;
    private final String deviceId;
    private final String peerId;
    private final String nodeId;
    private final long peerGeneration;
    private final long deviceBindingGeneration;
    private final String registeredPublicKey;
    private final Signer signer;

    private MemberDeviceProof(String memberId, String installationId, String deviceId, String peerId,
                              String nodeId, long peerGeneration, long deviceBindingGeneration,
                              String registeredPublicKey, Signer signer) {
        this.memberId = memberId;
        this.installationId = installationId;
        this.deviceId = deviceId;
        this.peerId = peerId;
        this.nodeId = nodeId;
        this.peerGeneration = peerGeneration;
        this.deviceBindingGeneration = deviceBindingGeneration;
        this.registeredPublicKey = registeredPublicKey;
        this.signer = signer;
    }

    static MemberDeviceProof fromAuthorizedResponse(String memberId, String installationId,
            String requestedPublicKey, String deviceId, String deviceStatus, boolean deviceBound,
            String authorizationStatus, String peerId, String nodeId, long peerGeneration,
            long deviceBindingGeneration, String credential, String profile, Signer signer) {
        try {
            byte[] credentialBytes = DeviceBindingProof.decode(credential);

            if (!"ready".equals(authorizationStatus)
                    || memberId == null || memberId.trim().isEmpty()
                    || !UUID.matcher(installationId == null ? "" : installationId).matches()
                    || !UUID.matcher(deviceId).matches()
                    || !"registered".equals(deviceStatus)
                    || !deviceBound
                    || !UUID.matcher(peerId).matches()
                    || !UUID.matcher(nodeId).matches()
                    || peerGeneration <= 0
                    || deviceBindingGeneration <= 0
                    || credentialBytes.length != 32
                    || !DeviceBindingProof.encode(credentialBytes).equals(credential)
                    || profile.isEmpty()
                    || profile.getBytes(StandardCharsets.UTF_8).length > MAX_PROFILE_BYTES
                    || containsDeviceSecretField(profile)
                    || requestedPublicKey == null
                    || requestedPublicKey.isEmpty()
                    || !requestedPublicKey.equals(signer.publicKeySpkiBase64Url())) {
                return null;
            }

            return new MemberDeviceProof(memberId, installationId, deviceId, peerId, nodeId,
                    peerGeneration, deviceBindingGeneration, requestedPublicKey, signer);
        } catch (Exception exception) {
            return null;
        }
    }

    public boolean hasRequiredReferences() {
        if (!present(memberId) || !UUID.matcher(installationId).matches()
                || !UUID.matcher(deviceId).matches() || !UUID.matcher(peerId).matches()
                || !UUID.matcher(nodeId).matches() || peerGeneration <= 0
                || deviceBindingGeneration <= 0 || !present(registeredPublicKey) || signer == null) {
            return false;
        }
        try {
            return registeredPublicKey.equals(signer.publicKeySpkiBase64Url());
        } catch (GeneralSecurityException exception) {
            return false;
        }
    }

    boolean matches(String accountId, PlatformApi.DeviceSnapshot snapshot, boolean activeEntitlementPresent) {
        if (!memberId.equals(accountId) || snapshot == null || !activeEntitlementPresent
                || !snapshot.entitlementActive
                || snapshot.devices == null) {
            return false;
        }
        boolean devicePresent = false;
        for (PlatformApi.RegisteredDevice device : snapshot.devices) {
            if (deviceId.equals(device.id) && installationId.equals(device.installationId)
                    && device.current && !device.removalPending && "registered".equals(device.status)
                    && "android".equals(device.platform)) {
                devicePresent = true;
                break;
            }
        }
        if (!devicePresent) {
            return false;
        }
        try {
            return registeredPublicKey.equals(signer.publicKeySpkiBase64Url());
        } catch (GeneralSecurityException exception) {
            return false;
        }
    }

    public String memberId() { return memberId; }
    public String installationId() { return installationId; }
    public String deviceId() { return deviceId; }
    public String peerId() { return peerId; }
    public String nodeId() { return nodeId; }
    public long peerGeneration() { return peerGeneration; }
    public long deviceBindingGeneration() { return deviceBindingGeneration; }
    public Signer signer() { return signer; }

    @Override
    public String toString() {
        return "MemberDeviceProof[verified]";
    }

    private static boolean containsDeviceSecretField(String profile) {
        String normalized = profile.toLowerCase(Locale.ROOT).replace("_", "").replace("-", "");
        for (String field : new String[]{"privatekey:", "peercredential:", "peersecret:", "subscriptiontoken:"}) {
            if (normalized.contains(field)) {
                return true;
            }
        }
        return false;
    }

    private static boolean present(String value) {
        return value != null && !value.trim().isEmpty();
    }
}
