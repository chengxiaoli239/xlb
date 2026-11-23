package com.xlb.vpn.tunnel;

import java.util.concurrent.atomic.AtomicInteger;

/** Tracks which asynchronous tunnel start is still allowed to publish or continue. */
final class TunnelAttemptGate {
    private final AtomicInteger generation = new AtomicInteger();

    int begin() {
        return generation.incrementAndGet();
    }

    int invalidate() {
        return generation.incrementAndGet();
    }

    boolean isCurrent(int attempt) {
        return generation.get() == attempt;
    }

    boolean finishIfCurrent(int attempt) {
        return generation.compareAndSet(attempt, attempt + 1);
    }
}
