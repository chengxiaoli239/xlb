package com.xlb.vpn.tunnel;

import org.junit.Test;

import java.io.IOException;
import java.util.concurrent.atomic.AtomicInteger;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class TunnelVerifierTest {
    @Test
    public void waitsForVpnProbeBeforeReportingConnected() {
        AtomicInteger attempts = new AtomicInteger();
        assertTrue(TunnelVerifier.verify(() -> attempts.incrementAndGet() == 3,
                () -> false, delay -> {}, 5));
        assertTrue(attempts.get() == 3);
    }

    @Test
    public void rejectsFailedAndCancelledProbes() {
        assertFalse(TunnelVerifier.verify(() -> { throw new IOException("offline"); },
                () -> false, delay -> {}, 3));
        AtomicInteger attempts = new AtomicInteger();
        assertFalse(TunnelVerifier.verify(() -> { attempts.incrementAndGet(); return true; },
                () -> true, delay -> {}, 3));
        assertTrue(attempts.get() == 0);
    }
}
