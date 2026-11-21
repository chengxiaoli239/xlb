package com.xlb.vpn.data;

import org.junit.Test;

import java.security.SecureRandom;
import java.util.Base64;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotEquals;
import static org.junit.Assert.assertTrue;

public class AnonymousRecoveryCredentialTest {
    @Test
    public void generatedCredentialIsCanonicalBase64UrlFor32Bytes() {
        String secret = AnonymousRecoveryCredential.generate(new SecureRandom());

        assertEquals(43, secret.length());
        assertFalse(secret.contains("="));
        assertEquals(32, Base64.getUrlDecoder().decode(secret).length);
        assertTrue(AnonymousRecoveryCredential.isValid(secret));
    }

    @Test
    public void generatedCredentialsAreIndependent() {
        SecureRandom secureRandom = new SecureRandom();

        assertNotEquals(
                AnonymousRecoveryCredential.generate(secureRandom),
                AnonymousRecoveryCredential.generate(secureRandom)
        );
    }

    @Test
    public void validatorRejectsMalformedAndNonCanonicalValues() {
        assertFalse(AnonymousRecoveryCredential.isValid(null));
        assertFalse(AnonymousRecoveryCredential.isValid("short"));
        assertFalse(AnonymousRecoveryCredential.isValid("B".repeat(43)));
    }
}
