package com.xlb.vpn.tunnel;

import com.xlb.vpn.data.MemberTunnelGrant;

/** Owns the ordered, fail-closed native resources for one authorized member tunnel. */
final class MemberTunnelSession implements AutoCloseable {
    interface Runtime {
        ProofHandle createDeviceCertificate(MemberTunnelGrant grant) throws Exception;
        AutoCloseable startBridge(ProofHandle certificate, MemberTunnelGrant grant) throws Exception;
        void loadMemberProfile(MemberTunnelGrant grant, AutoCloseable credentials) throws Exception;
        void establishTun() throws Exception;
        void verifyTunnel() throws Exception;
        void stopTunAndCore() throws Exception;
        void stopBridge() throws Exception;
    }

    interface ProofHandle extends AutoCloseable {
        @Override
        void close();
    }

    private final Runtime runtime;
    private ProofHandle certificate;
    private AutoCloseable credentials;
    private boolean started;
    private boolean tunStopped;
    private boolean bridgeStopped;
    private boolean closed;
    private volatile boolean stopRequested;

    MemberTunnelSession(Runtime runtime) {
        if (runtime == null) throw new IllegalArgumentException("member tunnel runtime is required");
        this.runtime = runtime;
    }

    synchronized void start(MemberTunnelGrant grant) throws Exception {
        if (closed) throw new IllegalStateException("member tunnel session is closed");
        if (stopRequested) throw new IllegalStateException("member tunnel session stop was requested");
        if (tunStopped || bridgeStopped) throw new IllegalStateException("member tunnel session cleanup is incomplete");
        if (started || certificate != null || credentials != null) {
            throw new IllegalStateException("member tunnel session is already active");
        }
        if (grant == null) throw new IllegalArgumentException("verified member tunnel grant is required");

        try {
            certificate = runtime.createDeviceCertificate(grant);
            if (certificate == null) throw new IllegalStateException("device proof certificate is unavailable");
            checkStopRequested();
            credentials = runtime.startBridge(certificate, grant);
            if (credentials == null) throw new IllegalStateException("proof bridge credentials are unavailable");
            checkStopRequested();
            runtime.loadMemberProfile(grant, credentials);
            checkStopRequested();
            runtime.establishTun();
            checkStopRequested();
            runtime.verifyTunnel();
            checkStopRequested();
            started = true;
        } catch (Exception exception) {
            stopAfterFailure(exception);
            throw exception;
        } catch (Error error) {
            stopAfterFailure(error);
            throw error;
        }
    }

    synchronized boolean isStarted() {
        return started;
    }

    void requestStop() {
        stopRequested = true;
    }

    @Override
    public synchronized void close() throws Exception {
        if (closed) return;
        Exception firstFailure = null;
        if (!tunStopped) {
            try {
                runtime.stopTunAndCore();
                tunStopped = true;
            } catch (Exception exception) {
                firstFailure = exception;
            }
        }
        if (!bridgeStopped) {
            try {
                runtime.stopBridge();
                bridgeStopped = true;
            } catch (Exception exception) {
                if (firstFailure == null) firstFailure = exception;
            }
        }
        if (credentials != null) {
            try {
                credentials.close();
                credentials = null;
            } catch (Exception exception) {
                if (firstFailure == null) firstFailure = exception;
            }
        }
        if (certificate != null) {
            certificate.close();
            certificate = null;
        }
        started = false;
        if (firstFailure == null && tunStopped && bridgeStopped
                && credentials == null && certificate == null) {
            closed = true;
        }
        if (!closed) {
            if (firstFailure == null) firstFailure = new IllegalStateException("member tunnel cleanup is incomplete");
            throw firstFailure;
        }
    }

    private void stopAfterFailure(Throwable original) {
        try {
            close();
        } catch (Exception cleanupFailure) {
            original.addSuppressed(cleanupFailure);
        }
    }

    private void checkStopRequested() {
        if (stopRequested) throw new IllegalStateException("member tunnel session stop was requested");
    }
}
