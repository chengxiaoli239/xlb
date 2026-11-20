package com.xlb.vpn.data;

import org.junit.Test;

import java.net.URI;
import java.nio.charset.StandardCharsets;
import java.util.Base64;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.fail;

public final class DebugProfileFetcherTest {
    @Test
    public void identifiesCommonNonMihomoResponsesWithoutLeakingContent() throws Exception {
        String encoded = Base64.getEncoder().encodeToString("ss://private-value".getBytes(StandardCharsets.UTF_8));
        for (String profile : new String[] {"<html>private-value</html>", "ss://private-value", encoded, "{\"error\":\"private-value\"}"}) {
            try {
                DebugProfileFetcher.rejectUnsupportedFormat(profile);
                fail("Expected unsupported profile format");
            } catch (DebugProfileFetcher.ProfileException exception) {
                if (exception.getMessage().contains("private-value")) fail("Error leaked profile data");
            }
        }
    }

    @Test
    public void preservesMihomoYamlForCoreValidation() throws Exception {
        DebugProfileFetcher.rejectUnsupportedFormat("proxies:\n  - name: Test\n    type: direct\n");
    }

    @Test
    public void decodesBase64ClashYamlSubscriptions() throws Exception {
        String profile = "proxies:\n  - name: Test\n    type: direct\n";
        String encoded = Base64.getEncoder().encodeToString(profile.getBytes(StandardCharsets.UTF_8));
        String wrapped = encoded.substring(0, encoded.length() / 2) + "\n" + encoded.substring(encoded.length() / 2);

        assertEquals(profile.trim(), DebugProfileFetcher.normalizeProfile(wrapped));
        assertEquals(profile.trim(), DebugProfileFetcher.normalizeProfile(
                Base64.getUrlEncoder().withoutPadding().encodeToString(profile.getBytes(StandardCharsets.UTF_8))));
    }

    @Test
    public void rejectsBase64NodeListsWithAHelpfulMessage() throws Exception {
        String encoded = Base64.getEncoder().encodeToString("vless://private-value".getBytes(StandardCharsets.UTF_8));
        try {
            DebugProfileFetcher.normalizeProfile(encoded);
            fail("Expected non-Clash subscription to be rejected");
        } catch (DebugProfileFetcher.ProfileException exception) {
            assertEquals("订阅返回了节点链接列表，请选择 Clash/Mihomo 专用订阅二维码", exception.getMessage());
            if (exception.getMessage().contains("private-value")) fail("Error leaked subscription data");
        }
    }

    @Test
    public void resolvesHttpsRedirectsWithoutExposingSubscriptionData() throws Exception {
        URI resolved = DebugProfileFetcher.resolveRedirect(
                URI.create("https://feed.example.test/account/start"), "/clash/profile.yaml");

        assertEquals(URI.create("https://feed.example.test/clash/profile.yaml"), resolved);
    }

    @Test
    public void rejectsRedirectDowngradeAndCredentialInjection() throws Exception {
        URI source = URI.create("https://feed.example.test/start");
        for (String location : new String[] {"http://feed.example.test/profile", "https://user:pass@cdn.example.test/profile"}) {
            try {
                DebugProfileFetcher.resolveRedirect(source, location);
                fail("Expected unsafe redirect to be rejected");
            } catch (DebugProfileFetcher.ProfileException exception) {
                if (exception.getMessage().contains("user") || exception.getMessage().contains("pass")) {
                    fail("Error leaked redirect details");
                }
            }
        }
    }

    @Test
    public void rejectsMissingRedirectLocation() throws Exception {
        try {
            DebugProfileFetcher.resolveRedirect(URI.create("https://feed.example.test/start"), " ");
            fail("Expected missing redirect location to be rejected");
        } catch (DebugProfileFetcher.ProfileException exception) {
            assertEquals("订阅重定向地址无效", exception.getMessage());
        }
    }
}
