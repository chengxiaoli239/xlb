package com.xlb.vpn.data;

import java.security.GeneralSecurityException;
import java.security.PrivateKey;
import java.security.Signature;
import java.util.Base64;

final class DeviceBindingProof {
    private DeviceBindingProof() {}

    static String encode(byte[] value) {
        return Base64.getUrlEncoder().withoutPadding().encodeToString(value);
    }

    static byte[] decode(String value) {
        if (value == null || !value.matches("[A-Za-z0-9_-]+")) {
            throw new IllegalArgumentException("无效的设备验证请求");
        }
        try {
            return Base64.getUrlDecoder().decode(value);
        } catch (IllegalArgumentException exception) {
            throw new IllegalArgumentException("无效的设备验证请求", exception);
        }
    }

    static String sign(PrivateKey privateKey, String challenge) throws GeneralSecurityException {
        byte[] challengeBytes = decode(challenge);
        if (challengeBytes.length != 32) {
            throw new IllegalArgumentException("无效的设备验证请求");
        }
        Signature signer = Signature.getInstance("SHA256withECDSA");
        signer.initSign(privateKey);
        signer.update(challengeBytes);
        return encode(signer.sign());
    }

    static String signDigest(PrivateKey privateKey, byte[] digest) throws GeneralSecurityException {
        if (digest == null || digest.length != 32) {
            throw new IllegalArgumentException("无效的设备验证请求");
        }
        Signature signer = Signature.getInstance("NONEwithECDSA");
        signer.initSign(privateKey);
        signer.update(digest);
        return encode(signer.sign());
    }
}
