package com.xlb.vpn.data;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URI;
import java.nio.charset.StandardCharsets;
import java.util.regex.Pattern;

final class AccountProfileFetcher {
    private static final int MAX_BYTES = 128 * 1024;
    private static final Pattern SUBSCRIPTION_PATH = Pattern.compile("^/subscribe/[A-Za-z0-9_-]{32,64}$");

    private AccountProfileFetcher() {}

    static String fetch(String baseUrl, String path) throws IOException {
        if (path == null || !SUBSCRIPTION_PATH.matcher(path).matches()) {
            throw new IOException("账户订阅地址无效");
        }
        URI origin;
        try {
            origin = URI.create(baseUrl);
        } catch (IllegalArgumentException exception) {
            throw new IOException("账户服务地址无效", exception);
        }
        boolean localDebug = "http".equalsIgnoreCase(origin.getScheme())
                && ("127.0.0.1".equals(origin.getHost()) || "localhost".equals(origin.getHost()));
        if (origin.getHost() == null || origin.getUserInfo() != null
                || !("https".equalsIgnoreCase(origin.getScheme()) || localDebug)) {
            throw new IOException("账户服务地址无效");
        }

        HttpURLConnection connection = (HttpURLConnection) URI.create(
                baseUrl.replaceAll("/$", "") + path + "?format=mihomo&mode=global").toURL().openConnection();
        connection.setInstanceFollowRedirects(false);
        connection.setConnectTimeout(8_000);
        connection.setReadTimeout(8_000);
        connection.setRequestProperty("Accept", "text/yaml");
        connection.setRequestProperty("Cache-Control", "no-store");
        try {
            if (connection.getResponseCode() != HttpURLConnection.HTTP_OK) {
                throw new IOException("服务线路暂不可用");
            }
            // The seeded local-test route contains documentation addresses, not usable nodes.
            if (!"production".equals(connection.getHeaderField("X-XLB-Profile-Scope"))) {
                throw new IOException("服务线路暂不可用");
            }
            if (connection.getContentLengthLong() > MAX_BYTES) {
                throw new IOException("账户线路配置过大");
            }
            ByteArrayOutputStream bytes = new ByteArrayOutputStream();
            byte[] buffer = new byte[8192];
            try (InputStream input = connection.getInputStream()) {
                int count;
                while ((count = input.read(buffer)) != -1) {
                    if (bytes.size() + count > MAX_BYTES) {
                        throw new IOException("账户线路配置过大");
                    }
                    bytes.write(buffer, 0, count);
                }
            }
            String profile = bytes.toString(StandardCharsets.UTF_8.name());
            if (profile.isBlank()) {
                throw new IOException("服务线路暂不可用");
            }
            return DebugProfileFetcher.normalizeProfile(profile);
        } finally {
            connection.disconnect();
        }
    }
}
