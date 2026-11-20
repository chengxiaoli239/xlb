package com.xlb.vpn.data;

import org.junit.Test;

import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.fail;

public final class SubscriptionQrParserTest {
    @Test
    public void acceptsDirectHttpsSubscriptionLinks() throws Exception {
        String address = "https://feed.example.test/c/test?token=abc123";

        assertEquals(address, SubscriptionQrParser.subscriptionUrl(address));
    }

    @Test
    public void extractsHttpsLinkFromClashInstallQr() throws Exception {
        String address = "https://feed.example.test/c/test?token=a%2Bb";
        String encoded = URLEncoder.encode(address, StandardCharsets.UTF_8.name());

        assertEquals(address, SubscriptionQrParser.subscriptionUrl("clash://install-config?url=" + encoded));
    }

    @Test
    public void rejectsPlainHttpAndUnsupportedQrSchemes() throws Exception {
        for (String value : new String[] {
                "http://feed.example.test/c/test",
                "clash://install-config?url=http%3A%2F%2Ffeed.example.test%2Fc%2Ftest",
                "unknown://install-config?url=https%3A%2F%2Ffeed.example.test%2Fc%2Ftest"
        }) {
            try {
                SubscriptionQrParser.subscriptionUrl(value);
                fail("Expected unsupported subscription QR to be rejected");
            } catch (DebugProfileFetcher.ProfileException expected) {
                // The parser returns a safe, fixed error without echoing QR contents.
            }
        }
    }
}
