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
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.regex.Pattern;

public final class PlatformApi {
    private static final String CLIENT_VERSION = "0.2.7";
    private static final Pattern DEVICE_ID = Pattern.compile(
            "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$");

    private final String baseUrl;
    private final SecureSessionStore sessionStore;

    public PlatformApi(String baseUrl, SecureSessionStore sessionStore) {
        this.baseUrl = baseUrl;
        this.sessionStore = sessionStore;
    }

    public MemberAccount currentAccount() throws PlatformApiException {
        JSONObject account = request("GET", "/api/v1/auth/session", null).optJSONObject("data");
        return toMemberAccount(account);
    }

    public MemberAccount login(String email, String password) throws PlatformApiException {
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

    public ClientSnapshot synchronize(MemberAccount account) throws PlatformApiException {
        try {
            String installationId = sessionStore.installationId();
            JSONObject registration = new JSONObject()
                    .put("installation_id", installationId)
                    .put("name", Build.MODEL.length() > 80 ? Build.MODEL.substring(0, 80) : Build.MODEL)
                    .put("platform", "android");
            String registrationMessage = null;
            try {
                request("POST", "/api/v1/me/devices", registration);
                try {
                    request("POST", "/api/v1/client/heartbeat", new JSONObject()
                            .put("installation_id", installationId)
                            .put("client_version", CLIENT_VERSION));
                } catch (PlatformApiException ignored) {
                    // Version telemetry is best effort and not a customer-facing state.
                }
            } catch (PlatformApiException exception) {
                registrationMessage = exception.getMessage();
            }
            return loadDevices(account, registrationMessage);
        } catch (PlatformApiException exception) {
            return new ClientSnapshot(account, null, "设备列表暂不可用，请稍后重试");
        } catch (Exception exception) {
            return new ClientSnapshot(account, null, "设备列表暂不可用，请稍后重试");
        }
    }

    public ClientSnapshot loadDevices(MemberAccount account) throws PlatformApiException {
        return loadDevices(account, null);
    }

    private ClientSnapshot loadDevices(MemberAccount account, String message) throws PlatformApiException {
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
                            installationId.equals(device.optString("installation_id"))));
                }
            }
            return new ClientSnapshot(account, new DeviceSnapshot(
                    devices.optInt("registered_count", entries.size()),
                    devices.optInt("max_devices"),
                    devices.optBoolean("entitlement_active"),
                    entries
            ), message);
        } catch (PlatformApiException exception) {
            throw exception;
        } catch (Exception exception) {
            throw new PlatformApiException("设备列表暂不可用，请稍后重试", exception);
        }
    }

    public void removeDevice(String deviceId) throws PlatformApiException {
        if (deviceId == null || !DEVICE_ID.matcher(deviceId).matches()) {
            throw new PlatformApiException("设备编号无效，请刷新设备列表");
        }
        request("DELETE", "/api/v1/me/devices/" + deviceId, null);
    }

    public void rotateInstallationId() {
        sessionStore.rotateInstallationId();
    }

    public void logout() throws PlatformApiException {
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
        return new MemberAccount(object.optString("id"), object.optString("name"), object.optString("email"));
    }

    public static final class MemberAccount {
        public final String id;
        public final String name;
        public final String email;

        public MemberAccount(String id, String name, String email) {
            this.id = id;
            this.name = name;
            this.email = email;
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

        public RegisteredDevice(String id, String name, String platform, boolean current) {
            this.id = id;
            this.name = name;
            this.platform = platform;
            this.current = current;
        }
    }

    public static final class ClientSnapshot {
        public final MemberAccount account;
        public final DeviceSnapshot device;
        public final String message;

        public ClientSnapshot(MemberAccount account, DeviceSnapshot device, String message) {
            this.account = account;
            this.device = device;
            this.message = message;
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
