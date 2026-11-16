package com.xlb.vpn.data;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URI;
import java.nio.charset.StandardCharsets;
import java.util.Base64;

public final class DebugProfileFetcher {
    private static final int MAX_BYTES = 128 * 1024;

    private DebugProfileFetcher() {}

    public static String fetch(String address) throws IOException {
        URI uri;
        try {
            uri = URI.create(address.trim());
        } catch (IllegalArgumentException exception) {
            throw new IOException("订阅地址格式不正确");
        }
        if (!"https".equalsIgnoreCase(uri.getScheme()) || uri.getHost() == null || uri.getUserInfo() != null) {
            throw new IOException("测试订阅必须使用 HTTPS 地址");
        }
        HttpURLConnection connection = (HttpURLConnection) uri.toURL().openConnection();
        connection.setInstanceFollowRedirects(false);
        connection.setConnectTimeout(8_000);
        connection.setReadTimeout(8_000);
        connection.setRequestProperty("Accept", "application/yaml, text/yaml, text/plain");
        connection.setRequestProperty("User-Agent", "Clash.Meta");
        try {
            int status = connection.getResponseCode();
            if (status != 200) {
                throw new ProfileException("订阅请求未成功（HTTP " + status + "）");
            }
            if (connection.getContentLengthLong() > MAX_BYTES) {
                throw new ProfileException("订阅配置超过 128 KB");
            }
            ByteArrayOutputStream bytes = new ByteArrayOutputStream();
            byte[] buffer = new byte[8192];
            try (InputStream input = connection.getInputStream()) {
                int count;
                while ((count = input.read(buffer)) != -1) {
                    if (bytes.size() + count > MAX_BYTES) {
                        throw new ProfileException("订阅配置超过 128 KB");
                    }
                    bytes.write(buffer, 0, count);
                }
            }
            String profile = bytes.toString(StandardCharsets.UTF_8.name());
            if (profile.isBlank()) {
                throw new ProfileException("订阅配置为空");
            }
            rejectUnsupportedFormat(profile);
            return profile;
        } finally {
            connection.disconnect();
        }
    }

    static void rejectUnsupportedFormat(String profile) throws ProfileException {
        String trimmed = profile.trim();
        if (trimmed.startsWith("<")) {
            throw new ProfileException("订阅返回了网页，请选择 Clash/Mihomo 专用订阅二维码");
        }
        if (trimmed.startsWith("ss://") || trimmed.startsWith("vmess://") || trimmed.startsWith("vless://")
                || trimmed.startsWith("trojan://") || trimmed.startsWith("hysteria://")) {
            throw new ProfileException("订阅返回了节点链接列表，请选择 Clash/Mihomo 专用订阅二维码");
        }
        if (trimmed.startsWith("{") && !trimmed.contains("\"proxies\"") && !trimmed.contains("\"proxy-providers\"")) {
            throw new ProfileException("订阅返回了非 Clash/Mihomo 的 JSON 响应");
        }
        if (trimmed.length() >= 16 && trimmed.matches("[A-Za-z0-9+/=\\s]+")) {
            try {
                String decoded = new String(Base64.getMimeDecoder().decode(trimmed), StandardCharsets.UTF_8).trim();
                if (decoded.startsWith("ss://") || decoded.startsWith("vmess://") || decoded.startsWith("vless://")
                        || decoded.startsWith("trojan://")) {
                    throw new ProfileException("订阅返回了 Base64 节点列表，请选择 Clash/Mihomo 专用订阅二维码");
                }
            } catch (IllegalArgumentException ignored) {
                // Leave other text to the Mihomo parser.
            }
        }
    }

    public static final class ProfileException extends IOException {
        public ProfileException(String message) {
            super(message);
        }
    }
}
