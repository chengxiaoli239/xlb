package com.xlb.vpn.tunnel;

import java.util.Objects;

/** Offline lifecycle contract. It is intentionally not wired into the VPN service. */
final class ProofBridgeSupervisor {
    static final boolean PROOF_BRIDGE_ENABLED = false;

    enum State {
        STOPPED,
        STARTING,
        // A live proof-bridge adapter only; never means VPN connected or traffic verified.
        READY,
        FAILED,
        STOPPING
    }

    enum Failure {
        FEATURE_DISABLED,
        INVALID_MEMBER_PROOF,
        ADAPTER_UNAVAILABLE,
        PROCESS_EXITED,
        STOP_FAILED
    }

    static final class Snapshot {
        final State state;
        final Failure failure;

        Snapshot(State state, Failure failure) {
            this.state = state;
            this.failure = failure;
        }
    }

    /** Capability reserved for a future verifier; ordinary identifiers cannot create one. */
    static final class VerifiedMemberDeviceProof {
        private final String memberId;
        private final String installationId;
        private final String peerBindingId;
        private final DeviceProofSigner signer;

        private VerifiedMemberDeviceProof(String memberId, String installationId,
                String peerBindingId, DeviceProofSigner signer) {
            this.memberId = memberId;
            this.installationId = installationId;
            this.peerBindingId = peerBindingId;
            this.signer = signer;
        }

        boolean hasRequiredReferences() {
            return present(memberId) && present(installationId)
                    && present(peerBindingId) && signer != null;
        }

        DeviceProofSigner signer() {
            return signer;
        }
    }

    /** Exposes only signing operations backed by a non-exportable device key. */
    interface DeviceProofSigner {
        String publicKeySpkiBase64Url() throws ProofBridgeException;
        String signChallengeBase64Url(byte[] challenge) throws ProofBridgeException;
    }

    interface ManagedProcess {
        boolean isAlive();
        void stop() throws ProofBridgeException;
        void forceStop() throws ProofBridgeException;
    }

    interface Adapter {
        /** Must recheck entitlement, binding, revocation and peer proof before returning a live process.
         * Any process created before an error must be synchronously stopped and reaped by the adapter.
         */
        ManagedProcess launch(VerifiedMemberDeviceProof proof) throws ProofBridgeException;
    }

    static final class ProofBridgeException extends Exception {
        final Failure failure;

        ProofBridgeException(Failure failure) {
            super(Objects.requireNonNull(failure).name());
            this.failure = failure;
        }
    }

    private final Adapter adapter;
    private ManagedProcess process;
    private Snapshot snapshot = new Snapshot(State.STOPPED, null);

    ProofBridgeSupervisor(Adapter adapter) {
        this.adapter = adapter;
    }

    Snapshot status() {
        if (snapshot.state == State.READY) {
            boolean alive;
            try {
                alive = process != null && process.isAlive();
            } catch (RuntimeException exception) {
                alive = false;
            }
            if (!alive) return fail(cleanupProcess() ? Failure.PROCESS_EXITED : Failure.STOP_FAILED);
        }
        return snapshot;
    }

    Snapshot start(VerifiedMemberDeviceProof proof) {
        if (proof == null || !proof.hasRequiredReferences()) return fail(Failure.INVALID_MEMBER_PROOF);
        if (!PROOF_BRIDGE_ENABLED) return fail(Failure.FEATURE_DISABLED);
        if (snapshot.state == State.READY) return status();
        if (!cleanupProcess()) return fail(Failure.STOP_FAILED);

        snapshot = new Snapshot(State.STARTING, null);
        try {
            ManagedProcess started = adapter == null ? null : adapter.launch(proof);
            if (started == null) return fail(Failure.ADAPTER_UNAVAILABLE);
            if (!started.isAlive()) {
                process = started;
                return fail(cleanupProcess() ? Failure.PROCESS_EXITED : Failure.STOP_FAILED);
            }
            process = started;
            snapshot = new Snapshot(State.READY, null);
            return snapshot;
        } catch (ProofBridgeException exception) {
            return fail(exception.failure);
        } catch (RuntimeException exception) {
            return fail(Failure.ADAPTER_UNAVAILABLE);
        }
    }

    Snapshot stop() {
        if (process == null) {
            snapshot = new Snapshot(State.STOPPED, null);
            return snapshot;
        }

        snapshot = new Snapshot(State.STOPPING, null);
        if (!cleanupProcess()) return fail(Failure.STOP_FAILED);
        snapshot = new Snapshot(State.STOPPED, null);
        return snapshot;
    }

    private boolean cleanupProcess() {
        if (process == null) return true;
        try {
            if (process.isAlive()) process.stop();
        } catch (ProofBridgeException | RuntimeException ignored) {
            // Force stop below; retain the handle if both attempts fail.
        }
        try {
            if (process.isAlive()) process.forceStop();
        } catch (ProofBridgeException | RuntimeException ignored) {
            return false;
        }
        try {
            if (process.isAlive()) return false;
        } catch (RuntimeException exception) {
            return false;
        }
        process = null;
        return true;
    }

    private Snapshot fail(Failure failure) {
        snapshot = new Snapshot(State.FAILED, Objects.requireNonNull(failure));
        return snapshot;
    }

    private static boolean present(String value) {
        return value != null && !value.trim().isEmpty();
    }
}
