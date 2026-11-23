package com.xlb.vpn.tunnel;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class TunnelAttemptGateTest {
    @Test
    public void stopInvalidatesQueuedStartAndNewStartGetsNewGeneration() {
        TunnelAttemptGate gate = new TunnelAttemptGate();
        int queuedStart = gate.begin();

        gate.invalidate();
        int newerStart = gate.begin();

        assertFalse(gate.isCurrent(queuedStart));
        assertTrue(gate.isCurrent(newerStart));
    }

    @Test
    public void staleAttemptCannotFinishOrInvalidateNewerStart() {
        TunnelAttemptGate gate = new TunnelAttemptGate();
        int staleAttempt = gate.begin();
        gate.invalidate();
        int currentAttempt = gate.begin();

        assertFalse(gate.finishIfCurrent(staleAttempt));
        assertTrue(gate.isCurrent(currentAttempt));
        assertTrue(gate.finishIfCurrent(currentAttempt));
        assertFalse(gate.isCurrent(currentAttempt));
    }
}
