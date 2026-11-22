package com.xlb.vpn.data;

import android.os.Build;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.IOException;
import java.io.InputStream;
import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.regex.Pattern;

public final class PlatformApi {
    private static final String CLIENT_VERSION = "0.2.15";
    private static final Pattern DEVICE_ID = Pattern.compile(
            "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$");

    private final String baseUrl;
    private final SecureSessionStore sessionStore;
    private final DeviceKeyStore deviceKeyStore = new DeviceKeyStore();
    private MemberDeviceProof memberDeviceProof;

    public PlatformApi(String baseUrl, SecureSessionStore sessionStore) {
        this.baseUrl = baseUrl;
        this.sessionStore = sessionStore;
    }

    public MemberAccount currentAccount() throws PlatformApiException {
        memberDeviceProof = null;
        JSONObject account = request("GET", "/api/v1/auth/session", null).optJSONObject("data");
        if (account != null && !"member".equals(account.optString("role"))) {
            sessionStore.clearSession();
            return null;
        }
        return toMemberAccount(account);
    }

    public MemberAccount login(String email, String password) throws PlatformApiException {
        memberDeviceProof = null;
        JSONObject payload = new JSONObject();
        try {
            payload.put("email", email.trim());
            payload.put("password", password);
        } catch (Exception exception) {
            throw new PlatformApiException("登录请求格式不正确", exception);
        }
        MemberAccount account = toMemberAccount(request("POST", "/api/v1/auth/login", payload).optJSONObject("data"));
        if (account == null || account.id.isEmpty() || account.email.isEmpty() || account.name.isEmpty()) {
            throw new PlatformApiException("登录响应格式不正确");
        }
        JSONObject session = request("GET", "/api/v1/auth/session", null).optJSONObject("data");
        if (session == null || !"member".equals(session.optString("role"))) {
            sessionStore.clearSession();
            throw new PlatformApiException("迅连宝客户端仅支持会员账户");
        }
        return account;
    }

    public MemberAccount registerAnonymousAccount(boolean userConsented) throws PlatformApiException {
        memberDeviceProof = null;
        if (!userConsented) {
            throw new PlatformApiException("需要先确认匿名账号使用说明");
        }

        JSONObject payload = new JSONObject();
        try {
            sessionStore.anonymousRecoverySecret();
            sessionStore.recordAnonymousRecoveryConsent();
            payload.put("installation_id", sessionStore.installationId());
            payload.put("recovery_secret", sessionStore.anonymousRecoverySecret());
            payload.put("consent", true);
        } catch (Exception exception) {
            throw new PlatformApiException("匿名账号注册请求格式不正确", exception);
        }

        JSONObject data = request("POST", "/api/v1/auth/register-anonymous", payload).optJSONObject("data");
        MemberAccount account = toMemberAccount(data);
        if (account == null || account.id.isEmpty() || !"anonymous".equals(account.accountType)) {
            throw new PlatformApiException("匿名账号注册响应格式不正确");
        }

        JSONObject session = request("GET", "/api/v1/auth/session", null).optJSONObject("data");
        if (session == null || !account.id.equals(session.optString("id"))
                || !"anonymous".equals(session.optString("account_type"))) {
            sessionStore.clearSession();
            throw new PlatformApiException("无法恢复匿名账号会话");
        }

        return account;
    }

    public MemberAccount restoreAnonymousAccount() throws PlatformApiException {
        if (!sessionStore.hasAnonymousRecoveryConsent()) {
            return null;
        }

        return registerAnonymousAccount(true);
    }

    public ClientSnapshot synchronize(MemberAccount account) throws PlatformApiException {
        memberDeviceProof = null;
        try {
            ClientSnapshot overview = withOverview(new ClientSnapshot(account, null, null));
            if (overview.entitlement == null) {
                return withOverview(loadDevices(account, null));
            }
            String registrationMessage = null;
            NodeAuthorizationStatus nodeAuthorizationStatus = NodeAuthorizationStatus.UNAVAILABLE;
            MemberDeviceProof authorizedProof = null;
            try {
                String installationId = sessionStore.installationId();
                String publicKey = deviceKeyStore.publicKey(installationId);
                JSONObject binding = new JSONObject()
                        .put("installation_id", installationId)
                        .put("name", Build.MODEL.length() > 80 ? Build.MODEL.substring(0, 80) : Build.MODEL)
                        .put("platform", "android")
                        .put("public_key", publicKey);
                JSONObject challenge = request("POST", "/api/v1/me/device-bindings/challenge", binding).optJSONObject("data");
                if (challenge == null || challenge.optString("challenge_id").isEmpty()) {
                    throw new PlatformApiException("设备授权响应格式不正确");
                }
                JSONObject proof = new JSONObject()
                        .put("challenge_id", challenge.optString("challenge_id"))
                        .put("signature", deviceKeyStore.sign(installationId, challenge.optString("challenge")));
                JSONObject authorization = request("POST", "/api/v1/me/device-bindings/authorize", proof).optJSONObject("data");
                if (authorization == null || authorization.optJSONObject("device") == null) {
                    throw new PlatformApiException("设备授权响应格式不正确");
                }
                JSONObject nodeAuthorization = authorization.optJSONObject("node_authorization");
                nodeAuthorizationStatus = NodeAuthorizationStatus.fromStatus(
                        nodeAuthorization == null ? null : nodeAuthorization.optString("status", null));
                if (nodeAuthorizationStatus == NodeAuthorizationStatus.READY) {
                    JSONObject authorizedDevice = authorization.optJSONObject("device");
                    authorizedProof = MemberDeviceProof.fromAuthorizedResponse(
                            account.id,
                            installationId,
                            publicKey,
                            authorizedDevice == null ? null : authorizedDevice.optString("id", null),
                            authorizedDevice == null ? null : authorizedDevice.optString("status", null),
                            authorizedDevice != null && authorizedDevice.optBoolean("bound", false),
                            nodeAuthorization.optString("status", null),
                            nodeAuthorization.optString("peer_id", null),
                            nodeAuthorization.optString("node_id", null),
                            nodeAuthorization.optLong("peer_generation", 0),
                            nodeAuthorization.optLong("device_binding_generation", 0),
                            nodeAuthorization.optString("peer_credential", null),
                            nodeAuthorization.optString("profile", null),
                            deviceSigner(installationId));
                    if (authorizedProof == null) {
                        nodeAuthorizationStatus = NodeAuthorizationStatus.UNAVAILABLE;
                    }
                }
                try {
                    request("POST", "/api/v1/client/heartbeat", new JSONObject()
                            .put("installation_id", installationId)
                            .put("client_version", CLIENT_VERSION));
                } catch (PlatformApiException ignored) {
                    // Version telemetry is best effort and not a customer-facing state.
                }
            } catch (PlatformApiException | GeneralSecurityException exception) {
                registrationMessage = exception.getMessage();
            }
            ClientSnapshot snapshot = withOverview(loadDevices(account, registrationMessage, nodeAuthorizationStatus));
            if (authorizedProof != null && authorizedProof.matches(
                    account.id, snapshot.device, snapshot.entitlement != null)) {
                memberDeviceProof = authorizedProof;
            } else if (nodeAuthorizationStatus == NodeAuthorizationStatus.READY) {
                memberDeviceProof = null;
                return snapshot.withNodeAuthorizationStatus(NodeAuthorizationStatus.UNAVAILABLE);
            }
            return snapshot;
        } catch (PlatformApiException exception) {
            memberDeviceProof = null;
            return new ClientSnapshot(account, null, "设备列表暂不可用，请稍后重试");
        } catch (Exception exception) {
            memberDeviceProof = null;
            return new ClientSnapshot(account, null, "设备列表暂不可用，请稍后重试");
        }
    }

    public ClientSnapshot loadDevices(MemberAccount account) throws PlatformApiException {
        memberDeviceProof = null;
        return withOverview(loadDevices(account, null));
    }

    private MemberDeviceProof.Signer deviceSigner(String installationId) {
        return new MemberDeviceProof.Signer() {
            @Override
            public String publicKeySpkiBase64Url() throws GeneralSecurityException {
                return deviceKeyStore.publicKey(installationId);
            }

            @Override
            public String signChallengeBase64Url(byte[] challenge) throws GeneralSecurityException {
                return deviceKeyStore.sign(installationId, DeviceBindingProof.encode(challenge));
            }
        };
    }

    private ClientSnapshot withOverview(ClientSnapshot current) {
        try {
            JSONObject overview = request("GET", "/api/v1/me/overview", null).optJSONObject("data");
            if (overview == null) return current;
            JSONObject active = overview.optJSONObject("entitlement");
            EntitlementSummary entitlement = active == null ? null : new EntitlementSummary(
                    active.optString("plan_name"), active.optString("starts_at"), active.optString("ends_at"),
                    number(active, "traffic_gb"), number(active, "data_used_gb"), active.optInt("max_devices"));
            return current.withOverview(entitlement, overview.optInt("activeSubscriptions"));
        } catch (PlatformApiException exception) {
            return current;
        }
    }

    private static double number(JSONObject object, String field) {
        if (!object.has(field) || object.isNull(field)) return 0;
        double value = object.optDouble(field, 0);
        return Double.isFinite(value) ? value : 0;
    }

    private ClientSnapshot loadDevices(MemberAccount account, String message) throws PlatformApiException {
        return loadDevices(account, message, NodeAuthorizationStatus.UNAVAILABLE);
    }

    private ClientSnapshot loadDevices(MemberAccount account, String message,
                                       NodeAuthorizationStatus nodeAuthorizationStatus) throws PlatformApiException {
        try {
            String installationId = sessionStore.installationId();
            JSONObject devices = request("GET", "/api/v1/me/devices", null).optJSONObject("data");
            if (devices == null) {
                throw new PlatformApiException("设备列表响应格式不正确");
            }
            JSONArray registered = devices.optJSONArray("devices");
            if (registered == null) {
                throw new PlatformApiException("设备列表响应格式不正确");
            }
            List<RegisteredDevice> entries = new ArrayList<>();
            for (int index = 0; index < registered.length(); index++) {
                JSONObject device = registered.optJSONObject(index);
                if (device != null) {
                    String name = device.optString("name").trim();
                    entries.add(new RegisteredDevice(device.optString("id"), name.isEmpty() ? "未命名设备" : name,
                            device.optString("platform"),
                            installationId.equals(device.optString("installation_id")),
                            device.optBoolean("removal_pending"), device.optString("installation_id"),
                            device.optString("status")));
                }
            }
            return new ClientSnapshot(account, new DeviceSnapshot(
                    devices.optInt("registered_count", entries.size()),
                    devices.optInt("max_devices"),
                    devices.optBoolean("entitlement_active"),
                    entries
            ), message, null, 0, null, nodeAuthorizationStatus);
        } catch (PlatformApiException exception) {
            throw exception;
        } catch (Exception exception) {
            throw new PlatformApiException("设备列表暂不可用，请稍后重试", exception);
        }
    }

    public boolean removeDevice(String deviceId) throws PlatformApiException {
        memberDeviceProof = null;
        if (deviceId == null || !DEVICE_ID.matcher(deviceId).matches()) {
            throw new PlatformApiException("设备编号无效，请刷新设备列表");
        }
        JSONObject data = request("DELETE", "/api/v1/me/devices/" + deviceId, null).optJSONObject("data");
        return data != null && "revocation_pending".equals(data.optString("status"));
    }

    public void rotateInstallationId() {
        memberDeviceProof = null;
        sessionStore.rotateInstallationId();
    }

    public void logout() throws PlatformApiException {
        memberDeviceProof = null;
        try {
            request("POST", "/api/v1/auth/logout", new JSONObject());
        } finally {
            sessionStore.clearSession();
        }
    }

    private JSONObject request(String method, String path, JSONObject body) throws PlatformApiException {
        String csrfToken = null;
        if (!"GET".equals(method) && !"HEAD".equals(method)) {
            JSONObject csrfResponse = requestRaw("GET", "/api/v1/auth/csrf", null, null);
            JSONObject csrfData = csrfResponse.optJSONObject("data");
            csrfToken = csrfData == null ? "" : csrfData.optString("token", "");
            if (csrfToken.isEmpty()) {
                throw new PlatformApiException("无法建立安全会话，请检查网络后重试");
            }
        }
        return requestRaw(method, path, body, csrfToken);
    }

    private JSONObject requestRaw(String method, String path, JSONObject body, String csrfToken) throws PlatformApiException {
        HttpURLConnection connection = null;
        try {
            connection = (HttpURLConnection) new URL(baseUrl.replaceAll("/$", "") + path).openConnection();
            connection.setRequestMethod(method);
            connection.setInstanceFollowRedirects(false);
            connection.setConnectTimeout(8_000);
            connection.setReadTimeout(8_000);
            connection.setRequestProperty("Accept", "application/json");
            connection.setRequestProperty("Cache-Control", "no-store");
            String cookies = sessionStore.cookieHeader();
            if (!cookies.isEmpty()) {
                connection.setRequestProperty("Cookie", cookies);
            }
            if (csrfToken != null) {
                connection.setRequestProperty("X-CSRF-TOKEN", csrfToken);
            }
            if (body != null) {
                connection.setDoOutput(true);
                connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
                try (java.io.OutputStream output = connection.getOutputStream()) {
                    output.write(body.toString().getBytes(StandardCharsets.UTF_8));
                }
            }
            storeCookies(connection.getHeaderFields());
            int statusCode = connection.getResponseCode();
            InputStream stream = null;
            if (statusCode != HttpURLConnection.HTTP_NO_CONTENT && statusCode != 205) {
                stream = statusCode >= 400 ? connection.getErrorStream() : connection.getInputStream();
            }
            String responseBody = readBody(stream);
            JSONObject response = responseBody.trim().isEmpty() ? new JSONObject() : new JSONObject(responseBody);
            if (statusCode < 200 || statusCode >= 300) {
                JSONObject error = response.optJSONObject("error");
                String message = error == null ? "服务暂时不可用（" + statusCode + "）" : error.optString("message");
                throw new PlatformApiException(message.isEmpty() ? "请求失败" : message);
            }
            return response;
        } catch (PlatformApiException exception) {
            throw exception;
        } catch (IOException exception) {
            throw new PlatformApiException("无法连接迅连宝服务，请检查网络后重试", exception);
        } catch (Exception exception) {
            throw new PlatformApiException("服务响应格式不正确", exception);
        } finally {
            if (connection != null) {
                connection.disconnect();
            }
        }
    }

    private String readBody(InputStream stream) throws IOException {
        if (stream == null) {
            return "";
        }
        StringBuilder body = new StringBuilder();
        try (BufferedReader reader = new BufferedReader(new InputStreamReader(stream, StandardCharsets.UTF_8))) {
            String line;
            while ((line = reader.readLine()) != null) {
                body.append(line).append('\n');
            }
        }
        return body.toString();
    }

    private void storeCookies(Map<String, List<String>> headers) {
        for (Map.Entry<String, List<String>> entry : headers.entrySet()) {
            if (entry.getKey() == null || !"Set-Cookie".equalsIgnoreCase(entry.getKey())) {
                continue;
            }
            if (entry.getValue() == null) {
                continue;
            }
            for (String header : entry.getValue()) {
                String[] parts = header.split(";", 2);
                String[] pair = parts[0].split("=", 2);
                if (pair.length != 2 || pair[0].trim().isEmpty()) {
                    continue;
                }
                boolean delete = parts.length > 1 && parts[1].toLowerCase(Locale.ROOT).contains("max-age=0");
                sessionStore.updateCookie(pair[0].trim(), pair[1].trim(), delete);
            }
        }
    }

    private MemberAccount toMemberAccount(JSONObject object) {
        if (object == null || object.isNull("id")) {
            return null;
        }
        return new MemberAccount(
                object.optString("id"),
                object.optString("name"),
                object.isNull("email") ? "" : object.optString("email"),
                object.optString("account_type")
        );
    }

    public static final class MemberAccount {
        public final String id;
        public final String name;
        public final String email;
        public final String accountType;

        public MemberAccount(String id, String name, String email, String accountType) {
            this.id = id;
            this.name = name;
            this.email = email;
            this.accountType = accountType;
        }
    }

    public static final class DeviceSnapshot {
        public final int registered;
        public final int maximum;
        public final boolean entitlementActive;
        public final List<RegisteredDevice> devices;

        public DeviceSnapshot(int registered, int maximum, boolean entitlementActive, List<RegisteredDevice> devices) {
            this.registered = registered;
            this.maximum = maximum;
            this.entitlementActive = entitlementActive;
            this.devices = devices;
        }
    }

    public static final class RegisteredDevice {
        public final String id;
        public final String name;
        public final String platform;
        public final boolean current;
        public final boolean removalPending;
        public final String installationId;
        public final String status;

        public RegisteredDevice(String id, String name, String platform, boolean current, boolean removalPending) {
            this(id, name, platform, current, removalPending, "", "registered");
        }

        public RegisteredDevice(String id, String name, String platform, boolean current, boolean removalPending,
                                String installationId, String status) {
            this.id = id;
            this.name = name;
            this.platform = platform;
            this.current = current;
            this.removalPending = removalPending;
            this.installationId = installationId;
            this.status = status;
        }
    }

    public static final class ClientSnapshot {
        public final MemberAccount account;
        public final DeviceSnapshot device;
        public final String message;
        public final EntitlementSummary entitlement;
        public final int subscriptionCount;
        public final String accountProfile;
        public final NodeAuthorizationStatus nodeAuthorizationStatus;

        public ClientSnapshot(MemberAccount account, DeviceSnapshot device, String message) {
            this(account, device, message, null, 0);
        }

        public ClientSnapshot(MemberAccount account, DeviceSnapshot device, String message,
                              EntitlementSummary entitlement, int subscriptionCount) {
            this(account, device, message, entitlement, subscriptionCount, null);
        }

        public ClientSnapshot(MemberAccount account, DeviceSnapshot device, String message,
                              EntitlementSummary entitlement, int subscriptionCount, String accountProfile) {
            this(account, device, message, entitlement, subscriptionCount, accountProfile,
                    NodeAuthorizationStatus.UNAVAILABLE);
        }

        public ClientSnapshot(MemberAccount account, DeviceSnapshot device, String message,
                              EntitlementSummary entitlement, int subscriptionCount, String accountProfile,
                              NodeAuthorizationStatus nodeAuthorizationStatus) {
            this.account = account;
            this.device = device;
            this.message = message;
            this.entitlement = entitlement;
            this.subscriptionCount = subscriptionCount;
            this.accountProfile = accountProfile;
            this.nodeAuthorizationStatus = nodeAuthorizationStatus == null
                    ? NodeAuthorizationStatus.UNAVAILABLE : nodeAuthorizationStatus;
        }

        ClientSnapshot withOverview(EntitlementSummary overviewEntitlement, int activeSubscriptions) {
            return new ClientSnapshot(account, device, message, overviewEntitlement, activeSubscriptions,
                    accountProfile, nodeAuthorizationStatus);
        }

        ClientSnapshot withNodeAuthorizationStatus(NodeAuthorizationStatus status) {
            return new ClientSnapshot(account, device, message, entitlement, subscriptionCount,
                    accountProfile, status);
        }
    }

    public enum NodeAuthorizationStatus {
        UNAVAILABLE,
        PROVISIONING,
        READY;

        static NodeAuthorizationStatus fromStatus(String status) {
            if ("provisioning".equals(status)) return PROVISIONING;
            if ("ready".equals(status)) return READY;
            return UNAVAILABLE;
        }
    }

    public static final class EntitlementSummary {
        public final String planName;
        public final String startsAt;
        public final String endsAt;
        public final double trafficGb;
        public final double dataUsedGb;
        public final int maximumDevices;

        public EntitlementSummary(String planName, String startsAt, String endsAt,
                                  double trafficGb, double dataUsedGb, int maximumDevices) {
            this.planName = planName;
            this.startsAt = startsAt;
            this.endsAt = endsAt;
            this.trafficGb = trafficGb;
            this.dataUsedGb = dataUsedGb;
            this.maximumDevices = maximumDevices;
        }
    }

    public static final class PlatformApiException extends Exception {
        public PlatformApiException(String message) {
            super(message);
        }

        public PlatformApiException(String message, Throwable cause) {
            super(message, cause);
        }
    }
}
