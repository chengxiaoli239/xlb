package com.xlb.vpn.data;

import android.os.Build;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;

import java.security.KeyFactory;
import java.security.KeyStore;
import java.security.PrivateKey;
import java.security.PublicKey;
import java.security.SecureRandom;
import java.security.Signature;
import java.security.interfaces.ECPublicKey;
import java.security.spec.X509EncodedKeySpec;
import java.util.Locale;
import java.util.UUID;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

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
}
