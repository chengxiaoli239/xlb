package com.xlb.vpn.data;

import org.junit.Test;

import java.nio.charset.StandardCharsets;
import java.util.Base64;

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
}
