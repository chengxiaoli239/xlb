package com.xlb.vpn.data;

import org.junit.Test;

import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.Signature;
import java.security.spec.ECGenParameterSpec;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;
import static org.junit.Assert.fail;

public final class DeviceBindingProofTest {
    @Test
    public void signsTheDecodedChallengeWithP256() throws Exception {
        KeyPairGenerator generator = KeyPairGenerator.getInstance("EC");
        generator.initialize(new ECGenParameterSpec("secp256r1"));
        KeyPair keyPair = generator.generateKeyPair();
        byte[] bytes = new byte[32];
        for (int index = 0; index < bytes.length; index++) bytes[index] = (byte) index;
        String challenge = DeviceBindingProof.encode(bytes);

        Signature verifier = Signature.getInstance("SHA256withECDSA");
        verifier.initVerify(keyPair.getPublic());
        verifier.update(bytes);

        assertTrue(verifier.verify(DeviceBindingProof.decode(DeviceBindingProof.sign(keyPair.getPrivate(), challenge))));
        assertEquals(challenge, DeviceBindingProof.encode(DeviceBindingProof.decode(challenge)));
    }

    @Test
    public void rejectsMalformedChallengeBeforeSigning() throws Exception {
        KeyPairGenerator generator = KeyPairGenerator.getInstance("EC");
        generator.initialize(new ECGenParameterSpec("secp256r1"));
        KeyPair keyPair = generator.generateKeyPair();
        try {
            DeviceBindingProof.sign(keyPair.getPrivate(), "short");
            fail("Expected an invalid challenge");
        } catch (IllegalArgumentException expected) {
            assertEquals("无效的设备验证请求", expected.getMessage());
        }
    }
}
