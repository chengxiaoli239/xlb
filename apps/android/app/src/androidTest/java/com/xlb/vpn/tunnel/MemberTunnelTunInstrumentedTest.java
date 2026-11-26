package com.xlb.vpn.tunnel;

import static org.junit.Assert.assertArrayEquals;
import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

import android.content.BroadcastReceiver;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.net.VpnService;
import android.os.Build;

import androidx.test.core.app.ActivityScenario;
import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;
import androidx.test.uiautomator.By;
import androidx.test.uiautomator.UiDevice;
import androidx.test.uiautomator.Until;

import com.xlb.vpn.data.DeviceProofJni;
import com.xlb.vpn.data.MemberTunnelGrant;
import com.xlb.vpn.data.MemberTunnelGrantAvdFixture;
import com.xlb.vpn.presentation.MainActivity;

import org.json.JSONObject;
import org.junit.Test;
import org.junit.runner.RunWith;

import java.io.File;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.Socket;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.UUID;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.regex.Pattern;

@RunWith(AndroidJUnit4.class)
public final class MemberTunnelTunInstrumentedTest {
    private static final String TEST_TARGET = "192.0.2.1";
    private static final int TEST_TARGET_PORT = 443;

    @Test
    public void authorizedFixturePeerCarriesTcpEchoThroughSystemTunWithoutConnectedState() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        String installationId = MemberTunnelGrantAvdFixture.newInstallationId();
        String publicKey = MemberTunnelGrantAvdFixture.publicKey(installationId);
        File peerStateDirectory = new File(context.getCacheDir(), "isolated-node-agent-" + UUID.randomUUID());
        assertTrue(peerStateDirectory.mkdirs());
        JSONObject peerRequest = new JSONObject()
                .put("node_id", MemberTunnelGrantAvdFixture.NODE_ID)
                .put("peer_id", MemberTunnelGrantAvdFixture.PEER_ID)
                .put("entitlement_id", "81000000-0000-4000-8000-000000000004")
                .put("request_id", "81000000-0000-4000-8000-000000000005")
                .put("generation", MemberTunnelGrantAvdFixture.PEER_GENERATION)
                .put("device_id", MemberTunnelGrantAvdFixture.DEVICE_ID)
                .put("device_name", "Disposable AVD system TUN")
                .put("device_public_key", publicKey)
                .put("device_key_fingerprint", MemberTunnelGrantAvdFixture.publicKeyFingerprint(publicKey))
                .put("device_binding_generation", MemberTunnelGrantAvdFixture.DEVICE_GENERATION)
                .put("peer_generation", MemberTunnelGrantAvdFixture.PEER_GENERATION)
                .put("device_generation", MemberTunnelGrantAvdFixture.DEVICE_GENERATION)
                .put("expires_at", Instant.now().plus(1, ChronoUnit.HOURS).toString())
                .put("credential", "isolated-test-credential")
                .put("state_path", new File(peerStateDirectory, "peer-store.json").getAbsolutePath());

        AtomicInteger connectedStates = new AtomicInteger();
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                if (XlbVpnService.STATE_CONNECTED.equals(intent.getStringExtra(XlbVpnService.EXTRA_STATE))) {
                    connectedStates.incrementAndGet();
                }
            }
        };
        registerReceiver(context, receiver);
        long handoffId = 0;
        boolean serviceStarted = false;
        try {
            JSONObject node = new JSONObject(DeviceProofJni.startNodeAgentFixture(peerRequest.toString()));
            assertFalse("isolated Node Agent fixture did not start", node.has("error"));
            MemberTunnelGrant grant = MemberTunnelGrantAvdFixture.createGrant(installationId, publicKey,
                    node.getString("endpoint"), node.getString("ca_pem"));
            handoffId = MemberTunnelGrantStore.publish(grant);
            requestVpnPermission(context);

            Intent startIntent = new Intent(context, XlbVpnService.class)
                    .setAction(XlbVpnService.ACTION_START_ISOLATED_MEMBER_TEST)
                    .putExtra(XlbVpnService.EXTRA_MEMBER_GRANT_HANDOFF_ID, handoffId);
            ComponentName started = startVpnService(context, startIntent);
            assertNotNull("VPN service did not start", started);
            serviceStarted = true;

            Network vpnNetwork = awaitActiveVpnNetwork(context, TimeUnit.SECONDS.toMillis(10));
            assertNotNull("Android did not expose the VPN network; state=" + XlbVpnService.currentState()
                    + ", failure=" + XlbVpnService.currentFailureCode(), vpnNetwork);
            assertTrue("isolated service did not retain its member session", XlbVpnService.currentTunnelIsMember());
            assertEquals("isolated fixture must not publish a connected state", 0, connectedStates.get());

            byte[] payload = "xlb-isolated-tun-echo".getBytes(StandardCharsets.US_ASCII);
            try (Socket activeFlow = openEchoThroughVpn(vpnNetwork, payload)) {
                JSONObject receipt = new JSONObject(DeviceProofJni.revokeNodeAgentFixturePeer());
                assertFalse("isolated Node Agent did not return a revocation receipt", receipt.has("error"));
                assertEquals("confirmed_test", receipt.getString("confirmation_status"));
                assertTrue(receipt.getLong("upload_bytes") > 0);
                assertTrue(receipt.getLong("download_bytes") > 0);
                assertEquals("active TUN TCP flow remained open after peer revocation", -1,
                        activeFlow.getInputStream().read());
            }
            assertTrue("revoked peer did not fail the active member tunnel health monitor",
                    awaitState(XlbVpnService.STATE_ERROR, TimeUnit.SECONDS.toMillis(5)));
            JSONObject rejection = new JSONObject(DeviceProofJni.nodeAgentFixtureStatus());
            assertEquals("revoked peer health probe did not reach the isolated Node Agent", 403,
                    rejection.getInt("status"));
            assertTrue(rejection.getString("body").contains("unauthorized"));
            assertFalse("revoked member tunnel still owns its session", XlbVpnService.currentTunnelIsMember());
            assertTrue("revoked member tunnel retained the Android VPN network",
                    awaitVpnNetworkGone(context, TimeUnit.SECONDS.toMillis(5)));
            assertEquals("isolated fixture must not publish a connected state", 0, connectedStates.get());
        } finally {
            if (serviceStarted) {
                startVpnService(context, new Intent(context, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP));
                awaitVpnStopped(context, TimeUnit.SECONDS.toMillis(10));
            } else if (handoffId != 0) {
                MemberTunnelGrantStore.clear();
            }
            context.unregisterReceiver(receiver);
            DeviceProofJni.stopNodeAgentFixture();
            MemberTunnelGrantAvdFixture.deleteInstallationKey(installationId);
            deleteRecursively(peerStateDirectory);
        }
    }

    @Test
    public void revocationDuringMemberStartupDoesNotStartHealthMonitorOrPublishConnected() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        String installationId = MemberTunnelGrantAvdFixture.newInstallationId();
        String publicKey = MemberTunnelGrantAvdFixture.publicKey(installationId);
        File peerStateDirectory = new File(context.getCacheDir(), "isolated-node-agent-" + UUID.randomUUID());
        assertTrue(peerStateDirectory.mkdirs());
        JSONObject peerRequest = new JSONObject()
                .put("node_id", MemberTunnelGrantAvdFixture.NODE_ID)
                .put("peer_id", MemberTunnelGrantAvdFixture.PEER_ID)
                .put("entitlement_id", "81000000-0000-4000-8000-000000000004")
                .put("request_id", "81000000-0000-4000-8000-000000000005")
                .put("generation", MemberTunnelGrantAvdFixture.PEER_GENERATION)
                .put("device_id", MemberTunnelGrantAvdFixture.DEVICE_ID)
                .put("device_name", "Disposable AVD startup revocation")
                .put("device_public_key", publicKey)
                .put("device_key_fingerprint", MemberTunnelGrantAvdFixture.publicKeyFingerprint(publicKey))
                .put("device_binding_generation", MemberTunnelGrantAvdFixture.DEVICE_GENERATION)
                .put("peer_generation", MemberTunnelGrantAvdFixture.PEER_GENERATION)
                .put("device_generation", MemberTunnelGrantAvdFixture.DEVICE_GENERATION)
                .put("expires_at", Instant.now().plus(1, ChronoUnit.HOURS).toString())
                .put("credential", "isolated-test-credential")
                .put("state_path", new File(peerStateDirectory, "peer-store.json").getAbsolutePath());

        AtomicInteger connectedStates = new AtomicInteger();
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                if (XlbVpnService.STATE_CONNECTED.equals(intent.getStringExtra(XlbVpnService.EXTRA_STATE))) {
                    connectedStates.incrementAndGet();
                }
            }
        };
        registerReceiver(context, receiver);
        long handoffId = 0;
        boolean serviceStarted = false;
        try {
            JSONObject node = new JSONObject(DeviceProofJni.startNodeAgentFixture(peerRequest.toString()));
            assertFalse("isolated Node Agent fixture did not start", node.has("error"));
            MemberTunnelGrant grant = MemberTunnelGrantAvdFixture.createGrant(installationId, publicKey,
                    node.getString("endpoint"), node.getString("ca_pem"));
            handoffId = MemberTunnelGrantStore.publish(grant);
            requestVpnPermission(context);
            DeviceProofJni.armNodeAgentFixtureEchoGate();

            Intent startIntent = new Intent(context, XlbVpnService.class)
                    .setAction(XlbVpnService.ACTION_START_ISOLATED_MEMBER_TEST)
                    .putExtra(XlbVpnService.EXTRA_MEMBER_GRANT_HANDOFF_ID, handoffId);
            ComponentName started = startVpnService(context, startIntent);
            assertNotNull("VPN service did not start", started);
            serviceStarted = true;

            assertTrue("member startup did not reach its blocked route probe",
                    DeviceProofJni.awaitNodeAgentFixtureEchoGate(TimeUnit.SECONDS.toMillis(10)));
            assertEquals("startup probe did not enter the isolated peer exactly once", 1,
                    DeviceProofJni.nodeAgentFixtureEchoTargetDialCount());

            JSONObject receipt = new JSONObject(DeviceProofJni.revokeNodeAgentFixturePeer());
            assertFalse("isolated Node Agent did not return a revocation receipt", receipt.has("error"));
            assertEquals("confirmed_test", receipt.getString("confirmation_status"));

            startVpnService(context, new Intent(context, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP));
            DeviceProofJni.releaseNodeAgentFixtureEchoGate();
            awaitVpnStopped(context, TimeUnit.SECONDS.toMillis(10));
            assertEquals("revoked startup published a connected state", 0, connectedStates.get());
            assertFalse("revoked startup retained a member session", XlbVpnService.currentTunnelIsMember());
            assertEquals("revoked startup retained its VPN route", "", XlbVpnService.currentRouteName());

            int probesAfterStop = DeviceProofJni.nodeAgentFixtureEchoTargetDialCount();
            Thread.sleep(1_250);
            assertEquals("revoked startup continued probing after stop completed", probesAfterStop,
                    DeviceProofJni.nodeAgentFixtureEchoTargetDialCount());
        } finally {
            DeviceProofJni.releaseNodeAgentFixtureEchoGate();
            if (serviceStarted) {
                startVpnService(context, new Intent(context, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP));
                awaitVpnStopped(context, TimeUnit.SECONDS.toMillis(10));
            } else if (handoffId != 0) {
                MemberTunnelGrantStore.clear();
            }
            context.unregisterReceiver(receiver);
            DeviceProofJni.stopNodeAgentFixture();
            MemberTunnelGrantAvdFixture.deleteInstallationKey(installationId);
            deleteRecursively(peerStateDirectory);
        }
    }

    private static void requestVpnPermission(Context context) throws Exception {
        Intent approval = VpnService.prepare(context);
        if (approval == null) return;

        try (ActivityScenario<MainActivity> scenario = ActivityScenario.launch(MainActivity.class)) {
            scenario.onActivity(activity -> activity.startActivityForResult(approval, 7814));
            UiDevice device = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation());
            Pattern approvalLabel = Pattern.compile("(?i)^(allow|ok|允许)$");
            assertTrue("VPN permission dialog did not appear on the disposable AVD",
                    device.wait(Until.hasObject(By.text(approvalLabel)), TimeUnit.SECONDS.toMillis(15)));
            device.findObject(By.text(approvalLabel)).click();
            long deadline = System.currentTimeMillis() + TimeUnit.SECONDS.toMillis(10);
            while (VpnService.prepare(context) != null && System.currentTimeMillis() < deadline) {
                Thread.sleep(100);
            }
            assertEquals("VPN permission was not granted on the disposable AVD", null, VpnService.prepare(context));
        }
    }

    private static ComponentName startVpnService(Context context, Intent intent) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                && !XlbVpnService.ACTION_STOP.equals(intent.getAction())) {
            return context.startForegroundService(intent);
        }
        return context.startService(intent);
    }

    private static Network awaitActiveVpnNetwork(Context context, long timeoutMillis) throws Exception {
        ConnectivityManager manager = (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
        long deadline = System.currentTimeMillis() + timeoutMillis;
        while (System.currentTimeMillis() < deadline) {
            Network network = manager == null ? null : manager.getActiveNetwork();
            NetworkCapabilities capabilities = network == null || manager == null
                    ? null : manager.getNetworkCapabilities(network);
            if (capabilities != null && capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN)) {
                return network;
            }
            Thread.sleep(25);
        }
        return null;
    }

    private static boolean hasActiveVpnNetwork(Context context) {
        ConnectivityManager manager = (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
        Network network = manager == null ? null : manager.getActiveNetwork();
        NetworkCapabilities capabilities = network == null || manager == null
                ? null : manager.getNetworkCapabilities(network);
        return capabilities != null && capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN);
    }

    private static boolean awaitVpnNetworkGone(Context context, long timeoutMillis) throws Exception {
        long deadline = System.currentTimeMillis() + timeoutMillis;
        while (System.currentTimeMillis() < deadline) {
            if (!hasActiveVpnNetwork(context)) return true;
            Thread.sleep(25);
        }
        return !hasActiveVpnNetwork(context);
    }

    private static boolean awaitState(String expectedState, long timeoutMillis) throws Exception {
        long deadline = System.currentTimeMillis() + timeoutMillis;
        while (System.currentTimeMillis() < deadline) {
            if (expectedState.equals(XlbVpnService.currentState())) return true;
            Thread.sleep(25);
        }
        return expectedState.equals(XlbVpnService.currentState());
    }

    private static Socket openEchoThroughVpn(Network network, byte[] payload) throws Exception {
        Socket socket = network.getSocketFactory().createSocket();
        try {
            socket.connect(new InetSocketAddress(TEST_TARGET, TEST_TARGET_PORT), 3_000);
            socket.setSoTimeout(3_000);
            OutputStream output = socket.getOutputStream();
            InputStream input = socket.getInputStream();
            output.write(payload);
            output.flush();
            byte[] response = new byte[payload.length];
            int offset = 0;
            while (offset < response.length) {
                int count = input.read(response, offset, response.length - offset);
                if (count < 0) break;
                offset += count;
            }
            assertEquals("TCP echo through the isolated peer was incomplete", payload.length, offset);
            assertArrayEquals("TCP bytes did not return through system TUN, Mihomo, proof bridge, and fixture peer",
                    payload, response);
            return socket;
        } catch (Exception exception) {
            socket.close();
            throw exception;
        }
    }

    private static void awaitVpnStopped(Context context, long timeoutMillis) throws Exception {
        long deadline = System.currentTimeMillis() + timeoutMillis;
        while (XlbVpnService.currentTunnelIsMember()
                || XlbVpnService.STATE_CONNECTED.equals(XlbVpnService.currentState())) {
            if (System.currentTimeMillis() >= deadline) {
                throw new AssertionError("VPN service did not leave its active state after stop");
            }
            Thread.sleep(50);
        }
        ConnectivityManager manager = (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
        while (manager != null && System.currentTimeMillis() < deadline) {
            Network network = manager.getActiveNetwork();
            NetworkCapabilities capabilities = network == null ? null : manager.getNetworkCapabilities(network);
            if (capabilities == null || !capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN)) return;
            Thread.sleep(50);
        }
        if (manager != null) throw new AssertionError("Android retained the VPN network after stop");
        assertFalse("isolated tunnel stop left a pending member grant", MemberTunnelGrantStore.hasPendingHandoff());
    }

    private static void registerReceiver(Context context, BroadcastReceiver receiver) {
        IntentFilter filter = new IntentFilter(XlbVpnService.ACTION_STATE);
        if (Build.VERSION.SDK_INT >= 33) {
            context.registerReceiver(receiver, filter, XlbVpnService.INTERNAL_PERMISSION, null,
                    Context.RECEIVER_NOT_EXPORTED);
            return;
        }
        context.registerReceiver(receiver, filter, XlbVpnService.INTERNAL_PERMISSION, null);
    }

    private static void requireDisposableEmulator() {
        String fingerprint = Build.FINGERPRINT.toLowerCase(java.util.Locale.ROOT);
        assertTrue("System TUN instrumentation requires the dedicated disposable emulator",
                "ranchu".equals(Build.HARDWARE) || "goldfish".equals(Build.HARDWARE)
                        || fingerprint.contains("sdk_gphone"));
        assertEquals("Set disposable_avd=true only for a disposable emulator", "true",
                InstrumentationRegistry.getArguments().getString("disposable_avd", "false"));
        assertFalse("Production member gate must remain closed for this isolated test",
                MemberTunnelAdmissionPolicy.MEMBER_PROOF_BRIDGE_INTEGRATED);
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
}
