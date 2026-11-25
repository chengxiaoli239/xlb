package com.xlb.vpn.data;

/** JNI hooks for Android instrumentation-only signer, bridge, and isolated Agent fixtures. */
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

    public static void armNodeAgentFixtureEchoGate() {
        nativeArmNodeAgentFixtureEchoGate();
    }

    public static boolean awaitNodeAgentFixtureEchoGate(long timeoutMillis) {
        return nativeAwaitNodeAgentFixtureEchoGate(timeoutMillis);
    }

    public static void releaseNodeAgentFixtureEchoGate() {
        nativeReleaseNodeAgentFixtureEchoGate();
    }

    public static int nodeAgentFixtureEchoTargetDialCount() {
        return nativeNodeAgentFixtureEchoTargetDialCount();
    }

    private static native boolean nativeCompleteTls13MtlsHandshake(
            DigestSigner signer, byte[] publicKeySpkiDer);

    private static native String nativeStartNodeAgentFixture(String requestJson);

    private static native void nativeStopNodeAgentFixture();

    private static native String nativeNodeAgentFixtureStatus();

    private static native String nativeRevokeNodeAgentFixturePeer();

    private static native void nativeArmNodeAgentFixtureEchoGate();

    private static native boolean nativeAwaitNodeAgentFixtureEchoGate(long timeoutMillis);

    private static native void nativeReleaseNodeAgentFixtureEchoGate();

    private static native int nativeNodeAgentFixtureEchoTargetDialCount();
}
