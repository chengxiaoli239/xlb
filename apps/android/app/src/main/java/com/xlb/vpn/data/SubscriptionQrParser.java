package com.xlb.vpn.data;

import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.util.Locale;

public final class SubscriptionQrParser {
    private SubscriptionQrParser() {}

    public static String subscriptionUrl(String scanned) throws DebugProfileFetcher.ProfileException {
        if (scanned == null || scanned.length() > 8192) {
            throw new DebugProfileFetcher.ProfileException("二维码不是有效的订阅链接");
        }
        String value = scanned.trim();
        URI uri = parse(value);
        String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase(Locale.ROOT);
        if (scheme.equals("clash") || scheme.equals("clashmeta") || scheme.equals("mihomo")) {
            if (!"install-config".equalsIgnoreCase(uri.getHost())) {
                throw new DebugProfileFetcher.ProfileException("二维码不是受支持的订阅导入格式");
            }
            value = extractUrl(uri.getRawQuery());
            uri = parse(value);
        }
        if (!"https".equalsIgnoreCase(uri.getScheme()) || uri.getHost() == null || uri.getUserInfo() != null) {
            throw new DebugProfileFetcher.ProfileException("二维码中的订阅地址必须使用 HTTPS");
        }
        return value;
    }

    private static URI parse(String value) throws DebugProfileFetcher.ProfileException {
        try {
            return URI.create(value);
        } catch (IllegalArgumentException exception) {
            throw new DebugProfileFetcher.ProfileException("二维码不是有效的订阅链接");
        }
    }

    private static String extractUrl(String query) throws DebugProfileFetcher.ProfileException {
        if (query != null) {
            for (String pair : query.split("&")) {
                int separator = pair.indexOf('=');
                if (separator > 0 && pair.substring(0, separator).equals("url")) {
                    try {
                        return URLDecoder.decode(pair.substring(separator + 1), StandardCharsets.UTF_8.name());
                    } catch (Exception exception) {
                        break;
                    }
                }
            }
        }
        throw new DebugProfileFetcher.ProfileException("二维码中没有订阅地址");
    }
}
