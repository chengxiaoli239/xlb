package com.xlb.vpn.tunnel;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

import android.content.BroadcastReceiver;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.os.Build;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;

import com.xlb.vpn.data.MemberTunnelGrant;
import com.xlb.vpn.data.MemberTunnelGrantAvdFixture;
import com.xlb.vpn.data.PlatformApi;
import com.xlb.vpn.data.SecureSessionStore;

import org.json.JSONArray;
import org.json.JSONObject;
import org.junit.Test;
import org.junit.runner.RunWith;

import java.io.ByteArrayOutputStream;
import java.io.Closeable;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
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
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;

@RunWith(AndroidJUnit4.class)
public final class PlatformApiMemberGrantInstrumentedTest {
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
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                if (XlbVpnService.STATE_ERROR.equals(intent.getStringExtra(XlbVpnService.EXTRA_STATE))) {
                    serviceMessage.set(intent.getStringExtra(XlbVpnService.EXTRA_MESSAGE));
                    rejected.countDown();
                } else if (XlbVpnService.STATE_DISCONNECTED.equals(
                        intent.getStringExtra(XlbVpnService.EXTRA_STATE))) {
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

            MemberTunnelGrantStore.publish(grant);
            ComponentName started = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                    ? context.startForegroundService(start)
                    : context.startService(start);
            assertNotNull("VPN service did not start", started);
            assertTrue("closed production gate did not reject the server-ready grant",
                    rejected.await(5, TimeUnit.SECONDS));
            assertEquals("会员安全线路尚未接入", serviceMessage.get());
            assertNull("service did not consume the one-shot grant", MemberTunnelGrantStore.consume());
            assertEquals("", XlbVpnService.currentRouteName());

            MemberTunnelGrantStore.publish(grant);
            ComponentName stoppedService = context.startService(stop);
            assertNotNull("VPN service did not accept the stop action", stoppedService);
            assertTrue("VPN service did not complete the local stop path",
                    expectedDisconnect.get().await(5, TimeUnit.SECONDS));
            assertNull("stop left a pending process-local grant", MemberTunnelGrantStore.consume());

            assertTrue("current-device removal did not report pending revocation",
                    api.removeDevice(MemberTunnelGrantAvdFixture.DEVICE_ID));
            assertNull("device removal left a cached member grant", api.verifiedMemberTunnelGrant());
            assertNull("device removal left a cached device proof", api.verifiedMemberDeviceProof());

            assertEquals(PlatformApi.NodeAuthorizationStatus.UNAVAILABLE,
                    api.synchronize(account).nodeAuthorizationStatus);
            assertNull("revoked device retained a cached member grant", api.verifiedMemberTunnelGrant());
            MemberTunnelGrantStore.publish(grant);
            expectedDisconnect.set(new CountDownLatch(1));
            ComponentName logoutStop = context.startService(stop);
            assertNotNull("VPN service did not accept the logout stop action", logoutStop);
            assertTrue("logout stop action did not complete the service stop path",
                    expectedDisconnect.get().await(5, TimeUnit.SECONDS));
            assertNull("logout stop action did not clear the process-local grant", MemberTunnelGrantStore.consume());
            api.logout();
            assertNull("logout left a cached member grant", api.verifiedMemberTunnelGrant());
            assertEquals("logout did not clear local session cookies", "", sessionStore.cookieHeader());
            assertNull("logout path left a pending process-local grant", MemberTunnelGrantStore.consume());
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

        private FakeMemberApi(String trustedCa) throws IOException {
            this.trustedCa = trustedCa;
            serverSocket = new ServerSocket(0, 8, InetAddress.getByName("127.0.0.1"));
            serverSocket.setSoTimeout(1000);
            serverThread = new Thread(this::serve, "isolated-member-api");
            serverThread.start();
        }

        private int port() {
            return serverSocket.getLocalPort();
        }

        private boolean challengeSignatureVerified() {
            return challengeSignatureVerified;
        }

        private boolean hasFailure() {
            return failure.get() != null;
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
            if (requestLine == null) throw new IOException("missing request line");
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
            if ("GET".equals(method) && "/api/v1/auth/csrf".equals(path)) {
                return data(new JSONObject().put("token", "isolated-csrf-token"));
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
                            .put("bridge_endpoint", "https://node.example.invalid:9443")
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

        private static void writeResponse(OutputStream output, JSONObject response) throws IOException {
            byte[] body = response.toString().getBytes(StandardCharsets.UTF_8);
            int status = response.has("error") ? 404 : 200;
            String headers = "HTTP/1.1 " + status + (status == 200 ? " OK\r\n" : " Not Found\r\n")
                    + "Content-Type: application/json; charset=utf-8\r\n"
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
