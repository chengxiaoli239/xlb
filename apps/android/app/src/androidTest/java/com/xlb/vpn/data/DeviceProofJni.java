package com.xlb.vpn.data;

/** Instrumentation-only signer callback contract; it is not connected to the VPN or bridge lifecycle. */
final class DeviceProofJni {
    static {
        System.loadLibrary("xlbcore");
    }

    interface DigestSigner {
        byte[] signDigest(byte[] digest);
    }

    private DeviceProofJni() {}

    static boolean verifiesSignerCallback(DigestSigner signer, byte[] publicKeySpkiDer, byte[] digest) {
        return signer != null && publicKeySpkiDer != null && digest != null
                && nativeVerifyDigestSignature(signer, publicKeySpkiDer, digest);
    }

    private static native boolean nativeVerifyDigestSignature(
            DigestSigner signer, byte[] publicKeySpkiDer, byte[] digest);
}
