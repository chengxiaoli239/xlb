package com.xlb.vpn.tunnel;

import java.io.IOException;
import java.util.concurrent.Executors;
import java.util.concurrent.RejectedExecutionException;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.ScheduledFuture;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.function.BooleanSupplier;

/** Periodically verifies a current member tunnel and reports sustained route failure once. */
final class MemberTunnelHealthMonitor implements AutoCloseable {
    interface Probe {
        boolean check() throws IOException;
    }

    private final BooleanSupplier attemptIsCurrent;
    private final Probe probe;
    private final Runnable failureHandler;
    private final long intervalMillis;
    private final int failureThreshold;
    private final AtomicBoolean started = new AtomicBoolean();
    private final AtomicBoolean closed = new AtomicBoolean();
    private final ScheduledExecutorService scheduler = Executors.newSingleThreadScheduledExecutor(task -> {
        Thread thread = new Thread(task, "xlb-member-tunnel-health");
        thread.setDaemon(true);
        return thread;
    });
    private ScheduledFuture<?> scheduledCheck;
    private int consecutiveFailures;

    MemberTunnelHealthMonitor(BooleanSupplier attemptIsCurrent, Probe probe, Runnable failureHandler,
            long intervalMillis, int failureThreshold) {
        if (attemptIsCurrent == null || probe == null || failureHandler == null) {
            throw new IllegalArgumentException("member tunnel health dependencies are required");
        }
        if (intervalMillis < 1 || failureThreshold < 1) {
            throw new IllegalArgumentException("member tunnel health limits are invalid");
        }
        this.attemptIsCurrent = attemptIsCurrent;
        this.probe = probe;
        this.failureHandler = failureHandler;
        this.intervalMillis = intervalMillis;
        this.failureThreshold = failureThreshold;
    }

    void start() {
        if (!started.compareAndSet(false, true)) {
            throw new IllegalStateException("member tunnel health monitor already started");
        }
        if (closed.get()) {
            scheduler.shutdownNow();
            return;
        }
        try {
            scheduledCheck = scheduler.scheduleWithFixedDelay(this::check, intervalMillis,
                    intervalMillis, TimeUnit.MILLISECONDS);
        } catch (RejectedExecutionException exception) {
            closed.set(true);
            scheduler.shutdownNow();
        }
    }

    @Override
    public void close() {
        if (!closed.compareAndSet(false, true)) return;
        ScheduledFuture<?> current = scheduledCheck;
        if (current != null) current.cancel(true);
        scheduler.shutdownNow();
    }

    private void check() {
        if (closed.get()) return;
        if (!attemptIsCurrent.getAsBoolean()) {
            close();
            return;
        }

        boolean healthy;
        try {
            healthy = probe.check();
        } catch (IOException | RuntimeException exception) {
            healthy = false;
        }

        if (closed.get()) return;
        if (!attemptIsCurrent.getAsBoolean()) {
            close();
            return;
        }
        if (healthy) {
            consecutiveFailures = 0;
            return;
        }

        consecutiveFailures++;
        if (consecutiveFailures >= failureThreshold && closed.compareAndSet(false, true)) {
            scheduler.shutdown();
            failureHandler.run();
        }
    }
}
