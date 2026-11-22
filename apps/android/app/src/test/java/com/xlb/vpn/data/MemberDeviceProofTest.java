package com.xlb.vpn.data;

import org.junit.Test;

import java.lang.reflect.Field;
import java.security.GeneralSecurityException;
import java.util.Arrays;
import java.util.Collections;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

public final class MemberDeviceProofTest {
    private static final String INSTALLATION_ID = "fd3ae0f1-1234-4234-9234-000000000001";
    private static final String DEVICE_ID = "fd3ae0f1-1234-4234-9234-000000000002";
    private static final String PEER_ID = "fd3ae0f1-1234-4234-9234-000000000003";
    private static final String NODE_ID = "fd3ae0f1-1234-4234-9234-000000000004";
    private static final String PUBLIC_KEY = "registered-spki";
    private static final String PEER_CREDENTIAL = DeviceBindingProof.encode(new byte[32]);
    private static final String PROFILE = "mode: global\nproxy-groups:\n  - name: GLOBAL\n";

    @Test
    public void createsOpaqueProofOnlyFromCompleteReadyBoundPeerResponse() throws Exception {
        MemberDeviceProof proof = create("ready", PEER_CREDENTIAL, PROFILE);

        assertNotNull(proof);
        assertEquals("member-1", proof.memberId());
        assertEquals(INSTALLATION_ID, proof.installationId());
        assertEquals(DEVICE_ID, proof.deviceId());
        assertEquals(PEER_ID, proof.peerId());
        assertEquals(NODE_ID, proof.nodeId());
        assertEquals(1, proof.peerGeneration());
        assertEquals(2, proof.deviceBindingGeneration());
        assertEquals(PUBLIC_KEY, proof.signer().publicKeySpkiBase64Url());
        assertEquals("signed-challenge", proof.signer().signChallengeBase64Url(new byte[32]));
        assertFalse(proof.toString().contains(PEER_CREDENTIAL));
        assertFalse(proof.toString().contains(PROFILE));
        for (Field field : MemberDeviceProof.class.getDeclaredFields()) {
            assertFalse(field.getName().contains("credential"));
            assertFalse(field.getName().contains("profile"));
        }
    }

    @Test
    public void rejectsUnknownOrNonReadyAuthorizationAndUnregisteredDevices() throws Exception {
        assertNull(create("provisioning", PEER_CREDENTIAL, PROFILE));
        assertNull(create("connected", PEER_CREDENTIAL, PROFILE));
        assertNull(create("ready", PEER_CREDENTIAL, PROFILE, "revoked", true));
        assertNull(create("ready", PEER_CREDENTIAL, PROFILE, "registered", false));
    }

    @Test
    public void rejectsMissingIdentityGenerationsCredentialAndProfile() throws Exception {
        assertNull(create("ready", PEER_CREDENTIAL, PROFILE, "registered", true,
                null, NODE_ID, 1, 2, new FakeSigner(PUBLIC_KEY)));
        assertNull(create("ready", PEER_CREDENTIAL, PROFILE, "registered", true,
                PEER_ID, "bad", 1, 2, new FakeSigner(PUBLIC_KEY)));
        assertNull(create("ready", PEER_CREDENTIAL, PROFILE, "registered", true,
                PEER_ID, NODE_ID, 0, 2, new FakeSigner(PUBLIC_KEY)));
        assertNull(create("ready", DeviceBindingProof.encode(new byte[31]), PROFILE, "registered", true));
        assertNull(create("ready", PEER_CREDENTIAL, "", "registered", true));
    }

    @Test
    public void rejectsKeyMismatchAndProfileSecretFields() throws Exception {
        assertNull(create("ready", PEER_CREDENTIAL, PROFILE, "registered", true,
                PEER_ID, NODE_ID, 1, 2, new FakeSigner("other-public-key")));

        String[] secretProfiles = {
                "private_key: forbidden\n",
                "peerCredential: forbidden\n",
                "peer_secret: forbidden\n",
                "subscriptionToken: forbidden\n"
        };
        for (String profile : secretProfiles) {
            assertNull(create("ready", PEER_CREDENTIAL, profile, "registered", true));
        }
    }

    @Test
    public void capabilityMustMatchCurrentRegisteredDeviceSnapshot() throws Exception {
        MemberDeviceProof proof = create("ready", PEER_CREDENTIAL, PROFILE);
        assertNotNull(proof);

        PlatformApi.DeviceSnapshot matching = new PlatformApi.DeviceSnapshot(1, 5, true,
                Collections.singletonList(new PlatformApi.RegisteredDevice(
                        DEVICE_ID, "Android", "android", true, false, INSTALLATION_ID, "registered")));
        PlatformApi.DeviceSnapshot inactiveEntitlement = new PlatformApi.DeviceSnapshot(1, 5, false,
                Collections.singletonList(new PlatformApi.RegisteredDevice(
                        DEVICE_ID, "Android", "android", true, false, INSTALLATION_ID, "registered")));
        PlatformApi.DeviceSnapshot otherInstallation = new PlatformApi.DeviceSnapshot(1, 5, true,
                Collections.singletonList(new PlatformApi.RegisteredDevice(
                        DEVICE_ID, "Android", "android", true, false, "fd3ae0f1-1234-4234-9234-000000000099", "registered")));
        PlatformApi.DeviceSnapshot removed = new PlatformApi.DeviceSnapshot(1, 5, true,
                Collections.singletonList(new PlatformApi.RegisteredDevice(
                        DEVICE_ID, "Android", "android", true, true, INSTALLATION_ID, "registered")));
        PlatformApi.DeviceSnapshot revoked = new PlatformApi.DeviceSnapshot(1, 5, true,
                Collections.singletonList(new PlatformApi.RegisteredDevice(
                        DEVICE_ID, "Android", "android", true, false, INSTALLATION_ID, "revoked")));
        PlatformApi.DeviceSnapshot wrongDevice = new PlatformApi.DeviceSnapshot(1, 5, true,
                Collections.singletonList(new PlatformApi.RegisteredDevice(
                        PEER_ID, "Android", "android", true, false, INSTALLATION_ID, "registered")));

        assertTrue(proof.matches("member-1", matching, true));
        assertFalse(proof.matches("member-2", matching, true));
        assertFalse(proof.matches("member-1", matching, false));
        assertFalse(proof.matches("member-1", inactiveEntitlement, true));
        assertFalse(proof.matches("member-1", otherInstallation, true));
        assertFalse(proof.matches("member-1", removed, true));
        assertFalse(proof.matches("member-1", revoked, true));
        assertFalse(proof.matches("member-1", wrongDevice, true));
    }

    @Test
    public void rejectsOversizedProfileBeforeRetainingAnyProof() throws Exception {
        char[] oversized = new char[128 * 1024 + 1];
        Arrays.fill(oversized, 'x');
        assertNull(create("ready", PEER_CREDENTIAL, new String(oversized)));
    }

    private static MemberDeviceProof create(String status, String credential, String profile,
                                            String deviceStatus, boolean deviceBound) {
        return create(status, credential, profile, deviceStatus, deviceBound,
                PEER_ID, NODE_ID, 1, 2, new FakeSigner(PUBLIC_KEY));
    }

    private static MemberDeviceProof create(String status, String credential, String profile,
                                            String deviceStatus, boolean deviceBound, String peerId,
                                            String nodeId, long peerGeneration, long bindingGeneration,
                                            MemberDeviceProof.Signer signer) {
        return MemberDeviceProof.fromAuthorizedResponse("member-1", INSTALLATION_ID, PUBLIC_KEY,
                DEVICE_ID, deviceStatus, deviceBound, status, peerId, nodeId, peerGeneration,
                bindingGeneration, credential, profile, signer);
    }

    private static MemberDeviceProof create(String status, String credential, String profile) {
        return create(status, credential, profile, "registered", true);
    }

    private static final class FakeSigner implements MemberDeviceProof.Signer {
        private final String publicKey;

        private FakeSigner(String publicKey) {
            this.publicKey = publicKey;
        }

        @Override
        public String publicKeySpkiBase64Url() {
            return publicKey;
        }

        @Override
        public String signChallengeBase64Url(byte[] challenge) {
            return challenge.length == 32 ? "signed-challenge" : "";
        }
    }
}
