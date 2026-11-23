package com.xlb.vpn.data;

/** Instrumentation-only signer callback contract; it is not connected to the VPN or bridge lifecycle. */
public final class DeviceProofJni {
    static {
        System.loadLibrary("xlbcore");
    }

    interface DigestSigner {
        byte[] signDigest(byte[] digest);
    }

    private DeviceProofJni() {}

    static boolean completesTls13MtlsHandshake(DigestSigner signer, byte[] publicKeySpkiDer) {
        return signer != null && publicKeySpkiDer != null
                && nativeCompleteTls13MtlsHandshake(signer, publicKeySpkiDer);
    }

    public static String startNodeAgentFixture(String requestJson) {
        return nativeStartNodeAgentFixture(requestJson);
    }

    public static void stopNodeAgentFixture() {
        nativeStopNodeAgentFixture();
    }

    public static String nodeAgentFixtureStatus() {
        return nativeNodeAgentFixtureStatus();
    }

    public static String revokeNodeAgentFixturePeer() {
        return nativeRevokeNodeAgentFixturePeer();
    }

    private static native boolean nativeCompleteTls13MtlsHandshake(
            DigestSigner signer, byte[] publicKeySpkiDer);

    private static native String nativeStartNodeAgentFixture(String requestJson);

    private static native void nativeStopNodeAgentFixture();

    private static native String nativeNodeAgentFixtureStatus();

    private static native String nativeRevokeNodeAgentFixturePeer();
}
