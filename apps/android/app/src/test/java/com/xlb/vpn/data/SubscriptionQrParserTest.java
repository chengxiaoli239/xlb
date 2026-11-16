package com.xlb.vpn.data;

import org.junit.Test;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.fail;

public final class SubscriptionQrParserTest {
    @Test
    public void acceptsDirectHttpsAndClashInstallQr() throws Exception {
        assertEquals("https://example.com/sub?token=secret",
                SubscriptionQrParser.subscriptionUrl("https://example.com/sub?token=secret"));
        assertEquals("https://example.com/sub?token=secret",
                SubscriptionQrParser.subscriptionUrl("clash://install-config?url=https%3A%2F%2Fexample.com%2Fsub%3Ftoken%3Dsecret&name=Test"));
    }

    @Test
    public void rejectsNonHttpsAndUnrecognizedQrWithoutLeakingValue() throws Exception {
        for (String value : new String[] {"http://example.com/sub", "javascript:secret", "clash://other?url=https%3A%2F%2Fexample.com"}) {
            try {
                SubscriptionQrParser.subscriptionUrl(value);
                fail("Expected invalid QR to be rejected");
            } catch (DebugProfileFetcher.ProfileException exception) {
                if (exception.getMessage().contains(value)) fail("Error message leaked scanned content");
            }
        }
    }
}
