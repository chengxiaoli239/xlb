package com.xlb.vpn.data;

import org.junit.Test;

import java.lang.reflect.Field;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.Locale;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;

public final class MemberTunnelGrantTest {
    private static final String INSTALLATION_ID = "fd3ae0f1-1234-4234-9234-000000000001";
    private static final String DEVICE_ID = "fd3ae0f1-1234-4234-9234-000000000002";
    private static final String PEER_ID = "fd3ae0f1-1234-4234-9234-000000000003";
    private static final String NODE_ID = "fd3ae0f1-1234-4234-9234-000000000004";
    private static final String PUBLIC_KEY = "registered-spki";
    private static final String PROFILE = "mode: global\nproxies:\n  - name: XLB\n    type: socks5\n";
    private static final String CA_BUNDLE = "-----BEGIN CERTIFICATE-----\n"
            + "MIIDFjCCAf6gAwIBAgIUO7FHodXk/3ghZR64+gvzWg+XfKMwDQYJKoZIhvcNAQEL\n"
            + "BQAwIzEhMB8GA1UEAwwYWExCIEFuZHJvaWQgVW5pdCBUZXN0IENBMB4XDTI2MTEy\n"
            + "MzA4MDQ1MFoXDTM2MTEyMDA4MDQ1MFowIzEhMB8GA1UEAwwYWExCIEFuZHJvaWQg\n"
            + "VW5pdCBUZXN0IENBMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAmiRB\n"
            + "4kwHoxSs7goShThuCBIzNkkxdrACCyU4gtU76V2YnZIgNatr55+G2YXg6k1NPBbn\n"
            + "9TLMm1lucZWfBQheAFY4zBEEjqfrVwRDeq3Rl+LjMjhpPAqNbQJm836OI7tWtl6w\n"
            + "22XympuacLKQttyv3tf6I/vxalDxe1OeUq7tmrMChG7mql9f9KX0SOORwUZismpx\n"
            + "Jb90hhvQoLqvFBAc55p+pRyNCBnNQulgZdYkKFQ74yTnEbT3fGMTk5fPVpp+opft\n"
            + "8xZxLN3qxcLrbPUmB6/yA08XsaWislqk0n645E9Qvtphcg5Gi+wM0Lt5MwojkhOo\n"
            + "rLXLGFKh+OcW6Qk7dQIDAQABo0IwQDAdBgNVHQ4EFgQUhc377OOpw/ZyNuKQ8e5g\n"
            + "IdIQK3MwDwYDVR0TAQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMCAQYwDQYJKoZIhvcN\n"
            + "AQELBQADggEBAFldcUUW2+Jkf7feBBjBiaYggtYpTfWYnldJlveS/quKTJ/uDcRF\n"
            + "7gw96ObEPOWm9InNuKminZ26t9nwDue/JhMQNs1D8x9TS/Fp2QmXOnRailyuUi96\n"
            + "jXflA6qTceG4Kam9vg1+BmC81MfJSeAzOBDrdFoWjPVaZM3QbabXnqkQlQOULiYH\n"
            + "mRS6s2PkihVuDvjUUKhtHPZah/Lv9H3RvYVkSVCkPb3gUtOS91Kn1DerPCUzQDwV\n"
            + "LHQ0udxLiMbe5xJM25KXOPCk6vXIWghqcQhOuTcNwmC+3NA/NiIY33vyTkK4HuHW\n"
            + "Mc/jQV1gJGpJeMwr5Syc+0H3i2Aa/pYksCw=\n"
            + "-----END CERTIFICATE-----\n";

    public static MemberTunnelGrant createFixture() throws Exception {
        return createFixtureInternal();
    }

    private static MemberTunnelGrant createFixtureInternal() throws Exception {
        String profileDigest = digest(PROFILE);
        MemberDeviceProof proof = MemberDeviceProof.fromAuthorizedResponse("member-1", INSTALLATION_ID,
                PUBLIC_KEY, DEVICE_ID, "registered", true, "ready", PEER_ID, NODE_ID, 1, 2,
                DeviceBindingProof.encode(new byte[32]), PROFILE, new FakeSigner());
        return MemberTunnelGrant.fromAuthorizedResponse(proof, "ready", NODE_ID, PEER_ID,
                1, 2, "https://node-1.example.invalid:8443", CA_BUNDLE, PROFILE,
                profileDigest, "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true);
    }

    @Test
    public void acceptsOnlyCompleteReadyGrantBoundToCurrentDeviceProof() throws Exception {
        MemberTunnelGrant grant = create("ready", NODE_ID, PEER_ID, 1, 2,
                "https://node-1.example.invalid:8443", CA_BUNDLE, PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true);

        assertNotNull(grant);
        assertEquals(NODE_ID, grant.nodeId());
        assertEquals(PEER_ID, grant.peerId());
        assertEquals("https://node-1.example.invalid:8443", grant.bridgeEndpoint());
        assertEquals(CA_BUNDLE, grant.trustedServerCaPem());
        assertEquals(PROFILE, grant.profile());
        for (Field field : MemberTunnelGrant.class.getDeclaredFields()) {
            assertFalse(field.getName().contains("credential"));
            assertFalse(field.getName().contains("privateKey"));
        }
    }

    @Test
    public void rejectsIncompleteOrMismatchedServerAuthorization() throws Exception {
        assertNull(create("unavailable", NODE_ID, PEER_ID, 1, 2,
                "https://node-1.example.invalid", CA_BUNDLE, PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true));
        assertNull(create("ready", "fd3ae0f1-1234-4234-9234-000000000099", PEER_ID, 1, 2,
                "https://node-1.example.invalid", CA_BUNDLE, PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true));
        assertNull(create("ready", NODE_ID, "fd3ae0f1-1234-4234-9234-000000000099", 1, 2,
                "https://node-1.example.invalid", CA_BUNDLE, PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true));
        assertNull(create("ready", NODE_ID, PEER_ID, 2, 2,
                "https://node-1.example.invalid", CA_BUNDLE, PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true));
        assertNull(create("ready", NODE_ID, PEER_ID, 1, 2,
                "http://node-1.example.invalid", CA_BUNDLE, PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true));
        assertNull(create("ready", NODE_ID, PEER_ID, 1, 2,
                "https://user@node-1.example.invalid", CA_BUNDLE, PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true));
    }

    @Test
    public void rejectsMissingTrustOrUnvalidatedProfileArtifact() throws Exception {
        assertNull(create("ready", NODE_ID, PEER_ID, 1, 2,
                "https://node-1.example.invalid", "", PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true));
        assertNull(create("ready", NODE_ID, PEER_ID, 1, 2,
                "https://node-1.example.invalid", "-----BEGIN CERTIFICATE-----\nAA==\n-----END CERTIFICATE-----",
                PROFILE, digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true));
        assertNull(create("ready", NODE_ID, PEER_ID, 1, 2,
                "https://node-1.example.invalid", CA_BUNDLE, PROFILE,
                "0".repeat(64), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true));
        assertNull(create("ready", NODE_ID, PEER_ID, 1, 2,
                "https://node-1.example.invalid", CA_BUNDLE, PROFILE,
                digest(PROFILE), "unknown-renderer", "1.0.0", "xlb-proof-v1", true));
        assertNull(create("ready", NODE_ID, PEER_ID, 1, 2,
                "https://node-1.example.invalid", CA_BUNDLE, PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "latest", "xlb-proof-v1", true));
        assertNull(create("ready", NODE_ID, PEER_ID, 1, 2,
                "https://node-1.example.invalid", CA_BUNDLE, PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "other", true));
        assertNull(create("ready", NODE_ID, PEER_ID, 1, 2,
                "https://node-1.example.invalid", CA_BUNDLE, PROFILE,
                digest(PROFILE), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", false));
        assertNull(create("ready", NODE_ID, PEER_ID, 1, 2,
                "https://node-1.example.invalid", CA_BUNDLE, "private_key: forbidden\n",
                digest("private_key: forbidden\n"), "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true));
    }

    private MemberTunnelGrant create(String status, String nodeId, String peerId,
            long peerGeneration, long deviceGeneration, String endpoint, String caBundle,
            String profile, String profileDigest, String rendererId, String rendererVersion,
            String bridgeProtocol, boolean connectable) throws Exception {
        MemberDeviceProof proof = MemberDeviceProof.fromAuthorizedResponse("member-1", INSTALLATION_ID,
                PUBLIC_KEY, DEVICE_ID, "registered", true, "ready", PEER_ID, NODE_ID, 1, 2,
                DeviceBindingProof.encode(new byte[32]), PROFILE, new FakeSigner());
        return MemberTunnelGrant.fromAuthorizedResponse(proof, status, nodeId, peerId,
                peerGeneration, deviceGeneration, endpoint, caBundle, profile, profileDigest,
                rendererId, rendererVersion, bridgeProtocol, connectable);
    }

    private static String digest(String value) throws Exception {
        byte[] digest = MessageDigest.getInstance("SHA-256").digest(value.getBytes(StandardCharsets.UTF_8));
        StringBuilder result = new StringBuilder(digest.length * 2);
        for (byte item : digest) result.append(String.format(Locale.ROOT, "%02x", item));
        return result.toString();
    }

    private static final class FakeSigner implements MemberDeviceProof.Signer {
        @Override
        public String publicKeySpkiBase64Url() {
            return PUBLIC_KEY;
        }

        @Override
        public String signChallengeBase64Url(byte[] challenge) {
            return "signed";
        }

        @Override
        public byte[] signDigest(byte[] digest) {
            return digest;
        }
    }
}
