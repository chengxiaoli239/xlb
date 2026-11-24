package com.xlb.vpn.processdeathhost;

import android.util.Base64;

import org.json.JSONArray;
import org.json.JSONObject;

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
import java.security.KeyStore;
import java.security.MessageDigest;
import java.security.Signature;
import java.security.cert.X509Certificate;
import java.security.spec.X509EncodedKeySpec;
import java.util.Enumeration;
import java.util.Locale;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicReference;
import java.util.concurrent.atomic.AtomicInteger;

final class IsolatedMemberApi implements Closeable {
    private static final String SESSION_COOKIE = "xlb_session";
    private static final String SESSION_COOKIE_VALUE = "isolated-member-session";
    private static final String MEMBER_ID = "member-avd";
    private static final String DEVICE_ID = "81000000-0000-4000-8000-000000000001";
    private static final String NODE_ID = "81000000-0000-4000-8000-000000000003";
    private static final String PEER_ID = "81000000-0000-4000-8000-000000000002";
    private static final String PROFILE = "mixed-port: 7890\n"
            + "allow-lan: false\n"
            + "bind-address: 127.0.0.1\n"
            + "mode: global\n"
            + "log-level: warning\n"
            + "proxies:\n"
            + "  - name: XLB proof bridge\n"
            + "    type: socks5\n"
            + "    server: 127.0.0.1\n"
            + "    port: 17890\n"
            + "    udp: false\n"
            + "proxy-groups:\n"
            + "  - name: GLOBAL\n"
            + "    type: select\n"
            + "    proxies:\n"
            + "      - XLB proof bridge\n"
            + "rules:\n"
            + "  - MATCH,GLOBAL\n";

    private final String expectedEmail;
    private final String expectedPassword;
    private final ServerSocket serverSocket;
    private final String trustedCaPem;
    private final Thread serverThread;
    private final AtomicReference<Throwable> failure = new AtomicReference<>();
    private final AtomicInteger authorizationRequests = new AtomicInteger();
    private volatile String installationId;
    private volatile String publicKey;
    private volatile byte[] challenge;
    private volatile boolean challengeSignatureVerified;
    private volatile boolean authenticated;

    IsolatedMemberApi(String email, String password, int port) throws Exception {
        expectedEmail = email;
        expectedPassword = password;
        trustedCaPem = trustedSystemCaPem();
        serverSocket = new ServerSocket(port, 8, InetAddress.getByName("127.0.0.1"));
        serverSocket.setSoTimeout(1_000);
        serverThread = new Thread(this::serve, "process-death-member-api");
    }

    void start() {
        serverThread.start();
    }

    int authorizationRequests() {
        return authorizationRequests.get();
    }

    boolean challengeSignatureVerified() {
        return challengeSignatureVerified;
    }

    Throwable failure() {
        return failure.get();
    }

    @Override
    public void close() {
        try {
            serverSocket.close();
            serverThread.join(2_000);
        } catch (InterruptedException exception) {
            Thread.currentThread().interrupt();
        } catch (IOException exception) {
            failure.compareAndSet(null, exception);
        }
    }

    private void serve() {
        while (!serverSocket.isClosed()) {
            try (Socket socket = serverSocket.accept()) {
                socket.setSoTimeout(5_000);
                handle(socket);
            } catch (java.net.SocketTimeoutException ignored) {
                // Re-check the close state without keeping the test process alive.
            } catch (IOException | RuntimeException exception) {
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
        String cookies = "";
        String header;
        while ((header = readLine(input)) != null && !header.isEmpty()) {
            int separator = header.indexOf(':');
            if (separator > 0) {
                String name = header.substring(0, separator).trim();
                String value = header.substring(separator + 1).trim();
                if ("content-length".equalsIgnoreCase(name)) contentLength = Integer.parseInt(value);
                if ("cookie".equalsIgnoreCase(name)) cookies = value;
            }
        }
        byte[] body = readExactly(input, contentLength);
        JSONObject response = respond(method, path, body, cookies);
        boolean issueSession = "POST".equals(method) && "/api/v1/auth/login".equals(path) && authenticated;
        writeResponse(socket.getOutputStream(), response, issueSession);
    }

    private JSONObject respond(String method, String path, byte[] body, String cookies) throws Exception {
        if ("GET".equals(method) && "/api/v1/auth/csrf".equals(path)) {
            return data(new JSONObject().put("token", "isolated-csrf-token"));
        }
        if ("POST".equals(method) && "/api/v1/auth/login".equals(path)) {
            JSONObject credentials = new JSONObject(new String(body, StandardCharsets.UTF_8));
            if (!expectedEmail.equals(credentials.optString("email"))
                    || !expectedPassword.equals(credentials.optString("password"))) {
                return error(401, "invalid sandbox credentials");
            }
            authenticated = true;
            return data(memberAccount());
        }
        if ("GET".equals(method) && "/api/v1/auth/session".equals(path)) {
            return new JSONObject().put("data", hasSession(cookies) ? memberAccount() : JSONObject.NULL);
        }
        if (path.startsWith("/api/v1/me/") && !hasSession(cookies)) return error(401, "unauthenticated");
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
                    .put("challenge", Base64.encodeToString(challenge,
                            Base64.URL_SAFE | Base64.NO_WRAP | Base64.NO_PADDING)));
        }
        if ("POST".equals(method) && "/api/v1/me/device-bindings/authorize".equals(path)) {
            authorizationRequests.incrementAndGet();
            JSONObject proof = new JSONObject(new String(body, StandardCharsets.UTF_8));
            challengeSignatureVerified = verifyChallengeSignature(proof.optString("signature"));
            if (!challengeSignatureVerified) return error(422, "device signature did not verify");
            return data(readyAuthorization());
        }
        if ("GET".equals(method) && "/api/v1/me/devices".equals(path)) {
            JSONArray devices = new JSONArray().put(new JSONObject()
                    .put("id", DEVICE_ID)
                    .put("name", "Disposable Android")
                    .put("platform", "android")
                    .put("installation_id", installationId)
                    .put("status", "registered")
                    .put("removal_pending", false));
            return data(new JSONObject().put("registered_count", 1)
                    .put("max_devices", 5)
                    .put("entitlement_active", true)
                    .put("devices", devices));
        }
        return error(404, "unexpected isolated API route " + method + " " + path);
    }

    private JSONObject memberAccount() throws Exception {
        return new JSONObject().put("id", MEMBER_ID)
                .put("name", "Disposable member")
                .put("email", expectedEmail)
                .put("role", "member")
                .put("account_type", "full");
    }

    private boolean hasSession(String cookies) {
        for (String cookie : cookies.split(";")) {
            String[] pair = cookie.trim().split("=", 2);
            if (pair.length == 2 && SESSION_COOKIE.equals(pair[0])
                    && SESSION_COOKIE_VALUE.equals(pair[1])) return authenticated;
        }
        return false;
    }

    private JSONObject readyAuthorization() throws Exception {
        String peerCredential = Base64.encodeToString(new byte[32], Base64.URL_SAFE | Base64.NO_WRAP | Base64.NO_PADDING);
        return new JSONObject()
                .put("device", new JSONObject().put("id", DEVICE_ID).put("status", "registered").put("bound", true))
                .put("node_authorization", new JSONObject()
                        .put("status", "ready")
                        .put("peer_id", PEER_ID)
                        .put("node_id", NODE_ID)
                        .put("peer_generation", 3)
                        .put("device_binding_generation", 7)
                        .put("peer_credential", peerCredential)
                        .put("profile", PROFILE)
                        .put("bridge_endpoint", "https://node.example.invalid:9443")
                        .put("bridge_server_ca_pem", trustedCaPem)
                        .put("profile_sha256", sha256(PROFILE))
                        .put("profile_renderer_id", "xlb-mihomo-bridge-v1")
                        .put("profile_renderer_version", "1.0.0")
                        .put("bridge_protocol", "xlb-proof-v1")
                        .put("connectable", true));
    }

    private boolean verifyChallengeSignature(String encodedSignature) throws Exception {
        if (installationId == null || publicKey == null || challenge == null) return false;
        byte[] encodedKey = Base64.decode(publicKey, Base64.URL_SAFE | Base64.NO_WRAP | Base64.NO_PADDING);
        java.security.PublicKey key = KeyFactory.getInstance("EC").generatePublic(new X509EncodedKeySpec(encodedKey));
        Signature verifier = Signature.getInstance("SHA256withECDSA");
        verifier.initVerify(key);
        verifier.update(challenge);
        byte[] signature = Base64.decode(encodedSignature, Base64.URL_SAFE | Base64.NO_WRAP | Base64.NO_PADDING);
        return verifier.verify(signature);
    }

    private static String trustedSystemCaPem() throws Exception {
        KeyStore systemRoots = KeyStore.getInstance("AndroidCAStore");
        systemRoots.load(null);
        Enumeration<String> aliases = systemRoots.aliases();
        while (aliases.hasMoreElements()) {
            java.security.cert.Certificate candidate = systemRoots.getCertificate(aliases.nextElement());
            if (!(candidate instanceof X509Certificate)) continue;
            X509Certificate certificate = (X509Certificate) candidate;
            boolean[] keyUsage = certificate.getKeyUsage();
            if (certificate.getBasicConstraints() < 0 || keyUsage == null || keyUsage.length <= 5 || !keyUsage[5]) {
                continue;
            }
            certificate.checkValidity();
            return "-----BEGIN CERTIFICATE-----\n"
                    + Base64.encodeToString(certificate.getEncoded(), Base64.NO_WRAP)
                    + "\n-----END CERTIFICATE-----\n";
        }
        throw new IOException("Android system trust store has no valid CA certificate");
    }

    private static String sha256(String value) throws Exception {
        byte[] digest = MessageDigest.getInstance("SHA-256").digest(value.getBytes(StandardCharsets.UTF_8));
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

    private static void writeResponse(OutputStream output, JSONObject response, boolean issueSession)
            throws IOException {
        byte[] body = response.toString().getBytes(StandardCharsets.UTF_8);
        JSONObject error = response.optJSONObject("error");
        int status = error == null ? 200 : error.optInt("status", 400);
        String reason = status == 200 ? "OK" : status == 401 ? "Unauthorized" : "Bad Request";
        String headers = "HTTP/1.1 " + status + " " + reason + "\r\n"
                + "Content-Type: application/json; charset=utf-8\r\n"
                + (issueSession ? "Set-Cookie: " + SESSION_COOKIE + "=" + SESSION_COOKIE_VALUE
                        + "; Path=/; HttpOnly; SameSite=Lax\r\n" : "")
                + "Content-Length: " + body.length + "\r\n"
                + "Connection: close\r\n\r\n";
        output.write(headers.getBytes(StandardCharsets.US_ASCII));
        output.write(body);
        output.flush();
    }
}
