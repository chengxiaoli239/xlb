package com.xlb.vpn.data;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URI;
import java.nio.charset.StandardCharsets;
import java.util.Base64;
import java.util.HashSet;
import java.util.Set;
import java.util.regex.Pattern;

public final class DebugProfileFetcher {
    private static final int MAX_BYTES = 128 * 1024;
    private static final int MAX_REDIRECTS = 4;
    private static final Pattern BASE64_PROFILE = Pattern.compile("[A-Za-z0-9_+/=\\s-]+");
    private static final Pattern MIHOMO_YAML = Pattern.compile(
            "(?m)^(?:proxies|proxy-providers|proxy-groups|rules|port|mixed-port|socks-port|dns|tun|mode|external-controller)\\s*:");

    private DebugProfileFetcher() {}

    public static String fetch(String address) throws IOException {
        return fetch(address, uri -> (HttpURLConnection) uri.toURL().openConnection());
    }

    static String fetch(String address, ConnectionFactory connectionFactory) throws IOException {
        URI uri;
        try {
            uri = URI.create(address.trim());
        } catch (IllegalArgumentException exception) {
            throw new IOException("订阅地址格式不正确");
        }
        validateHttpsUri(uri, "测试订阅必须使用 HTTPS 地址");
        Set<String> visited = new HashSet<>();
        int redirects = 0;
        while (true) {
            String normalizedAddress = uri.normalize().toASCIIString();
            if (!visited.add(normalizedAddress)) {
                throw new ProfileException("订阅地址存在重定向循环");
            }
            HttpURLConnection connection = connectionFactory.open(uri);
            connection.setInstanceFollowRedirects(false);
            connection.setConnectTimeout(8_000);
            connection.setReadTimeout(8_000);
            connection.setRequestProperty("Accept", "application/yaml, text/yaml, text/plain");
            connection.setRequestProperty("User-Agent", "Clash.Meta");
            try {
                int status = connection.getResponseCode();
                if (isRedirect(status)) {
                    if (redirects >= MAX_REDIRECTS) {
                        throw new ProfileException("订阅重定向次数过多");
                    }
                    uri = resolveRedirect(uri, connection.getHeaderField("Location"));
                    redirects++;
                    continue;
                }
                if (status != HttpURLConnection.HTTP_OK) {
                    throw new ProfileException("订阅请求未成功（HTTP " + status + "）");
                }
                requireDebugTestScope(connection.getHeaderField("X-XLB-Profile-Scope"));
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
                return normalizeProfile(profile);
            } finally {
                connection.disconnect();
            }
        }
    }

    interface ConnectionFactory {
        HttpURLConnection open(URI uri) throws IOException;
    }

    static URI resolveRedirect(URI source, String location) throws ProfileException {
        if (location == null || location.isBlank()) {
            throw new ProfileException("订阅重定向地址无效");
        }
        try {
            URI target = source.resolve(location.trim());
            validateHttpsUri(target, "订阅重定向必须使用 HTTPS 地址");
            return target;
        } catch (IllegalArgumentException exception) {
            throw new ProfileException("订阅重定向地址无效");
        }
    }

    private static void validateHttpsUri(URI uri, String error) throws ProfileException {
        if (!"https".equalsIgnoreCase(uri.getScheme()) || uri.getHost() == null || uri.getUserInfo() != null) {
            throw new ProfileException(error);
        }
    }

    static void requireDebugTestScope(String scope) throws ProfileException {
        if (!"local-test".equals(scope)) {
            throw new ProfileException("仅支持隔离测试订阅");
        }
    }

    private static boolean isRedirect(int status) {
        return status == HttpURLConnection.HTTP_MOVED_PERM
                || status == HttpURLConnection.HTTP_MOVED_TEMP
                || status == HttpURLConnection.HTTP_SEE_OTHER
                || status == 307
                || status == 308;
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

    static String normalizeProfile(String profile) throws ProfileException {
        String trimmed = profile.trim();
        if (BASE64_PROFILE.matcher(trimmed).matches()) {
            String decoded = decodeProfile(trimmed);
            if (decoded != null) {
                String candidate = decoded.trim();
                if (MIHOMO_YAML.matcher(candidate).find()
                        || candidate.startsWith("{") && (candidate.contains("\"proxies\"")
                        || candidate.contains("\"proxy-providers\""))) {
                    return candidate;
                }
                rejectUnsupportedFormat(candidate);
                throw new ProfileException("订阅没有返回 Clash/Mihomo 配置");
            }
        }
        rejectUnsupportedFormat(trimmed);
        return profile;
    }

    private static String decodeProfile(String value) {
        String compact = value.replaceAll("\\s", "");
        try {
            byte[] decoded = compact.indexOf('-') >= 0 || compact.indexOf('_') >= 0
                    ? Base64.getUrlDecoder().decode(compact)
                    : Base64.getDecoder().decode(compact);
            return new String(decoded, StandardCharsets.UTF_8);
        } catch (IllegalArgumentException ignored) {
            return null;
        }
    }

    public static final class ProfileException extends IOException {
        public ProfileException(String message) {
            super(message);
        }
    }
}
