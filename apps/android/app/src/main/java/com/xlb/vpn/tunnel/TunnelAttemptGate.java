package com.xlb.vpn.tunnel;

/** Tracks which asynchronous tunnel start is still allowed to publish or continue. */
final class TunnelAttemptGate {
    private int generation;

    synchronized int begin() {
        return ++generation;
    }

    synchronized int invalidate() {
        return ++generation;
    }

    synchronized boolean isCurrent(int attempt) {
        return generation == attempt;
    }

    synchronized boolean finishIfCurrent(int attempt) {
        if (generation != attempt) return false;
        generation++;
        return true;
    }

    synchronized boolean finishIfCurrent(int attempt, Runnable action) {
        if (generation != attempt) return false;
        generation++;
        action.run();
        return true;
    }

    synchronized boolean runIfCurrent(int attempt, Runnable action) {
        if (generation != attempt) return false;
        action.run();
        return true;
    }
}
