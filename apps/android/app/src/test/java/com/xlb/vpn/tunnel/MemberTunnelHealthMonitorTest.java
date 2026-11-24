package com.xlb.vpn.tunnel;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicReference;

import org.junit.Test;

public final class MemberTunnelHealthMonitorTest {
    @Test
    public void reportsOneFailureAfterConsecutiveUnhealthyProbes() throws Exception {
        AtomicInteger probes = new AtomicInteger();
        AtomicInteger failures = new AtomicInteger();
        CountDownLatch failureReported = new CountDownLatch(1);
        MemberTunnelHealthMonitor monitor = new MemberTunnelHealthMonitor(
                () -> true,
                () -> {
                    probes.incrementAndGet();
                    return false;
                },
                () -> {
                    failures.incrementAndGet();
                    failureReported.countDown();
                },
                5,
                2);

        try {
            monitor.start();

            assertTrue("unhealthy member tunnel was not reported", failureReported.await(1, TimeUnit.SECONDS));
            Thread.sleep(25);
            assertEquals(2, probes.get());
            assertEquals(1, failures.get());
        } finally {
            monitor.close();
        }
    }

    @Test
    public void successfulProbeResetsTheConsecutiveFailureCount() throws Exception {
        AtomicInteger probes = new AtomicInteger();
        AtomicInteger failures = new AtomicInteger();
        CountDownLatch failureReported = new CountDownLatch(1);
        boolean[] results = {false, true, false, false};
        MemberTunnelHealthMonitor monitor = new MemberTunnelHealthMonitor(
                () -> true,
                () -> {
                    int index = probes.getAndIncrement();
                    return results[Math.min(index, results.length - 1)];
                },
                () -> {
                    failures.incrementAndGet();
                    failureReported.countDown();
                },
                5,
                2);

        try {
            monitor.start();

            assertTrue("monitor did not recover its failure streak after a healthy probe",
                    failureReported.await(1, TimeUnit.SECONDS));
            assertEquals(4, probes.get());
            assertEquals(1, failures.get());
        } finally {
            monitor.close();
        }
    }

    @Test
    public void staleAttemptStopsProbingWithoutReportingTunnelFailure() throws Exception {
        AtomicInteger probes = new AtomicInteger();
        AtomicReference<Throwable> callbackFailure = new AtomicReference<>();
        MemberTunnelHealthMonitor monitor = new MemberTunnelHealthMonitor(
                () -> false,
                () -> {
                    probes.incrementAndGet();
                    return false;
                },
                () -> callbackFailure.set(new AssertionError("stale tunnel attempt reported failure")),
                5,
                1);

        monitor.start();
        Thread.sleep(30);
        monitor.close();

        assertEquals(0, probes.get());
        assertFalse("monitor reported a stale attempt failure", callbackFailure.get() != null);
    }

    @Test
    public void closingDuringProbePreventsLateFailureCallback() throws Exception {
        CountDownLatch probeStarted = new CountDownLatch(1);
        CountDownLatch releaseProbe = new CountDownLatch(1);
        AtomicInteger failures = new AtomicInteger();
        MemberTunnelHealthMonitor monitor = new MemberTunnelHealthMonitor(
                () -> true,
                () -> {
                    probeStarted.countDown();
                    try {
                        releaseProbe.await();
                    } catch (InterruptedException exception) {
                        Thread.currentThread().interrupt();
                    }
                    return false;
                },
                failures::incrementAndGet,
                5,
                1);

        monitor.start();
        assertTrue("health probe did not start", probeStarted.await(1, TimeUnit.SECONDS));
        monitor.close();
        releaseProbe.countDown();
        Thread.sleep(25);

        assertEquals("closed monitor reported a late probe failure", 0, failures.get());
    }
}
