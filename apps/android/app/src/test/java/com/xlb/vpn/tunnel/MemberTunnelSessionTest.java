package com.xlb.vpn.tunnel;

import com.xlb.vpn.data.MemberTunnelGrant;
import com.xlb.vpn.data.MemberTunnelGrantTestFixture;

import org.junit.Test;

import java.util.ArrayList;
import java.util.List;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;
import static org.junit.Assert.fail;

public final class MemberTunnelSessionTest {
    @Test
    public void startsBridgeProfileAndTunnelInOrderThenStopsAndClearsResources() throws Exception {
        FakeRuntime runtime = new FakeRuntime(null, false);
        MemberTunnelSession session = new MemberTunnelSession(runtime);

        session.start(grant());

        assertTrue(session.isStarted());
        assertEquals(List.of("certificate", "bridge", "profile", "tun", "verify"), runtime.events);

        session.close();
        session.close();

        assertFalse(session.isStarted());
        assertEquals(List.of("certificate", "bridge", "profile", "tun", "verify",
                "stop-tun", "stop-bridge", "clear-credentials", "close-certificate"), runtime.events);
        try {
            session.start(grant());
            fail("closed session must not be reused");
        } catch (IllegalStateException expected) {
            assertEquals("member tunnel session is closed", expected.getMessage());
        }
    }

    @Test
    public void anyStartupFailureTearsDownTunnelBridgeCredentialsAndCertificate() throws Exception {
        for (String failureStage : new String[]{"certificate", "bridge", "profile", "tun", "verify"}) {
            FakeRuntime runtime = new FakeRuntime(failureStage, false);
            MemberTunnelSession session = new MemberTunnelSession(runtime);

            try {
                session.start(grant());
                fail("expected startup failure at " + failureStage);
            } catch (IllegalStateException expected) {
                assertEquals(failureStage, expected.getMessage());
            }

            assertFalse("session started at " + failureStage, session.isStarted());
            assertTrue("TUN/core not stopped at " + failureStage, runtime.events.contains("stop-tun"));
            assertTrue("bridge not stopped at " + failureStage, runtime.events.contains("stop-bridge"));
            if (runtime.events.contains("profile") || runtime.events.contains("tun")
                    || runtime.events.contains("verify")) {
                assertTrue("credentials not cleared at " + failureStage, runtime.events.contains("clear-credentials"));
            }
            if (!failureStage.equals("certificate")) {
                assertTrue("certificate not closed at " + failureStage, runtime.events.contains("close-certificate"));
            }
        }
    }

    @Test
    public void cleanupAttemptsEveryResourceEvenWhenStoppingTunnelFails() throws Exception {
        FakeRuntime runtime = new FakeRuntime(null, true);
        MemberTunnelSession session = new MemberTunnelSession(runtime);
        session.start(grant());

        try {
            session.close();
            fail("expected TUN cleanup failure");
        } catch (IllegalStateException expected) {
            assertEquals("stop-tun", expected.getMessage());
        }

        assertTrue(runtime.events.contains("stop-bridge"));
        assertTrue(runtime.events.contains("clear-credentials"));
        assertTrue(runtime.events.contains("close-certificate"));
        assertFalse(session.isStarted());

        session.close();
        assertEquals(2L, runtime.events.stream().filter("stop-tun"::equals).count());
        assertEquals(1L, runtime.events.stream().filter("stop-bridge"::equals).count());
        try {
            session.start(grant());
            fail("closed session must not be reused");
        } catch (IllegalStateException expected) {
            assertEquals("member tunnel session is closed", expected.getMessage());
        }
    }

    private static MemberTunnelGrant grant() {
        return MemberTunnelGrantTestFixture.create();
    }

    private static final class FakeRuntime implements MemberTunnelSession.Runtime {
        private final String failureStage;
        private boolean failTunnelCleanup;
        private final List<String> events = new ArrayList<>();

        FakeRuntime(String failureStage, boolean failTunnelCleanup) {
            this.failureStage = failureStage;
            this.failTunnelCleanup = failTunnelCleanup;
        }

        @Override
        public MemberTunnelSession.ProofHandle createDeviceCertificate(MemberTunnelGrant grant) {
            step("certificate");
            return () -> events.add("close-certificate");
        }

        @Override
        public AutoCloseable startBridge(MemberTunnelSession.ProofHandle certificate, MemberTunnelGrant grant) {
            step("bridge");
            return () -> events.add("clear-credentials");
        }

        @Override
        public void loadMemberProfile(MemberTunnelGrant grant, AutoCloseable credentials) {
            step("profile");
        }

        @Override
        public void establishTun() {
            step("tun");
        }

        @Override
        public void verifyTunnel() {
            step("verify");
        }

        @Override
        public void stopTunAndCore() {
            events.add("stop-tun");
            if (failTunnelCleanup) {
                failTunnelCleanup = false;
                throw new IllegalStateException("stop-tun");
            }
        }

        @Override
        public void stopBridge() {
            events.add("stop-bridge");
        }

        private void step(String name) {
            events.add(name);
            if (name.equals(failureStage)) throw new IllegalStateException(name);
        }
    }
}
