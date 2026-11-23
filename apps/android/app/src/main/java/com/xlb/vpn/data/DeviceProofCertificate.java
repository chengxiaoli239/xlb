package com.xlb.vpn.data;

import android.util.Base64;

import java.security.GeneralSecurityException;
import java.security.SecureRandom;
import java.util.Arrays;

/** Ephemeral certificate view over the installation Keystore signer. */
final class DeviceProofCertificate implements AutoCloseable {
    private static final Object LOCK = new Object();
    private static final int MAX_CERTIFICATE_BYTES = 8 * 1024;

    static {
        System.loadLibrary("xlbcore");
    }

    private final byte[] certificateDer;
    private boolean closed;
    private BridgeCredentials bridgeCredentials;

    private DeviceProofCertificate(byte[] certificateDer) {
        this.certificateDer = certificateDer;
    }

    static DeviceProofCertificate create(MemberDeviceProof proof) throws GeneralSecurityException {
        if (proof == null || !proof.hasRequiredReferences()) {
            throw new GeneralSecurityException("verified member device proof is unavailable");
        }

        MemberDeviceProof.Signer signer = proof.signer();
        byte[] publicKey;
        try {
            publicKey = DeviceBindingProof.decode(signer.publicKeySpkiBase64Url());
        } catch (RuntimeException exception) {
            throw new GeneralSecurityException("device public key is invalid", exception);
        }
        if (publicKey.length < 1 || publicKey.length > 256) {
            throw new GeneralSecurityException("device public key is invalid");
        }

        final String encodedCertificate;
        synchronized (LOCK) {
            encodedCertificate = nativeCreateDeviceCertificate(
                    proof.nodeId(), proof.peerId(), proof.deviceId(),
                    generation(proof.peerGeneration()), generation(proof.deviceBindingGeneration()),
                    publicKey, signer);
        }
        if (encodedCertificate == null || encodedCertificate.isEmpty()) {
            throw new GeneralSecurityException("Keystore-backed signer certificate could not be created");
        }
        byte[] certificate;
        try {
            certificate = DeviceBindingProof.decode(encodedCertificate);
        } catch (RuntimeException exception) {
            nativeReleaseDeviceCertificate();
            throw new GeneralSecurityException("device certificate encoding is invalid", exception);
        }
        if (certificate.length == 0 || certificate.length > MAX_CERTIFICATE_BYTES) {
            nativeReleaseDeviceCertificate();
            Arrays.fill(certificate, (byte) 0);
            throw new GeneralSecurityException("device certificate size is invalid");
        }
        return new DeviceProofCertificate(certificate);
    }

    synchronized byte[] encodedCertificate() {
        if (closed) {
            throw new IllegalStateException("device certificate handle is closed");
        }
        return certificateDer.clone();
    }

    synchronized BridgeCredentials startProofBridge(String nodeEndpoint, String trustedNodeCaPem)
            throws GeneralSecurityException {
        synchronized (LOCK) {
            if (closed) {
                throw new GeneralSecurityException("device certificate handle is closed");
            }
            if (bridgeCredentials != null) {
                throw new GeneralSecurityException("proof bridge is already configured for this certificate");
            }
            byte[] usernameBytes = new byte[32];
            byte[] passwordBytes = new byte[32];
            SecureRandom random = new SecureRandom();
            random.nextBytes(usernameBytes);
            random.nextBytes(passwordBytes);
            String username = Base64.encodeToString(usernameBytes, Base64.URL_SAFE | Base64.NO_WRAP | Base64.NO_PADDING);
            String password = Base64.encodeToString(passwordBytes, Base64.URL_SAFE | Base64.NO_WRAP | Base64.NO_PADDING);
            Arrays.fill(usernameBytes, (byte) 0);
            Arrays.fill(passwordBytes, (byte) 0);

            String failure = nativeStartProofBridge(nodeEndpoint, trustedNodeCaPem, username, password);
            if (failure != null) {
                throw new GeneralSecurityException("proof bridge could not start");
            }
            bridgeCredentials = new BridgeCredentials(username, password);
            return bridgeCredentials;
        }
    }

    synchronized boolean proofBridgeRunning() {
        synchronized (LOCK) {
            return !closed && nativeProofBridgeRunning();
        }
    }

    synchronized void stopProofBridge() {
        synchronized (LOCK) {
            nativeStopProofBridge();
            clearBridgeCredentials();
        }
    }

    @Override
    public synchronized void close() {
        synchronized (LOCK) {
            if (closed) return;
            nativeStopProofBridge();
            nativeReleaseDeviceCertificate();
            clearBridgeCredentials();
            Arrays.fill(certificateDer, (byte) 0);
            closed = true;
        }
    }

    private void clearBridgeCredentials() {
        if (bridgeCredentials != null) {
            bridgeCredentials.clear();
            bridgeCredentials = null;
        }
    }

    @Override
    public String toString() {
        return "DeviceProofCertificate[ephemeral]";
    }

    static final class BridgeCredentials {
        private final char[] username;
        private final char[] password;

        private BridgeCredentials(String username, String password) {
            this.username = username.toCharArray();
            this.password = password.toCharArray();
        }

        synchronized String usernameForProfile() {
            return new String(username);
        }

        synchronized String passwordForProfile() {
            return new String(password);
        }

        synchronized void clear() {
            Arrays.fill(username, '\0');
            Arrays.fill(password, '\0');
        }
    }

    private static int generation(long value) throws GeneralSecurityException {
        if (value < 1 || value > Integer.MAX_VALUE) {
            throw new GeneralSecurityException("device proof generation is invalid");
        }
        return (int) value;
    }

    private static native String nativeCreateDeviceCertificate(String nodeId, String peerId, String deviceId,
            int peerGeneration, int deviceGeneration, byte[] publicKeySpki, MemberDeviceProof.Signer signer);

    private static native void nativeReleaseDeviceCertificate();

    private static native String nativeStartProofBridge(String nodeEndpoint, String trustedNodeCaPem,
            String localUsername, String localPassword);

    private static native void nativeStopProofBridge();

    private static native boolean nativeProofBridgeRunning();
}
