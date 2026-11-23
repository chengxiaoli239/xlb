package com.xlb.vpn.tunnel;

import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

import org.junit.Test;

import static org.junit.Assert.assertEquals;
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

    @Test
    public void staleAttemptCannotPublishMemberOwnershipAfterReplacement() {
        TunnelAttemptGate gate = new TunnelAttemptGate();
        AtomicBoolean memberTunnel = new AtomicBoolean();
        int oldMemberAttempt = gate.begin();
        gate.runIfCurrent(oldMemberAttempt, () -> memberTunnel.set(true));

        int replacementAttempt = gate.begin();
        gate.runIfCurrent(replacementAttempt, () -> memberTunnel.set(true));
        boolean staleUpdateApplied = gate.runIfCurrent(oldMemberAttempt, () -> memberTunnel.set(false));

        assertFalse(staleUpdateApplied);
        assertTrue(memberTunnel.get());
    }

    @Test
    public void staleAttemptCannotPublishCompletionAfterReplacement() {
        TunnelAttemptGate gate = new TunnelAttemptGate();
        AtomicInteger publishedFailures = new AtomicInteger();
        int oldAttempt = gate.begin();
        int currentAttempt = gate.begin();

        boolean staleFinished = gate.finishIfCurrent(oldAttempt, publishedFailures::incrementAndGet);
        boolean currentFinished = gate.finishIfCurrent(currentAttempt, publishedFailures::incrementAndGet);

        assertFalse(staleFinished);
        assertTrue(currentFinished);
        assertEquals(1, publishedFailures.get());
    }
}
