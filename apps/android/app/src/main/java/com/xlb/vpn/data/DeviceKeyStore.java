package com.xlb.vpn.data;

import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;

import java.security.GeneralSecurityException;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.KeyStore;
import java.security.spec.ECGenParameterSpec;

final class DeviceKeyStore {
    private static final String KEY_STORE = "AndroidKeyStore";
    private static final String KEY_ALIAS_PREFIX = "xlb-device-p256-v1-";

    String publicKey(String installationId) throws GeneralSecurityException {
        return DeviceBindingProof.encode(keyPair(installationId).getPublic().getEncoded());
    }

    String sign(String installationId, String challenge) throws GeneralSecurityException {
        return DeviceBindingProof.sign(keyPair(installationId).getPrivate(), challenge);
    }

    private KeyPair keyPair(String installationId) throws GeneralSecurityException {
        String alias = KEY_ALIAS_PREFIX + installationId;
        KeyStore store = KeyStore.getInstance(KEY_STORE);
        try {
            store.load(null);
        } catch (java.io.IOException exception) {
            throw new GeneralSecurityException("无法读取设备密钥", exception);
        }
        if (store.containsAlias(alias)) {
            return new KeyPair(store.getCertificate(alias).getPublicKey(),
                    (java.security.PrivateKey) store.getKey(alias, null));
        }
        KeyPairGenerator generator = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, KEY_STORE);
        generator.initialize(new KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_SIGN)
                .setAlgorithmParameterSpec(new ECGenParameterSpec("secp256r1"))
                .setDigests(KeyProperties.DIGEST_SHA256, KeyProperties.DIGEST_NONE)
                .build());
        return generator.generateKeyPair();
    }
}
