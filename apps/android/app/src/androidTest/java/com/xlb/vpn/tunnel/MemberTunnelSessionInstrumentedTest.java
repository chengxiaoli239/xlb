package com.xlb.vpn.tunnel;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;

import com.xlb.vpn.data.DeviceProofCertificate;
import com.xlb.vpn.data.DeviceProofJni;
import com.xlb.vpn.data.MemberDeviceProof;
import com.xlb.vpn.data.MemberTunnelGrant;
import com.xlb.vpn.data.MemberTunnelGrantAvdFixture;

import org.json.JSONObject;
import org.junit.Test;
import org.junit.runner.RunWith;

import java.io.File;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.UUID;

@RunWith(AndroidJUnit4.class)
public final class MemberTunnelSessionInstrumentedTest {
    @Test
    public void serverAuthorizedGrantLoadsAuthenticatedProfileAndClosesNativeBridgeResources() throws Exception {
        requireDisposableEmulator();
        String installationId = MemberTunnelGrantAvdFixture.newInstallationId();
        String publicKey = MemberTunnelGrantAvdFixture.publicKey(installationId);
        File cacheDirectory = InstrumentationRegistry.getInstrumentation().getTargetContext().getCacheDir();
        File peerStateDirectory = new File(cacheDirectory, "isolated-node-agent-" + UUID.randomUUID());
        File mihomoHome = new File(cacheDirectory, "mihomo-member-session-" + UUID.randomUUID());
        assertTrue(peerStateDirectory.mkdirs());
        assertTrue(mihomoHome.mkdirs());
        JSONObject peerRequest = new JSONObject()
                .put("node_id", MemberTunnelGrantAvdFixture.NODE_ID)
                .put("peer_id", MemberTunnelGrantAvdFixture.PEER_ID)
                .put("entitlement_id", "81000000-0000-4000-8000-000000000004")
                .put("request_id", "81000000-0000-4000-8000-000000000005")
                .put("generation", MemberTunnelGrantAvdFixture.PEER_GENERATION)
                .put("device_id", MemberTunnelGrantAvdFixture.DEVICE_ID)
                .put("device_name", "Disposable AVD member session")
                .put("device_public_key", publicKey)
                .put("device_key_fingerprint", MemberTunnelGrantAvdFixture.publicKeyFingerprint(publicKey))
                .put("device_binding_generation", MemberTunnelGrantAvdFixture.DEVICE_GENERATION)
                .put("peer_generation", MemberTunnelGrantAvdFixture.PEER_GENERATION)
                .put("device_generation", MemberTunnelGrantAvdFixture.DEVICE_GENERATION)
                .put("expires_at", Instant.now().plus(1, ChronoUnit.HOURS).toString())
                .put("credential", "isolated-test-credential")
                .put("state_path", new File(peerStateDirectory, "peer-store.json").getAbsolutePath());

        MemberTunnelSession session = null;
        NativeMemberTunnelRuntime runtime = new NativeMemberTunnelRuntime(mihomoHome);
        try {
            JSONObject node = new JSONObject(DeviceProofJni.startNodeAgentFixture(peerRequest.toString()));
            assertFalse("isolated Node Agent fixture did not start", node.has("error"));
            MemberTunnelGrant grant = MemberTunnelGrantAvdFixture.createGrant(installationId, publicKey,
                    node.getString("endpoint"), node.getString("ca_pem"));
            session = new MemberTunnelSession(runtime);

            session.start(grant);

            assertTrue(session.isStarted());
            assertTrue(runtime.bridgeRunning());
            session.close();
            assertFalse(session.isStarted());
            assertFalse(runtime.bridgeRunning());
            assertEquals(1, runtime.profileLoadCount);
            assertEquals(1, runtime.testTunnelSetupCount);
            assertEquals(1, runtime.testTunnelVerificationCount);
            assertEquals(1, runtime.coreStopCount);
        } finally {
            if (session != null) session.close();
            DeviceProofJni.stopNodeAgentFixture();
            MemberTunnelGrantAvdFixture.deleteInstallationKey(installationId);
            deleteRecursively(peerStateDirectory);
            deleteRecursively(mihomoHome);
        }
    }

    private static void requireDisposableEmulator() {
        String hardware = android.os.Build.HARDWARE;
        String fingerprint = android.os.Build.FINGERPRINT.toLowerCase(java.util.Locale.ROOT);
        assertTrue("Member tunnel session instrumentation requires a disposable emulator",
                "ranchu".equals(hardware) || "goldfish".equals(hardware) || fingerprint.contains("sdk_gphone"));
        assertEquals("Set disposable_avd=true only for a disposable emulator", "true",
                InstrumentationRegistry.getArguments().getString("disposable_avd", "false"));
    }

    private static void deleteRecursively(File file) {
        if (file.isDirectory()) {
            File[] children = file.listFiles();
            if (children != null) {
                for (File child : children) deleteRecursively(child);
            }
        }
        if (file.exists()) file.delete();
    }

    private static final class NativeMemberTunnelRuntime implements MemberTunnelSession.Runtime {
        private final File home;
        private DeviceProofCertificate certificate;
        private DeviceProofCertificate.BridgeCredentials credentials;
        private int profileLoadCount;
        private int testTunnelSetupCount;
        private int testTunnelVerificationCount;
        private int coreStopCount;

        private NativeMemberTunnelRuntime(File home) {
            this.home = home;
        }

        @Override
        public MemberTunnelSession.ProofHandle createDeviceCertificate(MemberTunnelGrant grant) throws Exception {
            MihomoCore.initialize(home, fd -> true);
            certificate = DeviceProofCertificate.create(grant.deviceProof());
            return certificate::close;
        }

        @Override
        public AutoCloseable startBridge(MemberTunnelSession.ProofHandle ignored, MemberTunnelGrant grant)
                throws Exception {
            credentials = certificate.startProofBridge(grant.bridgeEndpoint(), grant.trustedServerCaPem());
            return credentials::clear;
        }

        @Override
        public void loadMemberProfile(MemberTunnelGrant grant, AutoCloseable ignored) {
            MihomoCore.loadMemberProfile(grant.profile(), credentials.usernameForProfile(),
                    credentials.passwordForProfile());
            profileLoadCount++;
        }

        @Override
        public void establishTun() {
            testTunnelSetupCount++;
        }

        @Override
        public void verifyTunnel() {
            testTunnelVerificationCount++;
        }

        @Override
        public void stopTunAndCore() {
            MihomoCore.stop();
            coreStopCount++;
        }

        @Override
        public void stopBridge() {
            if (certificate != null) certificate.stopProofBridge();
        }

        private boolean bridgeRunning() {
            return certificate != null && certificate.proofBridgeRunning();
        }
    }
}
