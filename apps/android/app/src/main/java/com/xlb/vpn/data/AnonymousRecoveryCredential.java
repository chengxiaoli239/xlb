package com.xlb.vpn.data;

import java.security.SecureRandom;
import java.util.Base64;

final class AnonymousRecoveryCredential {
    private static final int SECRET_BYTES = 32;

    private AnonymousRecoveryCredential() {
    }

    static String generate(SecureRandom secureRandom) {
        byte[] secret = new byte[SECRET_BYTES];
        secureRandom.nextBytes(secret);
        return Base64.getUrlEncoder().withoutPadding().encodeToString(secret);
    }

    static boolean isValid(String value) {
        if (value == null || !value.matches("^[A-Za-z0-9_-]{43}$")) {
            return false;
        }
        try {
            byte[] decoded = Base64.getUrlDecoder().decode(value);
            return decoded.length == SECRET_BYTES
                    && Base64.getUrlEncoder().withoutPadding().encodeToString(decoded).equals(value);
        } catch (IllegalArgumentException exception) {
            return false;
        }
    }

    static boolean canRestore(boolean consented, String value) {
        return consented && isValid(value);
    }

    static final class RecoveryState {
        final String secret;
        final boolean consented;

        RecoveryState(String secret, boolean consented) {
            this.secret = secret;
            this.consented = consented;
        }

        RecoveryState afterExplicitLogout() {
            return new RecoveryState(secret, false);
        }

        boolean canRestore() {
            return AnonymousRecoveryCredential.canRestore(consented, secret);
        }
    }
}
