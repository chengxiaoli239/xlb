package com.xlb.vpn.data;

import android.os.Build;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;

import org.json.JSONObject;
import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;

import java.io.FileOutputStream;
import java.security.KeyFactory;
import java.security.KeyPairGenerator;
import java.security.KeyStore;
import java.security.MessageDigest;
import java.security.PrivateKey;
import java.security.PublicKey;
import java.security.SecureRandom;
import java.security.Signature;
import java.security.interfaces.ECPublicKey;
import java.security.spec.ECGenParameterSpec;
import java.security.spec.X509EncodedKeySpec;
import java.util.Locale;
import java.util.UUID;
import java.nio.charset.StandardCharsets;

import static org.junit.Assert.assertArrayEquals;
import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;
import static org.junit.Assert.fail;

@RunWith(AndroidJUnit4.class)
public final class DeviceKeyStoreInstrumentedTest {
    private static final String KEY_STORE = "AndroidKeyStore";
    private static final String KEY_ALIAS_PREFIX = "xlb-device-p256-v1-";

    @Before
    public void requireDisposableEmulator() {
        String hardware = Build.HARDWARE == null ? "" : Build.HARDWARE.toLowerCase(Locale.ROOT);
        String fingerprint = Build.FINGERPRINT == null ? "" : Build.FINGERPRINT.toLowerCase(Locale.ROOT);
        boolean emulator = "ranchu".equals(hardware)
                || "goldfish".equals(hardware)
                || fingerprint.contains("sdk_gphone");
        assertTrue("DeviceKeyStore instrumentation may only run on an Android emulator", emulator);
        assertEquals(
                "Set -e disposable_avd true only for a disposable emulator",
                "true",
                InstrumentationRegistry.getArguments().getString("disposable_avd", "false")
        );
    }

    @Test
    public void keysAreInstallationScopedAndPrivateKeyIsNotExportable() throws Exception {
        String firstInstallationId = "instrumented-" + UUID.randomUUID();
        String secondInstallationId = "instrumented-" + UUID.randomUUID();
        String firstAlias = KEY_ALIAS_PREFIX + firstInstallationId;
        String secondAlias = KEY_ALIAS_PREFIX + secondInstallationId;
        KeyStore keyStore = KeyStore.getInstance(KEY_STORE);
        DeviceKeyStore deviceKeyStore = new DeviceKeyStore();

        try {
            String firstPublicKey = deviceKeyStore.publicKey(firstInstallationId);
            assertEquals(firstPublicKey, deviceKeyStore.publicKey(firstInstallationId));

            String secondPublicKey = deviceKeyStore.publicKey(secondInstallationId);
            assertNotEquals(firstPublicKey, secondPublicKey);

            keyStore.load(null);
            PrivateKey firstPrivateKey = (PrivateKey) keyStore.getKey(firstAlias, null);
            assertNotNull(firstPrivateKey);
            assertNull("AndroidKeyStore must not export the private key", firstPrivateKey.getEncoded());

            byte[] challenge = new byte[32];
            new SecureRandom().nextBytes(challenge);
            byte[] signatureBytes = DeviceBindingProof.decode(
                    deviceKeyStore.sign(firstInstallationId, DeviceBindingProof.encode(challenge))
            );
            PublicKey publicKey = KeyFactory.getInstance("EC").generatePublic(
                    new X509EncodedKeySpec(DeviceBindingProof.decode(firstPublicKey))
            );
            assertEquals(256, ((ECPublicKey) publicKey).getParams().getOrder().bitLength());

            Signature verifier = Signature.getInstance("SHA256withECDSA");
            verifier.initVerify(publicKey);
            verifier.update(challenge);
            assertTrue(verifier.verify(signatureBytes));

            PublicKey otherInstallationPublicKey = KeyFactory.getInstance("EC").generatePublic(
                    new X509EncodedKeySpec(DeviceBindingProof.decode(secondPublicKey))
            );
            verifier.initVerify(otherInstallationPublicKey);
            verifier.update(challenge);
            assertFalse("A different installation key must not verify this proof", verifier.verify(signatureBytes));
        } finally {
            keyStore.load(null);
            if (keyStore.containsAlias(firstAlias)) {
                keyStore.deleteEntry(firstAlias);
            }
            if (keyStore.containsAlias(secondAlias)) {
                keyStore.deleteEntry(secondAlias);
            }
        }
    }

    @Test
    public void digestSignatureMatchesGoCryptoSignerContract() throws Exception {
        String installationId = "digest-contract-" + UUID.randomUUID();
        String alias = KEY_ALIAS_PREFIX + installationId;
        DeviceKeyStore deviceKeyStore = new DeviceKeyStore();
        KeyStore keyStore = KeyStore.getInstance(KEY_STORE);
        byte[] message = "xlb Android Keystore crypto.Signer contract v1".getBytes(StandardCharsets.UTF_8);
        byte[] digest = MessageDigest.getInstance("SHA-256").digest(message);

        try {
            String encodedPublicKey = deviceKeyStore.publicKey(installationId);
            KeyStore.PrivateKeyEntry entry;
            keyStore.load(null);
            entry = (KeyStore.PrivateKeyEntry) keyStore.getEntry(alias, null);
            byte[] signatureBytes = signDigest(entry.getPrivateKey(), digest);
            PublicKey publicKey = KeyFactory.getInstance("EC").generatePublic(
                    new X509EncodedKeySpec(DeviceBindingProof.decode(encodedPublicKey))
            );

            Signature digestVerifier = Signature.getInstance("NONEwithECDSA");
            digestVerifier.initVerify(publicKey);
            digestVerifier.update(digest);
            assertTrue("Android Keystore must sign the supplied digest without hashing it again",
                    digestVerifier.verify(signatureBytes));

            Signature messageVerifier = Signature.getInstance("SHA256withECDSA");
            messageVerifier.initVerify(publicKey);
            messageVerifier.update(message);
            assertTrue("The digest signature must verify against the original message",
                    messageVerifier.verify(signatureBytes));

            JSONObject vector = new JSONObject()
                    .put("message", DeviceBindingProof.encode(message))
                    .put("digest", DeviceBindingProof.encode(digest))
                    .put("public_key", encodedPublicKey)
                    .put("signature", DeviceBindingProof.encode(signatureBytes));
            try (FileOutputStream output = InstrumentationRegistry.getInstrumentation()
                    .getTargetContext().openFileOutput("android-keystore-digest-vector.json", 0)) {
                output.write(vector.toString().getBytes(StandardCharsets.UTF_8));
            }
        } finally {
            keyStore.load(null);
            if (keyStore.containsAlias(alias)) {
                keyStore.deleteEntry(alias);
            }
        }
    }

    @Test
    public void legacySha256OnlyKeyFailsClosedWithoutRotation() throws Exception {
        String installationId = "legacy-digest-contract-" + UUID.randomUUID();
        String alias = KEY_ALIAS_PREFIX + installationId;
        KeyPairGenerator generator = KeyPairGenerator.getInstance("EC", KEY_STORE);
        generator.initialize(new KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_SIGN)
                .setAlgorithmParameterSpec(new ECGenParameterSpec("secp256r1"))
                .setDigests(KeyProperties.DIGEST_SHA256)
                .build());
        PublicKey originalPublicKey = generator.generateKeyPair().getPublic();
        KeyStore keyStore = KeyStore.getInstance(KEY_STORE);
        keyStore.load(null);

        try {
            try {
                signDigest((PrivateKey) keyStore.getKey(alias, null), new byte[32]);
                fail("A SHA256-only legacy key must not sign a pre-hashed digest");
            } catch (java.security.GeneralSecurityException expected) {
                assertTrue("Legacy key must remain registered", keyStore.containsAlias(alias));
            }
            assertArrayEquals(originalPublicKey.getEncoded(),
                    keyStore.getCertificate(alias).getPublicKey().getEncoded());
        } finally {
            if (keyStore.containsAlias(alias)) {
                keyStore.deleteEntry(alias);
            }
        }
    }

    private static byte[] signDigest(PrivateKey privateKey, byte[] digest) throws Exception {
        Signature signer = Signature.getInstance("NONEwithECDSA");
        signer.initSign(privateKey);
        signer.update(digest);
        return signer.sign();
    }
}
