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

    static boolean completesTls13MtlsHandshake(DigestSigner signer, byte[] publicKeySpkiDer) {
        return signer != null && publicKeySpkiDer != null
                && nativeCompleteTls13MtlsHandshake(signer, publicKeySpkiDer);
    }

    static String startNodeAgentFixture(String requestJson) {
        return nativeStartNodeAgentFixture(requestJson);
    }

    static void stopNodeAgentFixture() {
        nativeStopNodeAgentFixture();
    }

    static String nodeAgentFixtureStatus() {
        return nativeNodeAgentFixtureStatus();
    }

    private static native boolean nativeCompleteTls13MtlsHandshake(
            DigestSigner signer, byte[] publicKeySpkiDer);

    private static native String nativeStartNodeAgentFixture(String requestJson);

    private static native void nativeStopNodeAgentFixture();

    private static native String nativeNodeAgentFixtureStatus();
}
