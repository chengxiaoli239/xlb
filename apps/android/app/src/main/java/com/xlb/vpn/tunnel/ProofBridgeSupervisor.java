package com.xlb.vpn.tunnel;

import com.xlb.vpn.data.MemberDeviceProof;

import java.util.Objects;

/** Offline lifecycle contract. It is intentionally not wired into the VPN service. */
final class ProofBridgeSupervisor {
    static final boolean PROOF_BRIDGE_ENABLED = false;

    enum State {
        STOPPED,
        STARTING,
        CHECKING,
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

    interface ManagedProcess {
        boolean isAlive();
        void stop() throws ProofBridgeException;
        void forceStop() throws ProofBridgeException;
    }

    interface Adapter {
        /**
         * Must use bounded I/O while rechecking authorization and peer proof. Any child process created
         * before an error must be synchronously stopped and reaped by the adapter.
         */
        ManagedProcess launch(MemberDeviceProof proof) throws ProofBridgeException;
    }

    static final class ProofBridgeException extends Exception {
        final Failure failure;

        ProofBridgeException(Failure failure) {
            super(Objects.requireNonNull(failure).name());
            this.failure = failure;
        }
    }

    private final Object stateLock = new Object();
    private final Adapter adapter;
    private ManagedProcess process;
    private Snapshot snapshot = new Snapshot(State.STOPPED, null);
    private boolean operationInProgress;
    private boolean stopRequested;

    ProofBridgeSupervisor(Adapter adapter) {
        this.adapter = adapter;
    }

    Snapshot status() {
        ManagedProcess current;
        synchronized (stateLock) {
            if (operationInProgress || snapshot.state != State.READY) return snapshot;
            if (process == null) return failLocked(Failure.PROCESS_EXITED);
            operationInProgress = true;
            snapshot = new Snapshot(State.CHECKING, null);
            current = process;
        }

        if (!isAlive(current)) {
            boolean cleaned = cleanupProcess(current);
            synchronized (stateLock) {
                boolean requestedStop = stopRequested;
                if (cleaned && process == current) process = null;
                snapshot = cleaned && requestedStop
                        ? new Snapshot(State.STOPPED, null)
                        : new Snapshot(State.FAILED, cleaned ? Failure.PROCESS_EXITED : Failure.STOP_FAILED);
                operationInProgress = false;
                stopRequested = false;
                return snapshot;
            }
        }

        synchronized (stateLock) {
            if (!stopRequested) {
                snapshot = new Snapshot(State.READY, null);
                operationInProgress = false;
                return snapshot;
            }
            snapshot = new Snapshot(State.STOPPING, null);
        }
        return finishStop(current);
    }

    Snapshot start(MemberDeviceProof proof) {
        synchronized (stateLock) {
            if (operationInProgress) return snapshot;
            if (proof == null || !proof.hasRequiredReferences()) return failLocked(Failure.INVALID_MEMBER_PROOF);
            if (!PROOF_BRIDGE_ENABLED) return failLocked(Failure.FEATURE_DISABLED);
        }

        Snapshot current = status();
        if (current.state == State.READY) return current;

        ManagedProcess previous;
        synchronized (stateLock) {
            if (operationInProgress || snapshot.state == State.READY) return snapshot;
            operationInProgress = true;
            stopRequested = false;
            snapshot = new Snapshot(State.STARTING, null);
            previous = process;
        }

        if (previous != null) {
            boolean cleaned = cleanupProcess(previous);
            synchronized (stateLock) {
                if (cleaned && process == previous) process = null;
                if (!cleaned) {
                    operationInProgress = false;
                    stopRequested = false;
                    return failLocked(Failure.STOP_FAILED);
                }
                if (stopRequested) {
                    operationInProgress = false;
                    stopRequested = false;
                    snapshot = new Snapshot(State.STOPPED, null);
                    return snapshot;
                }
            }
        }

        try {
            ManagedProcess started = adapter == null ? null : adapter.launch(proof);
            if (started == null) return finishStartFailure(Failure.ADAPTER_UNAVAILABLE, null);

            boolean stopAfterLaunch;
            synchronized (stateLock) {
                if (stopRequested) {
                    process = started;
                    snapshot = new Snapshot(State.STOPPING, null);
                }
                stopAfterLaunch = stopRequested;
            }
            if (stopAfterLaunch) return finishStop(started);

            if (!isAlive(started)) {
                boolean cleaned = cleanupProcess(started);
                return finishStartFailure(cleaned ? Failure.PROCESS_EXITED : Failure.STOP_FAILED,
                        cleaned ? null : started);
            }

            synchronized (stateLock) {
                if (stopRequested) {
                    process = started;
                    snapshot = new Snapshot(State.STOPPING, null);
                } else {
                    process = started;
                    snapshot = new Snapshot(State.READY, null);
                    operationInProgress = false;
                    return snapshot;
                }
            }
            return finishStop(started);
        } catch (ProofBridgeException exception) {
            return finishStartFailure(exception.failure, null);
        } catch (RuntimeException exception) {
            return finishStartFailure(Failure.ADAPTER_UNAVAILABLE, null);
        }
    }

    Snapshot stop() {
        ManagedProcess current;
        synchronized (stateLock) {
            if (operationInProgress) {
                if (snapshot.state != State.STOPPING) {
                    stopRequested = true;
                    snapshot = new Snapshot(State.STOPPING, null);
                }
                return snapshot;
            }
            if (process == null) {
                snapshot = new Snapshot(State.STOPPED, null);
                return snapshot;
            }
            operationInProgress = true;
            stopRequested = false;
            snapshot = new Snapshot(State.STOPPING, null);
            current = process;
        }

        return finishStop(current);
    }

    private boolean cleanupProcess(ManagedProcess target) {
        try {
            if (target.isAlive()) target.stop();
        } catch (ProofBridgeException | RuntimeException ignored) {
            // Force stop below; retain the handle if both attempts fail.
        }
        try {
            if (target.isAlive()) target.forceStop();
        } catch (ProofBridgeException | RuntimeException ignored) {
            return false;
        }
        try {
            if (target.isAlive()) return false;
        } catch (RuntimeException exception) {
            return false;
        }
        return true;
    }

    private Snapshot finishStop(ManagedProcess target) {
        boolean stopped = cleanupProcess(target);
        synchronized (stateLock) {
            if (stopped && process == target) process = null;
            snapshot = stopped ? new Snapshot(State.STOPPED, null)
                    : new Snapshot(State.FAILED, Failure.STOP_FAILED);
            operationInProgress = false;
            stopRequested = false;
            return snapshot;
        }
    }

    private Snapshot finishStartFailure(Failure failure, ManagedProcess retainedProcess) {
        synchronized (stateLock) {
            if (retainedProcess != null) process = retainedProcess;
            snapshot = stopRequested && retainedProcess == null
                    ? new Snapshot(State.STOPPED, null)
                    : new Snapshot(State.FAILED, Objects.requireNonNull(failure));
            operationInProgress = false;
            stopRequested = false;
            return snapshot;
        }
    }

    private Snapshot failLocked(Failure failure) {
        snapshot = new Snapshot(State.FAILED, Objects.requireNonNull(failure));
        return snapshot;
    }

    private static boolean isAlive(ManagedProcess target) {
        try {
            return target.isAlive();
        } catch (RuntimeException exception) {
            return false;
        }
    }

}
