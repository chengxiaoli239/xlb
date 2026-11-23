package com.xlb.vpn.data;

import static org.junit.Assert.assertArrayEquals;
import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;
import static org.junit.Assert.fail;

import android.os.Build;
import android.util.Base64;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;

import org.json.JSONObject;
import org.junit.Test;
import org.junit.runner.RunWith;

import java.io.ByteArrayInputStream;
import java.io.File;
import java.net.Socket;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.KeyFactory;
import java.security.KeyStore;
import java.security.MessageDigest;
import java.security.PublicKey;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;
import java.security.spec.X509EncodedKeySpec;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.Enumeration;
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

    @Test
    public void keystoreSignerStartsAuthenticatedNativeProofBridgeOnLoopback() throws Exception {
        requireDisposableEmulator();
        String installationId = UUID.randomUUID().toString();
        String alias = KEY_ALIAS_PREFIX + installationId;
        DeviceKeyStore deviceKeyStore = new DeviceKeyStore();
        KeyStore keyStore = KeyStore.getInstance(KEY_STORE);
        DeviceProofCertificate certificate = null;
        DeviceProofCertificate.BridgeCredentials credentials = null;

        try {
            String publicKey = deviceKeyStore.publicKey(installationId);
            MemberDeviceProof proof = authorizedProof(installationId, publicKey, deviceKeyStore,
                    digest -> deviceKeyStore.signDigestBytes(installationId, digest));
            certificate = DeviceProofCertificate.create(proof);
            credentials = certificate.startProofBridge("https://127.0.0.1:1", trustedNodeCaBundle());

            assertTrue(certificate.proofBridgeRunning());
            try (Socket unauthenticated = new Socket("127.0.0.1", 17890)) {
                unauthenticated.setSoTimeout(2000);
                unauthenticated.getOutputStream().write(new byte[]{5, 1, 0});
                assertEquals(5, unauthenticated.getInputStream().read());
                assertEquals(0xff, unauthenticated.getInputStream().read());
            }

            try (Socket authenticated = new Socket("127.0.0.1", 17890)) {
                authenticated.setSoTimeout(2000);
                authenticated.getOutputStream().write(new byte[]{5, 1, 2});
                assertEquals(5, authenticated.getInputStream().read());
                assertEquals(2, authenticated.getInputStream().read());
                writeSocksUserPassword(authenticated, credentials);
                assertEquals(1, authenticated.getInputStream().read());
                assertEquals(0, authenticated.getInputStream().read());
            }

            certificate.stopProofBridge();
            assertFalse(certificate.proofBridgeRunning());
        } finally {
            if (certificate != null) certificate.close();
            keyStore.load(null);
            if (keyStore.containsAlias(alias)) keyStore.deleteEntry(alias);
        }
    }

    @Test
    public void keystoreSignerConnectsThroughNativeBridgeToAuthorizedIsolatedNodePeer() throws Exception {
        requireDisposableEmulator();
        String installationId = UUID.randomUUID().toString();
        String alias = KEY_ALIAS_PREFIX + installationId;
        String copiedInstallationId = UUID.randomUUID().toString();
        String copiedAlias = KEY_ALIAS_PREFIX + copiedInstallationId;
        String publicKey = new DeviceKeyStore().publicKey(installationId);
        String stateDirectory = new File(
                InstrumentationRegistry.getInstrumentation().getTargetContext().getCacheDir(),
                "isolated-node-agent-" + UUID.randomUUID()).getAbsolutePath();
        JSONObject request = new JSONObject()
                .put("node_id", NODE_ID)
                .put("peer_id", PEER_ID)
                .put("entitlement_id", "81000000-0000-4000-8000-000000000004")
                .put("request_id", "81000000-0000-4000-8000-000000000005")
                .put("generation", 3)
                .put("device_id", DEVICE_ID)
                .put("device_name", "Disposable AVD proof bridge")
                .put("device_public_key", publicKey)
                .put("device_key_fingerprint", fingerprint(DeviceBindingProof.decode(publicKey)))
                .put("device_binding_generation", 7)
                .put("peer_generation", 3)
                .put("device_generation", 7)
                .put("expires_at", Instant.now().plus(1, ChronoUnit.HOURS).toString())
                .put("credential", "isolated-test-credential")
                .put("state_path", stateDirectory + "/peer-store.json");

        KeyStore keyStore = KeyStore.getInstance(KEY_STORE);
        DeviceProofCertificate certificate = null;
        DeviceProofCertificate.BridgeCredentials credentials = null;
        File stateDirectoryFile = new File(stateDirectory);
        assertTrue(stateDirectoryFile.mkdirs());
        try {
            JSONObject fixture = new JSONObject(DeviceProofJni.startNodeAgentFixture(request.toString()));
            assertFalse("isolated Node Agent fixture did not start", fixture.has("error"));
            MemberDeviceProof proof = authorizedProof(installationId, publicKey, new DeviceKeyStore(),
                    digest -> new DeviceKeyStore().signDigestBytes(installationId, digest));
            certificate = DeviceProofCertificate.create(proof);
            credentials = certificate.startProofBridge(fixture.getString("endpoint"), fixture.getString("ca_pem"));
            assertTrue(certificate.proofBridgeRunning());

            try (Socket connection = new Socket("127.0.0.1", 17890)) {
                connection.setSoTimeout(5000);
                negotiateSocksUserPassword(connection, credentials);
                byte[] host = "bridge.test".getBytes(StandardCharsets.US_ASCII);
                connection.getOutputStream().write(new byte[]{5, 1, 0, 3, (byte) host.length});
                connection.getOutputStream().write(host);
                connection.getOutputStream().write(new byte[]{1, (byte) 187});
                byte[] connectReply = new byte[10];
                readFully(connection, connectReply);
                assertEquals("Node Agent did not authorize this installation key: "
                        + DeviceProofJni.nodeAgentFixtureStatus(), 0, connectReply[1]);

                byte[] payload = "Android Keystore proof bridge integration".getBytes(StandardCharsets.UTF_8);
                connection.getOutputStream().write(payload);
                byte[] response = new byte[payload.length];
                readFully(connection, response);
                assertArrayEquals(payload, response);
            }

            assertTrue(certificate.proofBridgeRunning());

            certificate.close();
            certificate = null;

            DeviceKeyStore copiedDeviceKeyStore = new DeviceKeyStore();
            String copiedPublicKey = copiedDeviceKeyStore.publicKey(copiedInstallationId);
            MemberDeviceProof copiedProof = authorizedProof(copiedInstallationId, copiedPublicKey,
                    copiedDeviceKeyStore,
                    digest -> copiedDeviceKeyStore.signDigestBytes(copiedInstallationId, digest));
            certificate = DeviceProofCertificate.create(copiedProof);
            credentials = certificate.startProofBridge(fixture.getString("endpoint"), fixture.getString("ca_pem"));
            try (Socket copiedConnection = new Socket("127.0.0.1", 17890)) {
                copiedConnection.setSoTimeout(5000);
                negotiateSocksUserPassword(copiedConnection, credentials);
                byte[] host = "bridge.test".getBytes(StandardCharsets.US_ASCII);
                copiedConnection.getOutputStream().write(new byte[]{5, 1, 0, 3, (byte) host.length});
                copiedConnection.getOutputStream().write(host);
                copiedConnection.getOutputStream().write(new byte[]{1, (byte) 187});
                byte[] copiedReply = new byte[10];
                readFully(copiedConnection, copiedReply);
                assertEquals("Node Agent accepted a copied peer identity", 5, copiedReply[1]);
                JSONObject rejection = new JSONObject(DeviceProofJni.nodeAgentFixtureStatus());
                assertEquals(403, rejection.getInt("status"));
                assertTrue(rejection.getString("body").contains("unauthorized"));
            }

            certificate.close();
            certificate = DeviceProofCertificate.create(proof);
            credentials = certificate.startProofBridge(fixture.getString("endpoint"), fixture.getString("ca_pem"));
            try (Socket revocationConnection = new Socket("127.0.0.1", 17890)) {
                revocationConnection.setSoTimeout(5000);
                negotiateSocksUserPassword(revocationConnection, credentials);
                byte[] host = "bridge.test".getBytes(StandardCharsets.US_ASCII);
                revocationConnection.getOutputStream().write(new byte[]{5, 1, 0, 3, (byte) host.length});
                revocationConnection.getOutputStream().write(host);
                revocationConnection.getOutputStream().write(new byte[]{1, (byte) 187});
                byte[] connectReply = new byte[10];
                readFully(revocationConnection, connectReply);
                assertEquals("Node Agent did not authorize the peer before revocation", 0, connectReply[1]);
                byte[] payload = "final measured bytes".getBytes(StandardCharsets.UTF_8);
                revocationConnection.getOutputStream().write(payload);
                byte[] response = new byte[payload.length];
                readFully(revocationConnection, response);
                assertArrayEquals(payload, response);

                JSONObject revocation = new JSONObject(DeviceProofJni.revokeNodeAgentFixturePeer());
                assertFalse("isolated Node Agent did not confirm revocation", revocation.has("error"));
                assertEquals("confirmed_test", revocation.getString("confirmation_status"));
                assertTrue(revocation.getLong("upload_bytes") > 0);
                assertTrue(revocation.getLong("download_bytes") > 0);
                assertEquals(-1, revocationConnection.getInputStream().read());
            }
            try (Socket revokedConnection = new Socket("127.0.0.1", 17890)) {
                revokedConnection.setSoTimeout(5000);
                negotiateSocksUserPassword(revokedConnection, credentials);
                byte[] host = "bridge.test".getBytes(StandardCharsets.US_ASCII);
                revokedConnection.getOutputStream().write(new byte[]{5, 1, 0, 3, (byte) host.length});
                revokedConnection.getOutputStream().write(host);
                revokedConnection.getOutputStream().write(new byte[]{1, (byte) 187});
                byte[] revokedReply = new byte[10];
                readFully(revokedConnection, revokedReply);
                assertEquals("Node Agent accepted a revoked peer", 5, revokedReply[1]);
                JSONObject rejection = new JSONObject(DeviceProofJni.nodeAgentFixtureStatus());
                assertEquals(403, rejection.getInt("status"));
                assertTrue(rejection.getString("body").contains("unauthorized"));
            }
        } finally {
            if (certificate != null) certificate.close();
            DeviceProofJni.stopNodeAgentFixture();
            keyStore.load(null);
            if (keyStore.containsAlias(alias)) keyStore.deleteEntry(alias);
            if (keyStore.containsAlias(copiedAlias)) keyStore.deleteEntry(copiedAlias);
            deleteRecursively(stateDirectoryFile);
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

    private static String trustedNodeCaBundle() throws Exception {
        KeyStore systemRoots = KeyStore.getInstance("AndroidCAStore");
        systemRoots.load(null);
        Enumeration<String> aliases = systemRoots.aliases();
        while (aliases.hasMoreElements()) {
            java.security.cert.Certificate candidate = systemRoots.getCertificate(aliases.nextElement());
            if (!(candidate instanceof X509Certificate)) continue;
            X509Certificate certificate = (X509Certificate) candidate;
            boolean[] keyUsage = certificate.getKeyUsage();
            if (certificate.getBasicConstraints() < 0 || keyUsage == null || keyUsage.length <= 5 || !keyUsage[5]) {
                continue;
            }
            try {
                certificate.checkValidity();
            } catch (java.security.cert.CertificateExpiredException | java.security.cert.CertificateNotYetValidException ignored) {
                continue;
            }
            String encoded = Base64.encodeToString(certificate.getEncoded(), Base64.NO_WRAP);
            return "-----BEGIN CERTIFICATE-----\n" + encoded + "\n-----END CERTIFICATE-----\n";
        }
        throw new GeneralSecurityException("Android system trust store has no currently valid CA certificate");
    }

    private static String fingerprint(byte[] publicKey) throws GeneralSecurityException {
        byte[] digest = MessageDigest.getInstance("SHA-256").digest(publicKey);
        StringBuilder encoded = new StringBuilder(digest.length * 2);
        for (byte value : digest) {
            encoded.append(String.format(java.util.Locale.ROOT, "%02x", value & 0xff));
        }
        return encoded.toString();
    }

    private static void negotiateSocksUserPassword(
            Socket socket, DeviceProofCertificate.BridgeCredentials credentials) throws Exception {
        socket.getOutputStream().write(new byte[]{5, 1, 2});
        assertEquals(5, socket.getInputStream().read());
        assertEquals(2, socket.getInputStream().read());
        byte[] username = credentials.usernameForProfile().getBytes(StandardCharsets.UTF_8);
        byte[] password = credentials.passwordForProfile().getBytes(StandardCharsets.UTF_8);
        socket.getOutputStream().write(new byte[]{1, (byte) username.length});
        socket.getOutputStream().write(username);
        socket.getOutputStream().write(new byte[]{(byte) password.length});
        socket.getOutputStream().write(password);
        assertEquals(1, socket.getInputStream().read());
        assertEquals(0, socket.getInputStream().read());
    }

    private static void readFully(Socket socket, byte[] buffer) throws Exception {
        int offset = 0;
        while (offset < buffer.length) {
            int count = socket.getInputStream().read(buffer, offset, buffer.length - offset);
            if (count < 0) throw new java.io.IOException("unexpected end of proof bridge response");
            offset += count;
        }
    }

    private static void deleteRecursively(File file) {
        if (file.isDirectory()) {
            File[] children = file.listFiles();
            if (children != null) {
                for (File child : children) deleteRecursively(child);
            }
        }
        if (file.exists()) file.delete();
    }

    private static void writeSocksUserPassword(Socket socket, DeviceProofCertificate.BridgeCredentials credentials)
            throws Exception {
        byte[] username = credentials.usernameForProfile().getBytes(StandardCharsets.UTF_8);
        byte[] password = credentials.passwordForProfile().getBytes(StandardCharsets.UTF_8);
        socket.getOutputStream().write(new byte[]{1, (byte) username.length});
        socket.getOutputStream().write(username);
        socket.getOutputStream().write(new byte[]{(byte) password.length});
        socket.getOutputStream().write(password);
        socket.getOutputStream().flush();
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
