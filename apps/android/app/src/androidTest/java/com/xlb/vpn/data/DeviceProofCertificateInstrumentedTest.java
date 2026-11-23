package com.xlb.vpn.data;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;
import static org.junit.Assert.fail;

import android.os.Build;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;

import org.json.JSONObject;
import org.junit.Test;
import org.junit.runner.RunWith;

import java.io.ByteArrayInputStream;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.KeyFactory;
import java.security.KeyStore;
import java.security.PublicKey;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;
import java.security.spec.X509EncodedKeySpec;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.UUID;

@RunWith(AndroidJUnit4.class)
public final class DeviceProofCertificateInstrumentedTest {
    private static final String KEY_STORE = "AndroidKeyStore";
    private static final String KEY_ALIAS_PREFIX = "xlb-device-p256-v1-";
    private static final String DEVICE_IDENTITY_OID = "1.3.6.1.4.1.57264.1.1";
    private static final String DEVICE_ID = "81000000-0000-4000-8000-000000000001";
    private static final String PEER_ID = "81000000-0000-4000-8000-000000000002";
    private static final String NODE_ID = "81000000-0000-4000-8000-000000000003";

    @Test
    public void keystoreSignerCreatesIdentityBoundCertificateAndHasExplicitLifetime() throws Exception {
        requireDisposableEmulator();
        String installationId = UUID.randomUUID().toString();
        String alias = KEY_ALIAS_PREFIX + installationId;
        DeviceKeyStore deviceKeyStore = new DeviceKeyStore();
        KeyStore keyStore = KeyStore.getInstance(KEY_STORE);
        DeviceProofCertificate certificate = null;

        try {
            String publicKey = deviceKeyStore.publicKey(installationId);
            MemberDeviceProof proof = authorizedProof(installationId, publicKey, deviceKeyStore,
                    digest -> new byte[]{1, 2, 3});

            try {
                DeviceProofCertificate.create(proof);
                fail("Invalid Android signature must not produce a device certificate");
            } catch (GeneralSecurityException expected) {
                assertTrue(expected.getMessage().contains("certificate"));
            }

            certificate = DeviceProofCertificate.create(authorizedProof(installationId, publicKey,
                    deviceKeyStore, digest -> deviceKeyStore.signDigestBytes(installationId, digest)));
            assertNotNull(certificate);
            X509Certificate parsed = parseCertificate(certificate.encodedCertificate());
            Instant now = Instant.now();
            assertTrue(parsed.getNotBefore().toInstant().isBefore(now.plus(1, ChronoUnit.MINUTES)));
            assertTrue(parsed.getNotAfter().toInstant().isAfter(now));
            assertTrue(parsed.getNotAfter().toInstant().isBefore(now.plus(11, ChronoUnit.MINUTES)));
            PublicKey registeredKey = KeyFactory.getInstance("EC").generatePublic(
                    new X509EncodedKeySpec(DeviceBindingProof.decode(publicKey)));
            parsed.verify(registeredKey);
            assertTrue(parsed.getCriticalExtensionOIDs().contains(DEVICE_IDENTITY_OID));
            assertEquals(PEER_ID, parsed.getSubjectX500Principal().getName().replace("CN=", ""));

            JSONObject identity = new JSONObject(new String(
                    unwrapOctetString(parsed.getExtensionValue(DEVICE_IDENTITY_OID)), StandardCharsets.UTF_8));
            assertEquals(NODE_ID, identity.getString("node_id"));
            assertEquals(PEER_ID, identity.getString("peer_id"));
            assertEquals(DEVICE_ID, identity.getString("device_id"));
            assertEquals(3, identity.getInt("peer_generation"));
            assertEquals(7, identity.getInt("device_generation"));

            try {
                DeviceProofCertificate.create(proof);
                fail("A second Keystore signer lease must be rejected while the first is active");
            } catch (GeneralSecurityException expected) {
                assertTrue(expected.getMessage().contains("signer"));
            }
        } finally {
            if (certificate != null) certificate.close();
            keyStore.load(null);
            if (keyStore.containsAlias(alias)) keyStore.deleteEntry(alias);
        }

        DeviceProofCertificate afterClose = DeviceProofCertificate.create(authorizedProof(installationId,
                deviceKeyStore.publicKey(installationId), deviceKeyStore,
                digest -> deviceKeyStore.signDigestBytes(installationId, digest)));
        try {
            assertNotNull(afterClose.encodedCertificate());
        } finally {
            afterClose.close();
            keyStore.load(null);
            if (keyStore.containsAlias(alias)) keyStore.deleteEntry(alias);
        }
    }

    private static MemberDeviceProof authorizedProof(String installationId, String publicKey,
            DeviceKeyStore keyStore, DigestSigner digestSigner) {
        MemberDeviceProof.Signer signer = new MemberDeviceProof.Signer() {
            @Override
            public String publicKeySpkiBase64Url() {
                return publicKey;
            }

            @Override
            public String signChallengeBase64Url(byte[] challenge) throws GeneralSecurityException {
                return keyStore.sign(installationId, DeviceBindingProof.encode(challenge));
            }

            @Override
            public byte[] signDigest(byte[] digest) throws GeneralSecurityException {
                return digestSigner.sign(digest);
            }
        };
        return MemberDeviceProof.fromAuthorizedResponse("member-1", installationId, publicKey,
                DEVICE_ID, "registered", true, "ready", PEER_ID, NODE_ID, 3, 7,
                DeviceBindingProof.encode(new byte[32]), "proxy-groups: []\n", signer);
    }

    private static X509Certificate parseCertificate(byte[] encoded) throws Exception {
        return (X509Certificate) CertificateFactory.getInstance("X.509")
                .generateCertificate(new ByteArrayInputStream(encoded));
    }

    private static byte[] unwrapOctetString(byte[] encoded) {
        if (encoded == null || encoded.length < 2 || encoded[0] != 0x04) {
            throw new IllegalArgumentException("X.509 extension wrapper is invalid");
        }
        int offset = 2;
        int length = encoded[1] & 0xff;
        if ((length & 0x80) != 0) {
            int lengthBytes = length & 0x7f;
            if (lengthBytes < 1 || lengthBytes > 4 || encoded.length < 2 + lengthBytes) {
                throw new IllegalArgumentException("X.509 extension length is invalid");
            }
            length = 0;
            for (int index = 0; index < lengthBytes; index++) {
                length = (length << 8) | (encoded[offset++] & 0xff);
            }
        }
        if (length != encoded.length - offset) {
            throw new IllegalArgumentException("X.509 extension payload length does not match");
        }
        byte[] payload = new byte[length];
        System.arraycopy(encoded, offset, payload, 0, length);
        return payload;
    }

    private static void requireDisposableEmulator() {
        String hardware = android.os.Build.HARDWARE;
        String fingerprint = Build.FINGERPRINT.toLowerCase();
        assertTrue("Device certificate instrumentation requires the dedicated emulator",
                "ranchu".equals(hardware) || "goldfish".equals(hardware) || fingerprint.contains("sdk_gphone"));
        assertEquals("Set -e disposable_avd true only for a disposable emulator", "true",
                InstrumentationRegistry.getArguments().getString("disposable_avd", "false"));
    }

    private interface DigestSigner {
        byte[] sign(byte[] digest) throws GeneralSecurityException;
    }
}
