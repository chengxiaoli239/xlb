package com.xlb.vpn.data;

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

import com.xlb.vpn.presentation.MainActivity;
import com.xlb.vpn.tunnel.MemberTunnelGrantStore;
import com.xlb.vpn.tunnel.XlbVpnService;

import org.json.JSONObject;
import org.json.JSONArray;
import org.junit.Test;
import org.junit.runner.RunWith;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileInputStream;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.Inet4Address;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.Socket;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.security.KeyFactory;
import java.security.KeyStore;
import java.security.MessageDigest;
import java.security.PrivateKey;
import java.security.cert.Certificate;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;
import java.security.spec.PKCS8EncodedKeySpec;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.UUID;
import java.security.SecureRandom;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicReference;
import java.util.regex.Pattern;

import javax.net.ssl.HttpsURLConnection;
import javax.net.ssl.KeyManagerFactory;
import javax.net.ssl.SSLContext;
import javax.net.ssl.TrustManagerFactory;

@RunWith(AndroidJUnit4.class)
public final class RemoteNodeAgentMemberTunInstrumentedTest {
    @Test
    public void keystoreProofCarriesTunTrafficThroughIsolatedAwsNodeAndRevokesWithReceipt() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        android.os.Bundle arguments = InstrumentationRegistry.getArguments();
        String nodeId = requiredArgument(arguments, "node_id");
        String controlUrl = requiredArgument(arguments, "control_url");
        String bridgeEndpoint = requiredArgument(arguments, "bridge_endpoint");
        String expectedEgressIp = requiredArgument(arguments, "expected_egress_ip");
        File caFile = new File(requiredArgument(arguments, "node_ca_file"));
        File clientCertificateFile = new File(requiredArgument(arguments, "admin_cert_file"));
        File clientPrivateKeyFile = new File(requiredArgument(arguments, "admin_key_file"));
        assertTrue("test node CA is missing", caFile.isFile());
        assertTrue("test management client certificate is missing", clientCertificateFile.isFile());
        assertTrue("test management client key is missing", clientPrivateKeyFile.isFile());

        String installationId = MemberTunnelGrantAvdFixture.newInstallationId();
        String publicKey = MemberTunnelGrantAvdFixture.publicKey(installationId);
        String publicKeyFingerprint = sha256(DeviceBindingProof.decode(publicKey));
        String deviceId = UUID.randomUUID().toString();
        String peerId = UUID.randomUUID().toString();
        String entitlementId = UUID.randomUUID().toString();
        String requestId = UUID.randomUUID().toString();
        byte[] credentialBytes = new byte[32];
        new SecureRandom().nextBytes(credentialBytes);
        String credential = android.util.Base64.encodeToString(
                credentialBytes,
                android.util.Base64.URL_SAFE | android.util.Base64.NO_WRAP | android.util.Base64.NO_PADDING);
        long generation = 1;
        long bindingGeneration = 1;
        String expiresAt = Instant.now().plus(30, ChronoUnit.MINUTES).toString();
        String egressProbeIPv4 = resolveEgressProbeIPv4(context);
        AtomicInteger connectedStates = new AtomicInteger();
        AtomicReference<String> tunnelFailure = new AtomicReference<>();
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                if (XlbVpnService.STATE_CONNECTED.equals(intent.getStringExtra(XlbVpnService.EXTRA_STATE))) {
                    connectedStates.incrementAndGet();
                }
                if (XlbVpnService.STATE_ERROR.equals(intent.getStringExtra(XlbVpnService.EXTRA_STATE))) {
                    tunnelFailure.set(intent.getStringExtra(XlbVpnService.EXTRA_MESSAGE));
                }
            }
        };
        registerReceiver(context, receiver);
        boolean serviceStarted = false;
        long handoffId = 0;
        MemberTunnelGrant grant = null;
        SSLContext controlTls = null;
        boolean peerProvisioned = false;
        boolean peerRevoked = false;
        try {
            controlTls = controlTlsContext(caFile, clientCertificateFile, clientPrivateKeyFile);
            JSONObject ensureRequest = new JSONObject()
                    .put("node_id", nodeId)
                    .put("peer_id", peerId)
                    .put("entitlement_id", entitlementId)
                    .put("request_id", requestId)
                    .put("generation", generation)
                    .put("device_id", deviceId)
                    .put("device_name", "Disposable Android AVD")
                    .put("device_public_key", publicKey)
                    .put("device_key_fingerprint", publicKeyFingerprint)
                    .put("device_binding_generation", bindingGeneration)
                    .put("peer_generation", generation)
                    .put("device_generation", bindingGeneration)
                    .put("expires_at", expiresAt)
                    .put("credential", credential);
            JSONObject ensure = postJson(controlTls, controlUrl + "/v1/member-peers/ensure", ensureRequest);
            JSONObject receipt = ensure.optJSONObject("data");
            assertNotNull("live Node Agent did not return a provisioning receipt", receipt);
            assertEquals(nodeId, receipt.getString("node_id"));
            assertEquals(peerId, receipt.getString("peer_id"));
            assertEquals(requestId, receipt.getString("request_id"));
            assertEquals("confirmed_test", receipt.getString("confirmation_status"));
            peerProvisioned = true;
            JSONObject artifact = receipt.getJSONObject("profile_artifact");
            String profile = artifact.getString("content");
            String profileDigest = artifact.getString("sha256");
            assertEquals(sha256(profile.getBytes(StandardCharsets.UTF_8)), profileDigest);
            assertEquals(bridgeEndpoint, artifact.getString("bridge_endpoint"));
            assertTrue(artifact.getBoolean("connectable"));

            DeviceKeyStore deviceKeyStore = new DeviceKeyStore();
            String deviceName = Build.MODEL.length() > 80 ? Build.MODEL.substring(0, 80) : Build.MODEL;
            MemberDeviceProof.Signer signer = new MemberDeviceProof.Signer() {
                @Override
                public String publicKeySpkiBase64Url() {
                    return publicKey;
                }

                @Override
                public String signChallengeBase64Url(byte[] challenge) throws java.security.GeneralSecurityException {
                    return deviceKeyStore.sign(installationId, DeviceBindingProof.encode(challenge));
                }

                @Override
                public byte[] signDigest(byte[] digest) throws java.security.GeneralSecurityException {
                    return deviceKeyStore.signDigestBytes(installationId, digest);
                }
            };
            MemberDeviceProof proof = MemberDeviceProof.fromAuthorizedResponse(
                    "isolated-avd-member", installationId, publicKey, deviceId, "registered", true,
                    "ready", peerId, nodeId, generation, bindingGeneration, credential, profile, signer);
            assertNotNull("Android Keystore proof capability rejected the matching Node Agent receipt", proof);
            grant = MemberTunnelGrant.fromAuthorizedResponse(
                    proof, "ready", nodeId, peerId, generation, bindingGeneration,
                    artifact.getString("bridge_endpoint"), artifact.getString("bridge_server_ca_pem"),
                    profile, profileDigest, artifact.getString("renderer_id"), artifact.getString("renderer_version"),
                    artifact.getString("bridge_protocol"), artifact.getBoolean("connectable"));
            assertNotNull("validated member grant rejected the live Node Agent profile", grant);

            handoffId = MemberTunnelGrantStore.publish(grant);
            requestVpnPermission(context);
            ComponentName started = startVpnService(context, new Intent(context, XlbVpnService.class)
                    .setAction(XlbVpnService.ACTION_START_ISOLATED_MEMBER_TEST)
                    .putExtra(XlbVpnService.EXTRA_REMOTE_NODE_ACCEPTANCE_TEST, true)
                    .putExtra(XlbVpnService.EXTRA_MEMBER_GRANT_HANDOFF_ID, handoffId));
            assertNotNull("VPN service did not start", started);
            serviceStarted = true;
            Network vpnNetwork = awaitActiveVpnNetwork(context, TimeUnit.SECONDS.toMillis(15));
            assertNotNull("Android did not establish its test VPN route. State error: "
                    + tunnelFailure.get(), vpnNetwork);
            assertTrue(XlbVpnService.currentTunnelIsMember());
            assertEquals("isolated route must not publish production CONNECTED", 0, connectedStates.get());

            String egressIp = fetchEgressIp(vpnNetwork, egressProbeIPv4);
            assertTrue("node egress response was not an IPv4 address: " + egressIp,
                    Pattern.matches("^[0-9]{1,3}(\\.[0-9]{1,3}){3}$", egressIp));
            assertEquals("VPN traffic did not exit through the isolated Node Agent endpoint",
                    expectedEgressIp, egressIp);

            JSONObject revokeRequest = new JSONObject()
                    .put("node_id", nodeId).put("peer_id", peerId)
                    .put("request_id", UUID.randomUUID().toString()).put("generation", generation + 1);
            JSONObject revoke = postJson(controlTls, controlUrl + "/v1/member-peers/revoke", revokeRequest)
                    .optJSONObject("data");
            assertNotNull("Node Agent did not return a revocation receipt", revoke);
            assertEquals("confirmed_test", revoke.getString("confirmation_status"));
            assertTrue("final upload counter was empty", revoke.getLong("upload_bytes") > 0);
            assertTrue("final download counter was empty", revoke.getLong("download_bytes") > 0);
            peerRevoked = true;
            assertTrue("revocation did not fail closed in the VPN session",
                    awaitVpnNetworkGone(context, TimeUnit.SECONDS.toMillis(10)));
            assertFalse(XlbVpnService.currentTunnelIsMember());
            assertEquals(0, connectedStates.get());
        } finally {
            if (serviceStarted) {
                startVpnService(context, new Intent(context, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP));
                awaitVpnStopped(context, TimeUnit.SECONDS.toMillis(10));
            } else if (handoffId > 0) {
                MemberTunnelGrantStore.clear();
            }
            context.unregisterReceiver(receiver);
            deleteInstallationKey(installationId);
            if (peerProvisioned && !peerRevoked && controlTls != null) {
                try {
                    JSONObject cleanupRequest = new JSONObject()
                            .put("node_id", nodeId).put("peer_id", peerId)
                            .put("request_id", UUID.randomUUID().toString()).put("generation", generation + 1);
                    postJson(controlTls, controlUrl + "/v1/member-peers/revoke", cleanupRequest);
                } catch (Throwable ignored) {
                    // The isolated node's short TTL and stack expiry remain the cleanup backstop.
                }
            }
        }
    }

    private static SSLContext controlTlsContext(File caFile, File certificateFile, File privateKeyFile)
            throws Exception {
        CertificateFactory factory = CertificateFactory.getInstance("X.509");
        X509Certificate ca;
        X509Certificate client;
        try (InputStream input = new FileInputStream(caFile)) {
            ca = (X509Certificate) factory.generateCertificate(input);
        }
        try (InputStream input = new FileInputStream(certificateFile)) {
            client = (X509Certificate) factory.generateCertificate(input);
        }
        String privateKeyPem = new String(Files.readAllBytes(privateKeyFile.toPath()), StandardCharsets.US_ASCII)
                .replace("-----BEGIN PRIVATE KEY-----", "")
                .replace("-----END PRIVATE KEY-----", "")
                .replaceAll("\\s", "");
        byte[] privateKeyDer = android.util.Base64.decode(privateKeyPem, android.util.Base64.DEFAULT);
        PrivateKey privateKey = KeyFactory.getInstance("EC").generatePrivate(new PKCS8EncodedKeySpec(privateKeyDer));
        char[] password = "isolated-test".toCharArray();
        KeyStore clientKeys = KeyStore.getInstance("PKCS12");
        clientKeys.load(null, password);
        clientKeys.setKeyEntry("node-agent-control", privateKey, password, new Certificate[]{client, ca});
        KeyManagerFactory keyManagers = KeyManagerFactory.getInstance(KeyManagerFactory.getDefaultAlgorithm());
        keyManagers.init(clientKeys, password);

        KeyStore trust = KeyStore.getInstance(KeyStore.getDefaultType());
        trust.load(null, null);
        trust.setCertificateEntry("isolated-node-ca", ca);
        TrustManagerFactory trustManagers = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm());
        trustManagers.init(trust);
        SSLContext context = SSLContext.getInstance("TLSv1.3");
        context.init(keyManagers.getKeyManagers(), trustManagers.getTrustManagers(), null);
        return context;
    }

    private static JSONObject postJson(SSLContext tls, String endpoint, JSONObject payload) throws Exception {
        HttpsURLConnection connection = (HttpsURLConnection) new URL(endpoint).openConnection();
        connection.setSSLSocketFactory(tls.getSocketFactory());
        connection.setConnectTimeout(10_000);
        connection.setReadTimeout(15_000);
        connection.setRequestMethod("POST");
        connection.setDoOutput(true);
        connection.setRequestProperty("Content-Type", "application/json");
        try (java.io.OutputStream output = connection.getOutputStream()) {
            output.write(payload.toString().getBytes(StandardCharsets.UTF_8));
        }
        int status = connection.getResponseCode();
        InputStream body = status >= 400 ? connection.getErrorStream() : connection.getInputStream();
        String response;
        try (InputStream input = body) {
            response = new String(input.readAllBytes(), StandardCharsets.UTF_8);
        }
        connection.disconnect();
        assertEquals("Node Agent request failed: " + response, 200, status);
        return new JSONObject(response);
    }

    private static String resolveEgressProbeIPv4(Context context) throws Exception {
        ConnectivityManager manager = (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
        Network network = manager == null ? null : manager.getActiveNetwork();
        if (network == null) throw new java.io.IOException("egress probe network is unavailable before VPN start");
        java.net.URLConnection connection = network.openConnection(
                new URL("https://cloudflare-dns.com/dns-query?name=checkip.amazonaws.com&type=A"));
        connection.setConnectTimeout(8_000);
        connection.setReadTimeout(8_000);
        connection.setRequestProperty("Accept", "application/dns-json");
        String response;
        try (InputStream input = connection.getInputStream()) {
            response = new String(input.readAllBytes(), StandardCharsets.UTF_8);
        }
        JSONArray answers = new JSONObject(response).getJSONArray("Answer");
        for (int index = 0; index < answers.length(); index++) {
            JSONObject answer = answers.getJSONObject(index);
            if (answer.optInt("type") != 1) continue;
            InetAddress address = InetAddress.getByName(answer.getString("data"));
            if (address instanceof Inet4Address) return address.getHostAddress();
        }
        throw new java.io.IOException("egress probe did not resolve to IPv4 before VPN start");
    }

    private static String fetchEgressIp(Network network, String probeIPv4) throws Exception {
        String host = "checkip.amazonaws.com";
        try (Socket socket = network.getSocketFactory().createSocket()) {
            socket.connect(new InetSocketAddress(probeIPv4, 80), 8_000);
            socket.setSoTimeout(8_000);
            OutputStream output = socket.getOutputStream();
            output.write(("GET / HTTP/1.1\r\nHost: " + host
                    + "\r\nConnection: close\r\n\r\n").getBytes(StandardCharsets.US_ASCII));
            output.flush();

            BufferedReader response = new BufferedReader(new InputStreamReader(
                    socket.getInputStream(), StandardCharsets.US_ASCII));
            String status = response.readLine();
            if (status == null || !status.matches("HTTP/1\\.[01] 200(?: .*)?")) {
                throw new java.io.IOException("egress probe returned unexpected status: " + status);
            }
            String header;
            while ((header = response.readLine()) != null && !header.isEmpty()) {
                // The endpoint closes the response after returning the small public IP body.
            }
            if (header == null) throw new java.io.IOException("egress probe headers were incomplete");
            StringBuilder body = new StringBuilder();
            String line;
            while ((line = response.readLine()) != null) body.append(line);
            return body.toString().trim();
        }
    }

    private static void requireDisposableEmulator() {
        android.os.Bundle arguments = InstrumentationRegistry.getArguments();
        assertEquals("true", arguments.getString("disposable_avd"));
        String fingerprint = Build.FINGERPRINT == null ? "" : Build.FINGERPRINT;
        assertTrue("test must run only on a disposable Android emulator",
                fingerprint.contains("sdk_gphone") || fingerprint.contains("generic"));
    }

    private static String requiredArgument(android.os.Bundle arguments, String name) {
        String value = arguments.getString(name);
        assertNotNull("missing instrumentation argument: " + name, value);
        assertFalse(value.trim().isEmpty());
        return value;
    }

    private static String sha256(byte[] value) throws Exception {
        byte[] digest = MessageDigest.getInstance("SHA-256").digest(value);
        StringBuilder encoded = new StringBuilder(digest.length * 2);
        for (byte item : digest) encoded.append(String.format(java.util.Locale.ROOT, "%02x", item));
        return encoded.toString();
    }

    private static void registerReceiver(Context context, BroadcastReceiver receiver) {
        IntentFilter filter = new IntentFilter(XlbVpnService.ACTION_STATE);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            context.registerReceiver(receiver, filter, Context.RECEIVER_NOT_EXPORTED);
        } else {
            context.registerReceiver(receiver, filter);
        }
    }

    private static void requestVpnPermission(Context context) throws Exception {
        Intent approval = VpnService.prepare(context);
        if (approval == null) return;
        try (ActivityScenario<MainActivity> scenario = ActivityScenario.launch(MainActivity.class)) {
            scenario.onActivity(activity -> activity.startActivityForResult(approval, 7814));
            UiDevice device = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation());
            Pattern approvalLabel = Pattern.compile("(?i)^(allow|ok|允许)$");
            assertTrue(device.wait(Until.hasObject(By.text(approvalLabel)), TimeUnit.SECONDS.toMillis(15)));
            device.findObject(By.text(approvalLabel)).click();
            long deadline = System.currentTimeMillis() + TimeUnit.SECONDS.toMillis(10);
            while (VpnService.prepare(context) != null && System.currentTimeMillis() < deadline) Thread.sleep(100);
            assertEquals(null, VpnService.prepare(context));
        }
    }

    private static ComponentName startVpnService(Context context, Intent intent) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !XlbVpnService.ACTION_STOP.equals(intent.getAction())) {
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
            Thread.sleep(100);
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
            Thread.sleep(100);
        }
        return false;
    }

    private static void awaitVpnStopped(Context context, long timeoutMillis) throws Exception {
        long deadline = System.currentTimeMillis() + timeoutMillis;
        while (System.currentTimeMillis() < deadline) {
            if (!XlbVpnService.currentTunnelIsMember() && awaitVpnNetworkGone(context, 100)) return;
            Thread.sleep(100);
        }
    }

    private static void deleteInstallationKey(String installationId) throws Exception {
        KeyStore keyStore = KeyStore.getInstance("AndroidKeyStore");
        keyStore.load(null);
        String alias = "xlb-device-p256-v1-" + installationId;
        if (keyStore.containsAlias(alias)) keyStore.deleteEntry(alias);
    }
}
