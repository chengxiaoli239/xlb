package com.xlb.vpn.tunnel;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertArrayEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

import android.content.BroadcastReceiver;
import android.content.ComponentName;
import android.content.Context;
import android.content.ContextWrapper;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.net.VpnService;
import android.os.Build;
import android.os.SystemClock;
import android.app.Activity;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.core.app.ActivityScenario;
import androidx.test.platform.app.InstrumentationRegistry;
import androidx.test.uiautomator.By;
import androidx.test.uiautomator.UiDevice;
import androidx.test.uiautomator.Until;

import com.xlb.vpn.data.DeviceProofJni;
import com.xlb.vpn.data.DeviceProofCertificate;
import com.xlb.vpn.data.MemberTunnelGrant;
import com.xlb.vpn.data.MemberTunnelGrantAvdFixture;
import com.xlb.vpn.data.PlatformApi;
import com.xlb.vpn.data.SecureSessionStore;
import com.xlb.vpn.presentation.MainActivity;

import org.json.JSONArray;
import org.json.JSONObject;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.junit.Assume;

import java.io.ByteArrayOutputStream;
import java.io.Closeable;
import java.io.File;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.InetAddress;
import java.net.ServerSocket;
import java.net.Socket;
import java.nio.charset.StandardCharsets;
import java.security.KeyFactory;
import java.security.Signature;
import java.security.spec.X509EncodedKeySpec;
import java.util.Base64;
import java.util.Locale;
import java.util.UUID;
import java.util.regex.Pattern;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicReference;

@RunWith(AndroidJUnit4.class)
public final class PlatformApiMemberGrantInstrumentedTest {
    private static final String TEST_TARGET = "192.0.2.1";
    private static final int TEST_TARGET_PORT = 443;
    private static final String SERVER_REQUEST_ID = "a12b3456-7890-4abc-8def-0123456789ab";

    @Test
    public void apiLoginFailureShowsOnlyAValidServerRequestReference() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        SecureSessionStore sessionStore = new SecureSessionStore(context);
        FakeMemberApi server = new FakeMemberApi(MemberTunnelGrantAvdFixture.trustedSystemCaPem());
        try {
            PlatformApi api = new PlatformApi("http://127.0.0.1:" + server.port(), sessionStore);

            try {
                api.login("member@example.invalid", "test-only-password");
                throw new AssertionError("fake API login should fail");
            } catch (PlatformApi.PlatformApiException exception) {
                assertEquals("session_expired", exception.diagnosticCode);
                assertTrue(exception.getMessage().contains("服务请求编号 " + SERVER_REQUEST_ID));
            }
        } finally {
            server.close();
        }
    }

    @Test
    public void synchronizedServerGrantCarriesTunTrafficAndRevocationReceipt() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        SharedPreferences preferences = context.getSharedPreferences("secure_client_state", Context.MODE_PRIVATE);
        SharedPreferences copiedPreferences = context.getSharedPreferences(
                "secure_client_state_copy_fixture", Context.MODE_PRIVATE);
        assertTrue("could not clear disposable emulator session state", preferences.edit().clear().commit());
        assertTrue("could not clear copied-installation fixture state",
                copiedPreferences.edit().clear().commit());
        SecureSessionStore sessionStore = new SecureSessionStore(context);
        sessionStore.updateCookie("xlb_session", "isolated-member-session", false);
        String installationId = sessionStore.installationId();
        String publicKey = MemberTunnelGrantAvdFixture.publicKey(installationId);
        String copiedInstallationId = null;
        File stateDirectory = new File(context.getCacheDir(), "isolated-node-agent-" + UUID.randomUUID());
        assertTrue(stateDirectory.mkdirs());

        JSONObject peerRequest = new JSONObject()
                .put("node_id", MemberTunnelGrantAvdFixture.NODE_ID)
                .put("peer_id", MemberTunnelGrantAvdFixture.PEER_ID)
                .put("entitlement_id", "81000000-0000-4000-8000-000000000004")
                .put("request_id", UUID.randomUUID().toString())
                .put("generation", MemberTunnelGrantAvdFixture.PEER_GENERATION)
                .put("device_id", MemberTunnelGrantAvdFixture.DEVICE_ID)
                .put("device_name", "Disposable authorized grant TUN")
                .put("device_public_key", publicKey)
                .put("device_key_fingerprint", MemberTunnelGrantAvdFixture.publicKeyFingerprint(publicKey))
                .put("device_binding_generation", MemberTunnelGrantAvdFixture.DEVICE_GENERATION)
                .put("peer_generation", MemberTunnelGrantAvdFixture.PEER_GENERATION)
                .put("device_generation", MemberTunnelGrantAvdFixture.DEVICE_GENERATION)
                .put("expires_at", java.time.Instant.now().plus(1, java.time.temporal.ChronoUnit.HOURS).toString())
                .put("credential", "isolated-test-credential")
                .put("state_path", new File(stateDirectory, "peer-store.json").getAbsolutePath());

        AtomicInteger connectedStates = new AtomicInteger();
        AtomicReference<String> lastError = new AtomicReference<>();
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                String state = intent.getStringExtra(XlbVpnService.EXTRA_STATE);
                if (XlbVpnService.STATE_CONNECTED.equals(state)) connectedStates.incrementAndGet();
                if (XlbVpnService.STATE_ERROR.equals(state)) {
                    lastError.set(intent.getStringExtra(XlbVpnService.EXTRA_MESSAGE));
                }
            }
        };
        registerReceiver(context, receiver);
        boolean serviceStarted = false;
        FakeMemberApi server = null;
        try {
            JSONObject node = new JSONObject(DeviceProofJni.startNodeAgentFixture(peerRequest.toString()));
            assertFalse("isolated Node Agent fixture did not start: " + node, node.has("error"));
            server = new FakeMemberApi(node.getString("ca_pem"));
            server.setBridgeEndpoint(node.getString("endpoint"));
            PlatformApi api = new PlatformApi("http://127.0.0.1:" + server.port(), sessionStore);
            PlatformApi.MemberAccount account = new PlatformApi.MemberAccount(
                    "member-avd", "Disposable member", "member@example.invalid", "member");

            PlatformApi.ClientSnapshot snapshot = api.synchronize(account);
            MemberTunnelGrant grant = api.verifiedMemberTunnelGrant();
            assertEquals(PlatformApi.NodeAuthorizationStatus.READY, snapshot.nodeAuthorizationStatus);
            assertNotNull("PlatformApi did not produce a verified grant from the signed challenge", grant);
            assertTrue("fake API did not verify the Android Keystore challenge signature",
                    server.challengeSignatureVerified());
            assertFalse("fake API observed an invalid request: " + server.failureMessage(), server.hasFailure());

            Context copiedContext = new ContextWrapper(context) {
                @Override
                public SharedPreferences getSharedPreferences(String name, int mode) {
                    return context.getSharedPreferences("secure_client_state_copy_fixture", mode);
                }
            };
            SecureSessionStore copiedSessionStore = new SecureSessionStore(copiedContext);
            copiedSessionStore.updateCookie("xlb_session", "isolated-member-session", false);
            copiedInstallationId = copiedSessionStore.installationId();
            String copiedPublicKey = MemberTunnelGrantAvdFixture.publicKey(copiedInstallationId);
            assertFalse("copied installation reused the authorized device public key",
                    publicKey.equals(copiedPublicKey));
            PlatformApi copiedApi = new PlatformApi("http://127.0.0.1:" + server.port(), copiedSessionStore);
            PlatformApi.ClientSnapshot copiedSnapshot = copiedApi.synchronize(account);
            MemberTunnelGrant copiedGrant = copiedApi.verifiedMemberTunnelGrant();
            assertEquals(PlatformApi.NodeAuthorizationStatus.READY, copiedSnapshot.nodeAuthorizationStatus);
            assertNotNull("second installation did not obtain its own signed test grant", copiedGrant);
            assertTrue("fake API did not verify the second installation challenge signature",
                    server.challengeSignatureVerified());

            DeviceProofCertificate copiedCertificate = DeviceProofCertificate.create(copiedGrant.deviceProof());
            try {
                DeviceProofCertificate.BridgeCredentials copiedCredentials = copiedCertificate.startProofBridge(
                        copiedGrant.bridgeEndpoint(), copiedGrant.trustedServerCaPem());
                try (Socket copiedConnection = new Socket("127.0.0.1", 17890)) {
                    copiedConnection.setSoTimeout(5_000);
                    negotiateSocksUserPassword(copiedConnection, copiedCredentials);
                    byte[] host = "bridge.test".getBytes(StandardCharsets.US_ASCII);
                    copiedConnection.getOutputStream().write(new byte[]{5, 1, 0, 3, (byte) host.length});
                    copiedConnection.getOutputStream().write(host);
                    copiedConnection.getOutputStream().write(new byte[]{1, (byte) 187});
                    byte[] response = new byte[10];
                    readFully(copiedConnection.getInputStream(), response);
                    assertEquals("Node Agent accepted a second installation key for the first device peer",
                            5, response[1]);
                    JSONObject rejection = new JSONObject(DeviceProofJni.nodeAgentFixtureStatus());
                    assertEquals(403, rejection.getInt("status"));
                    assertTrue(rejection.getString("body").contains("unauthorized"));
                }
            } finally {
                copiedCertificate.close();
                assertTrue("copied peer connection did not release its Agent session",
                        awaitNoActiveFixtureSessions(TimeUnit.SECONDS.toMillis(5)));
            }

            requestVpnPermission(context);
            long handoffId = MemberTunnelGrantStore.publish(grant);
            Intent startIntent = new Intent(context, XlbVpnService.class)
                    .setAction(XlbVpnService.ACTION_START_ISOLATED_MEMBER_TEST)
                    .putExtra(XlbVpnService.EXTRA_MEMBER_GRANT_HANDOFF_ID, handoffId);
            ComponentName started = startVpnService(context, startIntent);
            assertNotNull("VPN service did not start", started);
            serviceStarted = true;

            Network vpnNetwork = awaitActiveVpnNetwork(context, TimeUnit.SECONDS.toMillis(10));
            assertNotNull("Android did not expose the VPN network; state=" + XlbVpnService.currentState()
                    + ", failure=" + XlbVpnService.currentFailureCode() + ", message=" + lastError.get(), vpnNetwork);
            assertTrue("service did not retain the server-authorized member session",
                    XlbVpnService.currentTunnelIsMember());
            assertEquals("isolated service must not publish a production connected state", 0,
                    connectedStates.get());

            byte[] payload = "xlb-authorized-api-grant-tun-echo".getBytes(StandardCharsets.US_ASCII);
            try (Socket activeFlow = openEchoThroughVpn(vpnNetwork, payload)) {
                JSONObject receipt = new JSONObject(DeviceProofJni.revokeNodeAgentFixturePeer());
                assertFalse("isolated Node Agent did not return a revocation receipt", receipt.has("error"));
                assertEquals("confirmed_test", receipt.getString("confirmation_status"));
                assertTrue("revocation receipt did not include uploaded traffic", receipt.getLong("upload_bytes") > 0);
                assertTrue("revocation receipt did not include downloaded traffic", receipt.getLong("download_bytes") > 0);
                assertEquals("active TUN flow remained open after peer revocation", -1,
                        activeFlow.getInputStream().read());
            }
            assertTrue("revoked peer did not fail the member tunnel health monitor",
                    awaitState(XlbVpnService.STATE_ERROR, TimeUnit.SECONDS.toMillis(5)));
            JSONObject rejection = new JSONObject(DeviceProofJni.nodeAgentFixtureStatus());
            assertEquals("revoked peer health probe did not reach the isolated Node Agent", 403,
                    rejection.getInt("status"));
            assertTrue(rejection.getString("body").contains("unauthorized"));
            assertFalse("revoked member tunnel still owns its session", XlbVpnService.currentTunnelIsMember());
            assertTrue("revoked member tunnel retained the Android VPN network",
                    awaitVpnNetworkGone(context, TimeUnit.SECONDS.toMillis(5)));
            assertEquals("isolated service must not publish a production connected state", 0,
                    connectedStates.get());
            assertFalse("fake API observed an invalid request: " + server.failureMessage(), server.hasFailure());
        } finally {
            if (serviceStarted) {
                startVpnService(context, new Intent(context, XlbVpnService.class)
                        .setAction(XlbVpnService.ACTION_STOP));
                awaitVpnStopped(context, TimeUnit.SECONDS.toMillis(10));
            } else {
                MemberTunnelGrantStore.clear();
            }
            context.unregisterReceiver(receiver);
            sessionStore.clearSession();
            if (server != null) server.close();
            DeviceProofJni.stopNodeAgentFixture();
            if (copiedInstallationId != null) {
                MemberTunnelGrantAvdFixture.deleteInstallationKey(copiedInstallationId);
            }
            MemberTunnelGrantAvdFixture.deleteInstallationKey(installationId);
            deleteRecursively(stateDirectory);
            assertTrue("could not clear copied-installation fixture state",
                    copiedPreferences.edit().clear().commit());
            assertTrue("could not clear disposable emulator session state", preferences.edit().clear().commit());
        }
    }

    @Test
    public void approvedPermissionCallbackRevalidatesGrantAfterActivityRecreation() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        SharedPreferences preferences = context.getSharedPreferences("secure_client_state", Context.MODE_PRIVATE);
        assertTrue("could not clear disposable emulator session state", preferences.edit().clear().commit());
        String installationId = null;
        FakeMemberApi server = new FakeMemberApi(MemberTunnelGrantAvdFixture.trustedSystemCaPem(), 4175);
        SecureSessionStore sessionStore = new SecureSessionStore(context);
        sessionStore.updateCookie("xlb_session", "isolated-member-session", false);
        PlatformApi api = new PlatformApi("http://127.0.0.1:4175", sessionStore);
        PlatformApi.MemberAccount account = new PlatformApi.MemberAccount(
                "member-avd", "Disposable member", "member@example.invalid", "member");
        CountDownLatch rejected = new CountDownLatch(1);
        AtomicReference<String> serviceMessage = new AtomicReference<>();
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                if (XlbVpnService.STATE_ERROR.equals(intent.getStringExtra(XlbVpnService.EXTRA_STATE))) {
                    serviceMessage.set(intent.getStringExtra(XlbVpnService.EXTRA_MESSAGE));
                    rejected.countDown();
                }
            }
        };
        registerReceiver(context, receiver);

        try {
            PlatformApi.ClientSnapshot initialSnapshot = api.synchronize(account);
            MemberTunnelGrant originalGrant = api.verifiedMemberTunnelGrant();
            installationId = sessionStore.installationId();
            assertEquals(PlatformApi.NodeAuthorizationStatus.READY, initialSnapshot.nodeAuthorizationStatus);
            assertNotNull("test setup did not produce a verified member grant", originalGrant);

            try (ActivityScenario<MainActivity> scenario = ActivityScenario.launch(MainActivity.class)) {
                assertTrue("MainActivity did not synchronize the fake member API",
                        awaitApiAuthorizationCount(server, 2));
                scenario.onActivity(activity -> {
                    setActivityField(activity, "pendingMemberVpnPermission", true);
                    setActivityField(activity, "pendingMemberGrant", originalGrant);
                });

                scenario.recreate();

                assertTrue("recreated MainActivity did not finish its initial authorization sync",
                        awaitActivitySnapshot(scenario));
                int authorizationsBeforeCallback = server.authorizationRequestCount();
                scenario.onActivity(activity -> {
                    assertTrue("permission request marker was not restored",
                            readBooleanField(activity, "pendingMemberVpnPermission"));
                    assertNull("process-local grant was incorrectly restored", readField(activity, "pendingMemberGrant"));
                    invokePermissionResult(activity, Activity.RESULT_OK);
                });

                assertTrue("permission callback did not obtain a fresh server authorization",
                        awaitApiAuthorizationCount(server, authorizationsBeforeCallback + 1));
                assertTrue("fresh grant did not reach the VPN service's fail-closed gate",
                        rejected.await(5, TimeUnit.SECONDS));
                assertEquals("会员安全线路尚未接入", serviceMessage.get());
                assertTrue("fake API did not validate a device proof after re-authorization",
                        server.challengeSignatureVerified());
                assertFalse("closed member gate left the service marked as a member tunnel",
                        XlbVpnService.currentTunnelIsMember());
                assertFalse("fake API observed an invalid request: " + server.failureMessage(), server.hasFailure());
            }
        } finally {
            MemberTunnelGrantStore.clear();
            sessionStore.clearSession();
            context.unregisterReceiver(receiver);
            context.stopService(new Intent(context, XlbVpnService.class));
            if (installationId != null) MemberTunnelGrantAvdFixture.deleteInstallationKey(installationId);
            assertTrue("could not clear disposable emulator session state", preferences.edit().clear().commit());
            server.close();
        }
    }

    @Test
    public void deniedPermissionCallbackDoesNotReauthorizeOrStartMemberService() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        Assume.assumeTrue("this regression requires an AVD without existing VPN consent",
                VpnService.prepare(context) != null);
        SharedPreferences preferences = context.getSharedPreferences("secure_client_state", Context.MODE_PRIVATE);
        assertTrue("could not clear disposable emulator session state", preferences.edit().clear().commit());
        FakeMemberApi server = new FakeMemberApi(MemberTunnelGrantAvdFixture.trustedSystemCaPem(), 4175);
        SecureSessionStore sessionStore = new SecureSessionStore(context);
        sessionStore.updateCookie("xlb_session", "isolated-member-session", false);
        PlatformApi api = new PlatformApi("http://127.0.0.1:4175", sessionStore);
        PlatformApi.MemberAccount account = new PlatformApi.MemberAccount(
                "member-avd", "Disposable member", "member@example.invalid", "member");
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
        MemberTunnelGrantStore.clear();
        String installationId = null;

        try {
            PlatformApi.ClientSnapshot initialSnapshot = api.synchronize(account);
            MemberTunnelGrant grant = api.verifiedMemberTunnelGrant();
            installationId = sessionStore.installationId();
            assertEquals(PlatformApi.NodeAuthorizationStatus.READY, initialSnapshot.nodeAuthorizationStatus);
            assertNotNull("test setup did not produce a verified member grant", grant);

            try (ActivityScenario<MainActivity> scenario = ActivityScenario.launch(MainActivity.class)) {
                assertTrue("MainActivity did not synchronize the fake member API",
                        awaitApiAuthorizationCount(server, 2));
                scenario.onActivity(activity -> {
                    setActivityField(activity, "pendingMemberVpnPermission", true);
                    setActivityField(activity, "pendingMemberGrant", grant);
                });

                scenario.recreate();

                assertTrue("recreated MainActivity did not finish its initial authorization sync",
                        awaitActivitySnapshot(scenario));
                int authorizationsBeforeCallback = server.authorizationRequestCount();
                scenario.onActivity(activity -> {
                    assertTrue("permission request marker was not restored",
                            readBooleanField(activity, "pendingMemberVpnPermission"));
                    assertNull("process-local grant was incorrectly restored", readField(activity, "pendingMemberGrant"));
                    invokePermissionResult(activity, Activity.RESULT_CANCELED);
                    assertFalse("denied permission callback retained its pending marker",
                            readBooleanField(activity, "pendingMemberVpnPermission"));
                    assertNull("denied permission callback retained its process-local grant",
                            readField(activity, "pendingMemberGrant"));
                });

                Thread.sleep(250);
                assertEquals("denied permission callback triggered another authorization request",
                        authorizationsBeforeCallback, server.authorizationRequestCount());
                assertFalse("denied permission callback published a service grant",
                        MemberTunnelGrantStore.hasPendingHandoff());
                assertFalse("denied permission callback marked the service as a member tunnel",
                        XlbVpnService.currentTunnelIsMember());
                assertEquals("denied permission callback reported a connected tunnel", 0, connectedStates.get());
                assertFalse("fake API server observed an invalid request: " + server.failureMessage(),
                        server.hasFailure());
            }
        } finally {
            MemberTunnelGrantStore.clear();
            sessionStore.clearSession();
            context.unregisterReceiver(receiver);
            context.stopService(new Intent(context, XlbVpnService.class));
            if (installationId != null) MemberTunnelGrantAvdFixture.deleteInstallationKey(installationId);
            assertTrue("could not clear disposable emulator session state", preferences.edit().clear().commit());
            server.close();
        }
    }

    @Test
    public void approvedPermissionCallbackWithRevokedAuthorizationDoesNotPublishGrantOrServiceState()
            throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        SharedPreferences preferences = context.getSharedPreferences("secure_client_state", Context.MODE_PRIVATE);
        assertTrue("could not clear disposable emulator session state", preferences.edit().clear().commit());
        FakeMemberApi server = new FakeMemberApi(MemberTunnelGrantAvdFixture.trustedSystemCaPem(), 4175);
        SecureSessionStore sessionStore = new SecureSessionStore(context);
        sessionStore.updateCookie("xlb_session", "isolated-member-session", false);
        PlatformApi api = new PlatformApi("http://127.0.0.1:4175", sessionStore);
        PlatformApi.MemberAccount account = new PlatformApi.MemberAccount(
                "member-avd", "Disposable member", "member@example.invalid", "member");
        AtomicInteger connectedStates = new AtomicInteger();
        AtomicInteger serviceStateEvents = new AtomicInteger();
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                serviceStateEvents.incrementAndGet();
                if (XlbVpnService.STATE_CONNECTED.equals(intent.getStringExtra(XlbVpnService.EXTRA_STATE))) {
                    connectedStates.incrementAndGet();
                }
            }
        };
        registerReceiver(context, receiver);
        MemberTunnelGrantStore.clear();
        String installationId = null;

        try {
            PlatformApi.ClientSnapshot initialSnapshot = api.synchronize(account);
            MemberTunnelGrant originalGrant = api.verifiedMemberTunnelGrant();
            installationId = sessionStore.installationId();
            assertEquals(PlatformApi.NodeAuthorizationStatus.READY, initialSnapshot.nodeAuthorizationStatus);
            assertNotNull("test setup did not produce a verified member grant", originalGrant);

            try (ActivityScenario<MainActivity> scenario = ActivityScenario.launch(MainActivity.class)) {
                assertTrue("MainActivity did not synchronize the fake member API",
                        awaitApiAuthorizationCount(server, 2));
                scenario.onActivity(activity -> {
                    setActivityField(activity, "pendingMemberVpnPermission", true);
                    setActivityField(activity, "pendingMemberGrant", originalGrant);
                });

                scenario.recreate();

                assertTrue("recreated MainActivity did not finish its initial authorization sync",
                        awaitActivitySnapshot(scenario));
                int authorizationsBeforeCallback = server.authorizationRequestCount();
                assertTrue("fake member API did not revoke the device", api.removeDevice(
                        MemberTunnelGrantAvdFixture.DEVICE_ID));
                int serviceEventsBeforeCallback = serviceStateEvents.get();
                scenario.onActivity(activity -> {
                    assertTrue("permission request marker was not restored",
                            readBooleanField(activity, "pendingMemberVpnPermission"));
                    assertNull("process-local grant was incorrectly restored", readField(activity, "pendingMemberGrant"));
                    invokePermissionResult(activity, Activity.RESULT_OK);
                });

                assertTrue("approved callback did not request fresh authorization",
                        awaitApiAuthorizationCount(server, authorizationsBeforeCallback + 1));
                assertTrue("Activity did not apply the revoked fresh authorization",
                        awaitActivityAuthorizationStatus(scenario, PlatformApi.NodeAuthorizationStatus.UNAVAILABLE));
                scenario.onActivity(activity -> {
                    assertFalse("revoked authorization retained the permission marker",
                            readBooleanField(activity, "pendingMemberVpnPermission"));
                    assertNull("revoked authorization retained a process-local grant",
                            readField(activity, "pendingMemberGrant"));
                });
                InstrumentationRegistry.getInstrumentation().waitForIdleSync();
                assertNull("device removal retained a cached member grant", api.verifiedMemberTunnelGrant());
                assertFalse("revoked authorization published a service grant",
                        MemberTunnelGrantStore.hasPendingHandoff());
                assertFalse("revoked authorization marked the service as a member tunnel",
                        XlbVpnService.currentTunnelIsMember());
                assertEquals("revoked authorization caused a VPN service state transition",
                        serviceEventsBeforeCallback, serviceStateEvents.get());
                assertEquals("revoked authorization reported a connected tunnel", 0, connectedStates.get());
                assertFalse("fake API server observed an invalid request: " + server.failureMessage(),
                        server.hasFailure());
            }
        } finally {
            try {
                MemberTunnelGrantStore.clear();
                sessionStore.clearSession();
                context.unregisterReceiver(receiver);
                context.stopService(new Intent(context, XlbVpnService.class));
                if (installationId != null) MemberTunnelGrantAvdFixture.deleteInstallationKey(installationId);
                assertTrue("could not clear disposable emulator session state", preferences.edit().clear().commit());
            } finally {
                server.close();
            }
        }
    }

    @Test
    public void profileDigestMismatchNeverPublishesReadyGrant() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        SharedPreferences preferences = context.getSharedPreferences("secure_client_state", Context.MODE_PRIVATE);
        assertTrue("could not clear disposable emulator session state", preferences.edit().clear().commit());
        FakeMemberApi server = new FakeMemberApi(MemberTunnelGrantAvdFixture.trustedSystemCaPem());
        server.setProfileDigestOverride("0".repeat(64));
        SecureSessionStore sessionStore = new SecureSessionStore(context);
        sessionStore.installationId();
        sessionStore.updateCookie("xlb_session", "isolated-member-session", false);
        PlatformApi api = new PlatformApi("http://127.0.0.1:" + server.port(), sessionStore);
        PlatformApi.MemberAccount account = new PlatformApi.MemberAccount(
                "member-avd", "Disposable member", "member@example.invalid", "member");

        try {
            PlatformApi.ClientSnapshot snapshot = api.synchronize(account);

            assertEquals(PlatformApi.NodeAuthorizationStatus.UNAVAILABLE, snapshot.nodeAuthorizationStatus);
            assertNull("tampered profile digest produced a member grant", api.verifiedMemberTunnelGrant());
            assertNotNull("valid device proof was lost when only its tunnel artifact failed",
                    api.verifiedMemberDeviceProof());
            assertTrue("device challenge signature was not checked", server.challengeSignatureVerified());
            assertFalse("fake API server observed an invalid request", server.hasFailure());
        } finally {
            sessionStore.clearSession();
            MemberTunnelGrantAvdFixture.deleteInstallationKey(sessionStore.installationId());
            assertTrue("could not clear disposable emulator session state",
                    preferences.edit().clear().commit());
            server.close();
        }
    }

    @Test
    public void authorizedSyncGrantIsConsumedByClosedServiceGateAndClearedOnLogout() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        SharedPreferences preferences = context.getSharedPreferences("secure_client_state", Context.MODE_PRIVATE);
        assertTrue("could not clear disposable emulator session state", preferences.edit().clear().commit());
        String trustedCa = MemberTunnelGrantAvdFixture.trustedSystemCaPem();
        FakeMemberApi server = new FakeMemberApi(trustedCa);
        SecureSessionStore sessionStore = new SecureSessionStore(context);
        String installationId = sessionStore.installationId();
        sessionStore.updateCookie("xlb_session", "isolated-member-session", false);
        PlatformApi api = new PlatformApi("http://127.0.0.1:" + server.port(), sessionStore);
        PlatformApi.MemberAccount account = new PlatformApi.MemberAccount(
                "member-avd", "Disposable member", "member@example.invalid", "member");
        CountDownLatch rejected = new CountDownLatch(1);
        AtomicReference<CountDownLatch> expectedDisconnect = new AtomicReference<>(new CountDownLatch(1));
        AtomicReference<String> serviceMessage = new AtomicReference<>();
        AtomicInteger connectedStates = new AtomicInteger();
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                String state = intent.getStringExtra(XlbVpnService.EXTRA_STATE);
                if (XlbVpnService.STATE_CONNECTED.equals(state)) {
                    connectedStates.incrementAndGet();
                } else if (XlbVpnService.STATE_ERROR.equals(state)) {
                    serviceMessage.set(intent.getStringExtra(XlbVpnService.EXTRA_MESSAGE));
                    rejected.countDown();
                } else if (XlbVpnService.STATE_DISCONNECTED.equals(state)) {
                    expectedDisconnect.get().countDown();
                }
            }
        };
        registerReceiver(context, receiver);
        Intent start = new Intent(context, XlbVpnService.class).setAction(XlbVpnService.ACTION_START_MEMBER);
        Intent stop = new Intent(context, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP);
        MemberTunnelGrantStore.clear();

        try {
            PlatformApi.ClientSnapshot snapshot = api.synchronize(account);
            MemberTunnelGrant grant = api.verifiedMemberTunnelGrant();

            assertEquals(PlatformApi.NodeAuthorizationStatus.READY, snapshot.nodeAuthorizationStatus);
            assertNotNull("ready API response did not produce an identity-bound grant", grant);
            assertEquals(installationId, grant.deviceProof().installationId());
            assertEquals("https://node.example.invalid:9443", grant.bridgeEndpoint());
            assertTrue("device challenge signature was not checked", server.challengeSignatureVerified());

            long handoffId = MemberTunnelGrantStore.publish(grant);
            start.putExtra(XlbVpnService.EXTRA_MEMBER_GRANT_HANDOFF_ID, handoffId);
            ComponentName started = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                    ? context.startForegroundService(start)
                    : context.startService(start);
            assertNotNull("VPN service did not start", started);
            assertTrue("closed production gate did not reject the server-ready grant",
                    rejected.await(5, TimeUnit.SECONDS));
            assertEquals("会员安全线路尚未接入", serviceMessage.get());
            assertNull("service did not consume the one-shot grant",
                    MemberTunnelGrantStore.consume(handoffId));
            assertFalse("closed-gate rejection left the tunnel marked as a member session",
                    XlbVpnService.currentTunnelIsMember());
            assertEquals("", XlbVpnService.currentRouteName());

            long pendingHandoffId = MemberTunnelGrantStore.publish(grant);
            Intent delayedStart = new Intent(context, XlbVpnService.class)
                    .setAction(XlbVpnService.ACTION_START_MEMBER)
                    .putExtra(XlbVpnService.EXTRA_MEMBER_GRANT_HANDOFF_ID, pendingHandoffId);
            assertTrue("member grant was not pending before the authorization refresh",
                    XlbVpnService.hasPendingMemberTunnelGrant());

            assertTrue("current-device removal did not report pending revocation",
                    api.removeDevice(MemberTunnelGrantAvdFixture.DEVICE_ID));
            assertNull("device removal left a cached member grant", api.verifiedMemberTunnelGrant());
            assertNull("device removal left a cached device proof", api.verifiedMemberDeviceProof());

            assertEquals(PlatformApi.NodeAuthorizationStatus.UNAVAILABLE,
                    api.synchronize(account).nodeAuthorizationStatus);
            assertNull("revoked device retained a cached member grant", api.verifiedMemberTunnelGrant());
            assertTrue("authorization refresh unexpectedly cleared the already handed-off grant",
                    XlbVpnService.hasPendingMemberTunnelGrant());

            ComponentName stoppedService = context.startService(stop);
            assertNotNull("VPN service did not accept the stop after authorization loss", stoppedService);
            assertTrue("authorization-loss stop did not complete the local stop path",
                    expectedDisconnect.get().await(5, TimeUnit.SECONDS));
            assertNull("authorization-loss stop left a pending process-local grant",
                    MemberTunnelGrantStore.consume(pendingHandoffId));
            assertFalse("authorization-loss stop left a pending member grant",
                    XlbVpnService.hasPendingMemberTunnelGrant());

            ComponentName staleStarted = context.startForegroundService(delayedStart);
            assertNotNull("service did not accept the delayed revoked-grant intent", staleStarted);
            InstrumentationRegistry.getInstrumentation().waitForIdleSync();
            assertEquals("delayed revoked grant changed the stopped service state",
                    XlbVpnService.STATE_DISCONNECTED, XlbVpnService.currentState());
            assertFalse("delayed revoked grant marked the service as a member tunnel",
                    XlbVpnService.currentTunnelIsMember());
            assertEquals("delayed revoked grant reported a connected tunnel", 0, connectedStates.get());

            long logoutHandoffId = MemberTunnelGrantStore.publish(grant);
            expectedDisconnect.set(new CountDownLatch(1));
            ComponentName logoutStop = context.startService(stop);
            assertNotNull("VPN service did not accept the logout stop action", logoutStop);
            assertTrue("logout stop action did not complete the service stop path",
                    expectedDisconnect.get().await(5, TimeUnit.SECONDS));
            assertNull("logout stop action did not clear the process-local grant",
                    MemberTunnelGrantStore.consume(logoutHandoffId));
            api.logout();
            assertNull("logout left a cached member grant", api.verifiedMemberTunnelGrant());
            assertEquals("logout did not clear local session cookies", "", sessionStore.cookieHeader());
            assertNull("logout path left a pending process-local grant",
                    MemberTunnelGrantStore.consume(logoutHandoffId));
            assertFalse("fake API server observed an invalid request", server.hasFailure());
        } finally {
            MemberTunnelGrantStore.clear();
            sessionStore.clearSession();
            context.unregisterReceiver(receiver);
            context.stopService(start);
            context.stopService(stop);
            MemberTunnelGrantAvdFixture.deleteInstallationKey(installationId);
            assertTrue("could not clear disposable emulator session state",
                    preferences.edit().clear().commit());
            server.close();
        }
    }

    private static void registerReceiver(Context context, BroadcastReceiver receiver) {
        IntentFilter filter = new IntentFilter(XlbVpnService.ACTION_STATE);
        if (android.os.Build.VERSION.SDK_INT >= 33) {
            context.registerReceiver(receiver, filter, XlbVpnService.INTERNAL_PERMISSION, null,
                    Context.RECEIVER_NOT_EXPORTED);
            return;
        }
        context.registerReceiver(receiver, filter, XlbVpnService.INTERNAL_PERMISSION, null);
    }

    private static boolean awaitApiAuthorizationCount(FakeMemberApi server, int expectedCount)
            throws InterruptedException {
        long deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(10);
        while (System.nanoTime() < deadline) {
            if (server.authorizationRequestCount() >= expectedCount) return true;
            Thread.sleep(50);
        }
        return false;
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
            assertNull("VPN permission was not granted on the disposable AVD", VpnService.prepare(context));
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
            if (capabilities != null && capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN)) return network;
            Thread.sleep(25);
        }
        return null;
    }

    private static boolean awaitVpnNetworkGone(Context context, long timeoutMillis) throws Exception {
        ConnectivityManager manager = (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
        long deadline = System.currentTimeMillis() + timeoutMillis;
        while (System.currentTimeMillis() < deadline) {
            Network network = manager == null ? null : manager.getActiveNetwork();
            NetworkCapabilities capabilities = network == null || manager == null
                    ? null : manager.getNetworkCapabilities(network);
            if (capabilities == null || !capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN)) return true;
            Thread.sleep(25);
        }
        return false;
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
        boolean vpnNetworkGone = manager == null;
        while (manager != null && System.currentTimeMillis() < deadline) {
            Network network = manager.getActiveNetwork();
            NetworkCapabilities capabilities = network == null ? null : manager.getNetworkCapabilities(network);
            if (capabilities == null || !capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN)) {
                vpnNetworkGone = true;
                break;
            }
            Thread.sleep(50);
        }
        if (!vpnNetworkGone) throw new AssertionError("Android retained the VPN network after stop");
        assertFalse("isolated tunnel stop left a pending member grant", MemberTunnelGrantStore.hasPendingHandoff());
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
            assertEquals("TCP echo through the isolated member peer was incomplete", payload.length, offset);
            assertArrayEquals("TCP bytes did not return through system TUN, Mihomo, proof bridge, and fixture peer",
                    payload, response);
            return socket;
        } catch (Exception exception) {
            socket.close();
            throw exception;
        }
    }

    private static void negotiateSocksUserPassword(Socket connection,
            DeviceProofCertificate.BridgeCredentials credentials) throws IOException {
        InputStream input = connection.getInputStream();
        OutputStream output = connection.getOutputStream();
        output.write(new byte[]{5, 1, 2});
        assertEquals(5, input.read());
        assertEquals(2, input.read());
        byte[] username = credentials.usernameForProfile().getBytes(StandardCharsets.US_ASCII);
        byte[] password = credentials.passwordForProfile().getBytes(StandardCharsets.US_ASCII);
        output.write(new byte[]{1, (byte) username.length});
        output.write(username);
        output.write((byte) password.length);
        output.write(password);
        assertEquals(1, input.read());
        assertEquals(0, input.read());
    }

    private static void readFully(InputStream input, byte[] output) throws IOException {
        int offset = 0;
        while (offset < output.length) {
            int read = input.read(output, offset, output.length - offset);
            if (read < 0) throw new IOException("SOCKS response ended early");
            offset += read;
        }
    }

    private static boolean awaitNoActiveFixtureSessions(long timeoutMillis) throws Exception {
        long deadline = System.currentTimeMillis() + timeoutMillis;
        while (System.currentTimeMillis() < deadline) {
            int activeSessions = new JSONObject(DeviceProofJni.nodeAgentFixtureStatus()).getInt("active_sessions");
            if (activeSessions == 0) return true;
            Thread.sleep(25);
        }
        return new JSONObject(DeviceProofJni.nodeAgentFixtureStatus()).getInt("active_sessions") == 0;
    }

    private static boolean awaitActivitySnapshot(ActivityScenario<MainActivity> scenario)
            throws InterruptedException {
        long deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(10);
        while (System.nanoTime() < deadline) {
            AtomicReference<Boolean> ready = new AtomicReference<>(false);
            scenario.onActivity(activity -> ready.set(readField(activity, "snapshot") != null));
            if (ready.get()) return true;
            Thread.sleep(50);
        }
        return false;
    }

    private static boolean awaitActivityAuthorizationStatus(ActivityScenario<MainActivity> scenario,
            PlatformApi.NodeAuthorizationStatus expectedStatus) throws InterruptedException {
        long deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(10);
        while (System.nanoTime() < deadline) {
            AtomicReference<PlatformApi.NodeAuthorizationStatus> status = new AtomicReference<>();
            scenario.onActivity(activity -> {
                Object value = readField(activity, "snapshot");
                if (value instanceof PlatformApi.ClientSnapshot) {
                    status.set(((PlatformApi.ClientSnapshot) value).nodeAuthorizationStatus);
                }
            });
            if (status.get() == expectedStatus) return true;
            Thread.sleep(50);
        }
        return false;
    }

    private static void setActivityField(MainActivity activity, String name, Object value) {
        try {
            java.lang.reflect.Field field = MainActivity.class.getDeclaredField(name);
            field.setAccessible(true);
            field.set(activity, value);
        } catch (ReflectiveOperationException exception) {
            throw new AssertionError("Could not set the test-only Activity state", exception);
        }
    }

    private static boolean readBooleanField(MainActivity activity, String name) {
        try {
            java.lang.reflect.Field field = MainActivity.class.getDeclaredField(name);
            field.setAccessible(true);
            return field.getBoolean(activity);
        } catch (ReflectiveOperationException exception) {
            throw new AssertionError("Could not inspect the Activity state", exception);
        }
    }

    private static Object readField(MainActivity activity, String name) {
        try {
            java.lang.reflect.Field field = MainActivity.class.getDeclaredField(name);
            field.setAccessible(true);
            return field.get(activity);
        } catch (ReflectiveOperationException exception) {
            throw new AssertionError("Could not inspect the Activity state", exception);
        }
    }

    private static void invokePermissionResult(MainActivity activity, int resultCode) {
        try {
            java.lang.reflect.Method method = MainActivity.class.getDeclaredMethod(
                    "onActivityResult", int.class, int.class, Intent.class);
            method.setAccessible(true);
            method.invoke(activity, 91, resultCode, null);
        } catch (ReflectiveOperationException exception) {
            throw new AssertionError("Could not deliver the test VPN permission result", exception);
        }
    }

    private static void requireDisposableEmulator() {
        String hardware = Build.HARDWARE == null ? "" : Build.HARDWARE.toLowerCase(Locale.ROOT);
        String fingerprint = Build.FINGERPRINT == null ? "" : Build.FINGERPRINT.toLowerCase(Locale.ROOT);
        boolean emulator = "ranchu".equals(hardware)
                || "goldfish".equals(hardware)
                || fingerprint.contains("sdk_gphone");
        assertTrue("Member API integration requires a disposable emulator", emulator);
        assertEquals("Set disposable_avd=true only for a disposable emulator", "true",
                InstrumentationRegistry.getArguments().getString("disposable_avd", "false"));
    }

    private static final class FakeMemberApi implements Closeable {
        private static final String SESSION_COOKIE = "xlb_session";

        private final ServerSocket serverSocket;
        private final String trustedCa;
        private final Thread serverThread;
        private final AtomicReference<Throwable> failure = new AtomicReference<>();
        private volatile String installationId;
        private volatile String publicKey;
        private volatile byte[] challenge;
        private volatile boolean challengeSignatureVerified;
        private volatile boolean deviceRemovalPending;
        private volatile String profileDigestOverride;
        private volatile String bridgeEndpoint = "https://node.example.invalid:9443";
        private final AtomicInteger authorizationRequests = new AtomicInteger();

        private FakeMemberApi(String trustedCa) throws IOException {
            this(trustedCa, 0);
        }

        private FakeMemberApi(String trustedCa, int port) throws IOException {
            this.trustedCa = trustedCa;
            serverSocket = new ServerSocket(port, 8, InetAddress.getByName("127.0.0.1"));
            serverSocket.setSoTimeout(1000);
            serverThread = new Thread(this::serve, "isolated-member-api");
            serverThread.start();
        }

        private int port() {
            return serverSocket.getLocalPort();
        }

        private void setBridgeEndpoint(String endpoint) {
            bridgeEndpoint = endpoint;
        }

        private boolean challengeSignatureVerified() {
            return challengeSignatureVerified;
        }

        private boolean hasFailure() {
            return failure.get() != null;
        }

        private String failureMessage() {
            Throwable currentFailure = failure.get();
            return currentFailure == null ? "none" : currentFailure.toString();
        }

        private int authorizationRequestCount() {
            return authorizationRequests.get();
        }

        private void setProfileDigestOverride(String profileDigest) {
            profileDigestOverride = profileDigest;
        }

        private void serve() {
            while (!serverSocket.isClosed()) {
                try (Socket socket = serverSocket.accept()) {
                    socket.setSoTimeout(5000);
                    handle(socket);
                } catch (java.net.SocketTimeoutException ignored) {
                    // Re-check the close state without keeping the test process alive.
                } catch (IOException exception) {
                    if (!serverSocket.isClosed()) failure.compareAndSet(null, exception);
                } catch (Exception exception) {
                    failure.compareAndSet(null, exception);
                }
            }
        }

        private void handle(Socket socket) throws Exception {
            InputStream input = socket.getInputStream();
            String requestLine = readLine(input);
            if (requestLine == null) return;
            String[] requestParts = requestLine.split(" ", 3);
            if (requestParts.length != 3) throw new IOException("malformed request line");
            String method = requestParts[0];
            String path = requestParts[1];
            int contentLength = 0;
            String header;
            while ((header = readLine(input)) != null && !header.isEmpty()) {
                int separator = header.indexOf(':');
                if (separator > 0 && "content-length".equalsIgnoreCase(header.substring(0, separator).trim())) {
                    contentLength = Integer.parseInt(header.substring(separator + 1).trim());
                }
            }
            byte[] body = readExactly(input, contentLength);
            JSONObject response = respond(method, path, body);
            writeResponse(socket.getOutputStream(), response);
        }

        private JSONObject respond(String method, String path, byte[] body) throws Exception {
            if ("GET".equals(method) && "/api/v1/auth/session".equals(path)) {
                return data(new JSONObject().put("id", "member-avd")
                        .put("name", "Disposable member")
                        .put("email", "member@example.invalid")
                        .put("role", "member")
                        .put("account_type", "full"));
            }
            if ("GET".equals(method) && "/api/v1/auth/csrf".equals(path)) {
                return data(new JSONObject().put("token", "isolated-csrf-token"));
            }
            if ("POST".equals(method) && "/api/v1/auth/login".equals(path)) {
                return error(401, "登录失败").put("request_id_header", SERVER_REQUEST_ID);
            }
            if ("GET".equals(method) && "/api/v1/me/overview".equals(path)) {
                return data(new JSONObject().put("activeSubscriptions", 1)
                        .put("entitlement", new JSONObject()
                                .put("plan_name", "Disposable member plan")
                                .put("starts_at", "2026-01-01T00:00:00Z")
                                .put("ends_at", "2027-01-01T00:00:00Z")
                                .put("traffic_gb", 1500)
                                .put("data_used_gb", 0)
                                .put("max_devices", 5)));
            }
            if ("POST".equals(method) && "/api/v1/me/device-bindings/challenge".equals(path)) {
                JSONObject binding = new JSONObject(new String(body, StandardCharsets.UTF_8));
                installationId = binding.optString("installation_id");
                publicKey = binding.optString("public_key");
                challenge = new byte[32];
                new java.security.SecureRandom().nextBytes(challenge);
                return data(new JSONObject().put("challenge_id", UUID.randomUUID().toString())
                        .put("challenge", Base64.getUrlEncoder().withoutPadding().encodeToString(challenge)));
            }
            if ("POST".equals(method) && "/api/v1/me/device-bindings/authorize".equals(path)) {
                authorizationRequests.incrementAndGet();
                JSONObject proof = new JSONObject(new String(body, StandardCharsets.UTF_8));
                challengeSignatureVerified = verifyChallengeSignature(proof.optString("signature"));
                if (!challengeSignatureVerified) {
                    return error(422, "device signature did not verify");
                }
                if (deviceRemovalPending) {
                    return data(new JSONObject()
                            .put("device", new JSONObject()
                                    .put("id", MemberTunnelGrantAvdFixture.DEVICE_ID)
                                    .put("status", "removal_pending")
                                    .put("bound", true))
                            .put("node_authorization", new JSONObject().put("status", "revoked")));
                }
                return readyAuthorization();
            }
            if ("GET".equals(method) && "/api/v1/me/devices".equals(path)) {
                JSONArray devices = new JSONArray().put(new JSONObject()
                        .put("id", MemberTunnelGrantAvdFixture.DEVICE_ID)
                        .put("name", "Disposable Android")
                        .put("platform", "android")
                        .put("installation_id", installationId)
                        .put("status", deviceRemovalPending ? "removal_pending" : "registered")
                        .put("removal_pending", deviceRemovalPending));
                return data(new JSONObject().put("registered_count", 1)
                        .put("max_devices", 5)
                        .put("entitlement_active", true)
                        .put("devices", devices));
            }
            if ("DELETE".equals(method)
                    && ("/api/v1/me/devices/" + MemberTunnelGrantAvdFixture.DEVICE_ID).equals(path)) {
                deviceRemovalPending = true;
                return data(new JSONObject().put("status", "revocation_pending"));
            }
            if ("POST".equals(method) && "/api/v1/client/heartbeat".equals(path)) {
                return data(new JSONObject().put("accepted", true));
            }
            if ("POST".equals(method) && "/api/v1/auth/logout".equals(path)) {
                return new JSONObject().put("data", new JSONObject());
            }
            return error(404, "unexpected isolated API route " + method + " " + path);
        }

        private JSONObject readyAuthorization() throws Exception {
            String profile = MemberTunnelGrantAvdFixture.PROFILE;
            return data(new JSONObject()
                    .put("device", new JSONObject()
                            .put("id", MemberTunnelGrantAvdFixture.DEVICE_ID)
                            .put("status", "registered")
                            .put("bound", true))
                    .put("node_authorization", new JSONObject()
                            .put("status", "ready")
                            .put("peer_id", MemberTunnelGrantAvdFixture.PEER_ID)
                            .put("node_id", MemberTunnelGrantAvdFixture.NODE_ID)
                            .put("peer_generation", MemberTunnelGrantAvdFixture.PEER_GENERATION)
                            .put("device_binding_generation", MemberTunnelGrantAvdFixture.DEVICE_GENERATION)
                            .put("peer_credential", Base64.getUrlEncoder().withoutPadding().encodeToString(new byte[32]))
                            .put("profile", profile)
                            .put("bridge_endpoint", bridgeEndpoint)
                            .put("bridge_server_ca_pem", trustedCa)
                            .put("profile_sha256", profileDigestOverride == null ? sha256(profile) : profileDigestOverride)
                            .put("profile_renderer_id", "xlb-mihomo-bridge-v1")
                            .put("profile_renderer_version", "1.0.0")
                            .put("bridge_protocol", "xlb-proof-v1")
                            .put("connectable", true)));
        }

        private boolean verifyChallengeSignature(String encodedSignature) throws Exception {
            if (installationId == null || publicKey == null || challenge == null) return false;
            byte[] encodedKey = Base64.getUrlDecoder().decode(publicKey);
            java.security.PublicKey key = KeyFactory.getInstance("EC")
                    .generatePublic(new X509EncodedKeySpec(encodedKey));
            Signature verifier = Signature.getInstance("SHA256withECDSA");
            verifier.initVerify(key);
            verifier.update(challenge);
            return verifier.verify(Base64.getUrlDecoder().decode(encodedSignature));
        }

        private static String sha256(String value) throws Exception {
            byte[] digest = java.security.MessageDigest.getInstance("SHA-256")
                    .digest(value.getBytes(StandardCharsets.UTF_8));
            StringBuilder encoded = new StringBuilder();
            for (byte part : digest) encoded.append(String.format(Locale.ROOT, "%02x", part));
            return encoded.toString();
        }

        private static JSONObject data(JSONObject value) throws Exception {
            return new JSONObject().put("data", value);
        }

        private static JSONObject error(int status, String message) throws Exception {
            return new JSONObject().put("error", new JSONObject().put("status", status).put("message", message));
        }

        private static String readLine(InputStream input) throws IOException {
            ByteArrayOutputStream line = new ByteArrayOutputStream();
            int value;
            while ((value = input.read()) != -1) {
                if (value == '\n') break;
                if (value != '\r') line.write(value);
            }
            if (value == -1 && line.size() == 0) return null;
            return line.toString(StandardCharsets.US_ASCII.name());
        }

        private static byte[] readExactly(InputStream input, int length) throws IOException {
            byte[] result = new byte[length];
            int offset = 0;
            while (offset < length) {
                int count = input.read(result, offset, length - offset);
                if (count < 0) throw new IOException("request body ended early");
                offset += count;
            }
            return result;
        }

        private static void writeResponse(OutputStream output, JSONObject response) throws Exception {
            String requestId = response.optString("request_id_header", null);
            JSONObject responseBody = new JSONObject(response.toString());
            responseBody.remove("request_id_header");
            byte[] body = responseBody.toString().getBytes(StandardCharsets.UTF_8);
            JSONObject error = responseBody.optJSONObject("error");
            int status = error == null ? 200 : error.optInt("status", 404);
            String headers = "HTTP/1.1 " + status + (status == 200 ? " OK\r\n" : " Not Found\r\n")
                    + "Content-Type: application/json; charset=utf-8\r\n"
                    + (requestId == null ? "" : "X-Request-Id: " + requestId + "\r\n")
                    + "Set-Cookie: " + SESSION_COOKIE + "=isolated-session; Path=/\r\n"
                    + "Content-Length: " + body.length + "\r\n"
                    + "Connection: close\r\n\r\n";
            output.write(headers.getBytes(StandardCharsets.US_ASCII));
            output.write(body);
            output.flush();
        }

        @Override
        public void close() throws IOException {
            serverSocket.close();
            try {
                serverThread.join(TimeUnit.SECONDS.toMillis(2));
            } catch (InterruptedException exception) {
                Thread.currentThread().interrupt();
                throw new IOException("interrupted while stopping isolated API", exception);
            }
        }
    }
}
