package com.xlb.vpn.tunnel;

import org.junit.Test;

import java.lang.reflect.Constructor;
import java.lang.reflect.Field;
import java.lang.reflect.Modifier;
import java.util.concurrent.atomic.AtomicInteger;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertSame;
import static org.junit.Assert.assertTrue;

public final class ProofBridgeSupervisorTest {
    @Test
    public void bridgeIsDisabledAndMissingProofFailsClosedWithoutAdapterCall() {
        AtomicInteger launches = new AtomicInteger();
        ProofBridgeSupervisor supervisor = new ProofBridgeSupervisor(proof -> {
            launches.incrementAndGet();
            return null;
        });

        assertFalse(ProofBridgeSupervisor.PROOF_BRIDGE_ENABLED);
        assertEquals(ProofBridgeSupervisor.State.STOPPED, supervisor.status().state);
        ProofBridgeSupervisor.Snapshot result = supervisor.start(null);

        assertEquals(ProofBridgeSupervisor.State.FAILED, result.state);
        assertEquals(ProofBridgeSupervisor.Failure.INVALID_MEMBER_PROOF, result.failure);
        assertEquals(0, launches.get());
    }

    @Test
    public void capabilityCannotBeConstructedFromOrdinaryIdentifiers() {
        Constructor<?>[] constructors = ProofBridgeSupervisor.VerifiedMemberDeviceProof.class
                .getDeclaredConstructors();

        assertEquals(1, constructors.length);
        assertTrue(Modifier.isPrivate(constructors[0].getModifiers()));
    }

    @Test
    public void repeatedStopWithoutProcessIsIdempotentAndDoesNotClaimReady() {
        ProofBridgeSupervisor supervisor = new ProofBridgeSupervisor(null);

        assertEquals(ProofBridgeSupervisor.State.STOPPED, supervisor.stop().state);
        assertEquals(ProofBridgeSupervisor.State.STOPPED, supervisor.stop().state);
        assertEquals(ProofBridgeSupervisor.State.STOPPED, supervisor.status().state);
    }

    @Test
    public void failedGracefulStopFallsBackToForceStopAndClearsHandle() throws Exception {
        ProofBridgeSupervisor supervisor = new ProofBridgeSupervisor(null);
        FakeProcess process = new FakeProcess(true, true, false);
        installProcess(supervisor, process);

        ProofBridgeSupervisor.Snapshot result = supervisor.stop();

        assertEquals(ProofBridgeSupervisor.State.STOPPED, result.state);
        assertEquals(1, process.stopCalls);
        assertEquals(1, process.forceStopCalls);
        assertFalse(process.alive);
        assertNull(managedProcess(supervisor));
    }

    @Test
    public void failedGracefulAndForceStopRetainsHandleAndFailsClosed() throws Exception {
        ProofBridgeSupervisor supervisor = new ProofBridgeSupervisor(null);
        FakeProcess process = new FakeProcess(true, true, true);
        installProcess(supervisor, process);

        ProofBridgeSupervisor.Snapshot result = supervisor.stop();

        assertEquals(ProofBridgeSupervisor.State.FAILED, result.state);
        assertEquals(ProofBridgeSupervisor.Failure.STOP_FAILED, result.failure);
        assertEquals(1, process.stopCalls);
        assertEquals(1, process.forceStopCalls);
        assertTrue(process.alive);
        assertSame(process, managedProcess(supervisor));
    }

    private static void installProcess(ProofBridgeSupervisor supervisor,
            ProofBridgeSupervisor.ManagedProcess process) throws Exception {
        Field field = ProofBridgeSupervisor.class.getDeclaredField("process");
        field.setAccessible(true);
        field.set(supervisor, process);
    }

    private static ProofBridgeSupervisor.ManagedProcess managedProcess(
            ProofBridgeSupervisor supervisor) throws Exception {
        Field field = ProofBridgeSupervisor.class.getDeclaredField("process");
        field.setAccessible(true);
        return (ProofBridgeSupervisor.ManagedProcess) field.get(supervisor);
    }

    private static final class FakeProcess implements ProofBridgeSupervisor.ManagedProcess {
        private boolean alive;
        private final boolean failStop;
        private final boolean failForceStop;
        private int stopCalls;
        private int forceStopCalls;

        FakeProcess(boolean alive, boolean failStop, boolean failForceStop) {
            this.alive = alive;
            this.failStop = failStop;
            this.failForceStop = failForceStop;
        }

        @Override
        public boolean isAlive() {
            return alive;
        }

        @Override
        public void stop() throws ProofBridgeSupervisor.ProofBridgeException {
            stopCalls++;
            if (failStop) throw new ProofBridgeSupervisor.ProofBridgeException(
                    ProofBridgeSupervisor.Failure.STOP_FAILED);
            alive = false;
        }

        @Override
        public void forceStop() throws ProofBridgeSupervisor.ProofBridgeException {
            forceStopCalls++;
            if (failForceStop) throw new ProofBridgeSupervisor.ProofBridgeException(
                    ProofBridgeSupervisor.Failure.STOP_FAILED);
            alive = false;
        }
    }
}
