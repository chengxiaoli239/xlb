package com.xlb.vpn.data;

import java.security.GeneralSecurityException;
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

    @Override
    public synchronized void close() {
        synchronized (LOCK) {
            if (closed) return;
            nativeReleaseDeviceCertificate();
            Arrays.fill(certificateDer, (byte) 0);
            closed = true;
        }
    }

    @Override
    public String toString() {
        return "DeviceProofCertificate[ephemeral]";
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
}
