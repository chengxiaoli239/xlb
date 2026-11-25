package com.xlb.vpn.data;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Build;
import android.util.Base64;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;

import org.json.JSONObject;
import org.junit.After;
import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;

import java.util.Locale;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

@RunWith(AndroidJUnit4.class)
public final class SecureSessionStoreInstrumentedTest {
    private static final String ISOLATED_API_BASE_URL = "http://127.0.0.1:4181";
    private static final String PREFERENCES_NAME = "secure_client_state";

    /** These tests clear app preferences and may only run on a disposable emulator. */

    @Before
    public void setUp() {
        assertIsolatedEmulator();
        requireDisposableAvdOptIn();
        clearStoredState();
    }

    @After
    public void tearDown() {
        clearStoredState();
    }

    @Test
    public void testConsentedRecoveryStateSurvivesStoreRecreation() {
        SecureSessionStore firstStore = new SecureSessionStore(targetContext());
        String secret = firstStore.anonymousRecoverySecret();
        firstStore.recordAnonymousRecoveryConsent();

        SecureSessionStore recreatedStore = new SecureSessionStore(targetContext());

        assertEquals(secret, recreatedStore.anonymousRecoverySecret());
        assertTrue(recreatedStore.hasAnonymousRecoveryConsent());
    }

    @Test
    public void testClearingSessionCookieDoesNotClearRecoveryConsent() {
        SecureSessionStore store = new SecureSessionStore(targetContext());
        store.anonymousRecoverySecret();
        store.recordAnonymousRecoveryConsent();
        store.updateCookie("xlb_session", "session-value", false);

        store.updateCookie("xlb_session", "", true);

        SecureSessionStore recreatedStore = new SecureSessionStore(targetContext());
        assertEquals("", recreatedStore.cookieHeader());
        assertTrue(recreatedStore.hasAnonymousRecoveryConsent());
    }

    @Test
    public void testExplicitLogoutRetainsSecretButClosesAutomaticRecovery() {
        SecureSessionStore store = new SecureSessionStore(targetContext());
        String secret = store.anonymousRecoverySecret();
        store.recordAnonymousRecoveryConsent();
        store.updateCookie("xlb_session", "session-value", false);

        store.clearSession();

        SecureSessionStore recreatedStore = new SecureSessionStore(targetContext());
        assertEquals(secret, recreatedStore.anonymousRecoverySecret());
        assertFalse(recreatedStore.hasAnonymousRecoveryConsent());
        assertEquals("", recreatedStore.cookieHeader());
    }

    @Test
    public void testRotatingInstallationIdRemovesPriorRecoveryCredentialAndConsent() {
        SecureSessionStore store = new SecureSessionStore(targetContext());
        String oldInstallationId = store.installationId();
        String oldSecret = store.anonymousRecoverySecret();
        store.recordAnonymousRecoveryConsent();

        store.rotateInstallationId();

        SecureSessionStore recreatedStore = new SecureSessionStore(targetContext());
        assertFalse(oldInstallationId.equals(recreatedStore.installationId()));
        assertFalse(recreatedStore.hasAnonymousRecoveryConsent());
        assertFalse(oldSecret.equals(recreatedStore.anonymousRecoverySecret()));
    }

    @Test
    public void testRecoverySecretAndSessionCookieAreNotStoredAsPlaintext() {
        SecureSessionStore store = new SecureSessionStore(targetContext());
        String recoverySecret = store.anonymousRecoverySecret();
        String sessionCookie = "test-session-cookie-never-store-in-plaintext";
        store.recordAnonymousRecoveryConsent();
        store.updateCookie("xlb_session", sessionCookie, false);

        Map<String, ?> persistedPreferences = targetContext()
                .getSharedPreferences(PREFERENCES_NAME, Context.MODE_PRIVATE)
                .getAll();

        assertEquals(1, persistedPreferences.size());
        assertTrue(persistedPreferences.containsKey("encrypted_state"));
        Object encryptedState = persistedPreferences.get("encrypted_state");
        assertTrue(encryptedState instanceof String);
        String encodedState = (String) encryptedState;
        assertFalse(encodedState.contains(recoverySecret));
        assertFalse(encodedState.contains(sessionCookie));
        String decodedState = new String(
                Base64.decode(encodedState, Base64.NO_WRAP),
                StandardCharsets.UTF_8
        );
        assertFalse(decodedState.contains(recoverySecret));
        assertFalse(decodedState.contains(sessionCookie));
    }

    @Test
    public void testAnonymousAccountRegistrationLogoutAndExplicitRecovery() throws Exception {
        requireIsolatedApiOptIn();

        SecureSessionStore initialStore = new SecureSessionStore(targetContext());
        PlatformApi initialApi = new PlatformApi(ISOLATED_API_BASE_URL, initialStore);
        PlatformApi.MemberAccount created = initialApi.registerAnonymousAccount(true);
        assertEquals("anonymous", created.accountType);
        assertFalse(created.id.isEmpty());

        PlatformApi afterStoreRecreation = new PlatformApi(
                ISOLATED_API_BASE_URL,
                new SecureSessionStore(targetContext())
        );
        PlatformApi.MemberAccount current = afterStoreRecreation.currentAccount();
        assertEquals(created.id, current.id);

        expireServerSession(initialStore);
        assertTrue(initialStore.hasAnonymousRecoveryConsent());
        assertNull(afterStoreRecreation.currentAccount());
        assertTrue(initialStore.hasAnonymousRecoveryConsent());
        PlatformApi.MemberAccount automaticallyRestored = afterStoreRecreation.restoreAnonymousAccount();
        assertEquals(created.id, automaticallyRestored.id);

        afterStoreRecreation.logout();
        assertFalse(new SecureSessionStore(targetContext()).hasAnonymousRecoveryConsent());

        PlatformApi afterLogout = new PlatformApi(
                ISOLATED_API_BASE_URL,
                new SecureSessionStore(targetContext())
        );
        assertNull(afterLogout.restoreAnonymousAccount());

        PlatformApi.MemberAccount recovered = afterLogout.registerAnonymousAccount(true);
        assertEquals(created.id, recovered.id);

        PlatformApi afterRecoveryStoreRecreation = new PlatformApi(
                ISOLATED_API_BASE_URL,
                new SecureSessionStore(targetContext())
        );
        PlatformApi.MemberAccount restoredAfterReconsent = afterRecoveryStoreRecreation.restoreAnonymousAccount();
        assertEquals(created.id, restoredAfterReconsent.id);
    }

    private void clearStoredState() {
        assertIsolatedEmulator();
        requireDisposableAvdOptIn();
        Context context = targetContext();
        SharedPreferences preferences = context.getSharedPreferences(PREFERENCES_NAME, Context.MODE_PRIVATE);
        assertTrue(preferences.edit().clear().commit());
    }

    private void requireIsolatedApiOptIn() {
        String enabled = InstrumentationRegistry.getArguments().getString("isolated_api", "false");
        assertEquals("Set -e isolated_api true only when port 4181 serves a disposable local database", "true", enabled);
    }

    private void requireDisposableAvdOptIn() {
        String enabled = InstrumentationRegistry.getArguments().getString("disposable_avd", "false");
        assertEquals("Set -e disposable_avd true only for a disposable emulator", "true", enabled);
    }

    private void assertIsolatedEmulator() {
        String hardware = Build.HARDWARE == null ? "" : Build.HARDWARE.toLowerCase(Locale.ROOT);
        String fingerprint = Build.FINGERPRINT == null ? "" : Build.FINGERPRINT.toLowerCase(Locale.ROOT);
        boolean emulator = "ranchu".equals(hardware)
                || "goldfish".equals(hardware)
                || fingerprint.contains("sdk_gphone");
        assertTrue("This test clears app preferences and may only run on an isolated emulator", emulator);
    }

    private void expireServerSession(SecureSessionStore sessionStore) throws Exception {
        Map<String, String> cookies = parseCookies(sessionStore.cookieHeader());
        JSONObject csrfResponse;
        HttpURLConnection csrfConnection = openConnection("GET", "/api/v1/auth/csrf", cookies, null);
        try {
            int status = csrfConnection.getResponseCode();
            assertTrue("Isolated API did not issue a CSRF session", status >= 200 && status < 300);
            csrfResponse = new JSONObject(readResponse(csrfConnection));
            mergeSetCookies(cookies, csrfConnection.getHeaderFields());
        } finally {
            csrfConnection.disconnect();
        }

        JSONObject data = csrfResponse.optJSONObject("data");
        String csrfToken = data == null ? "" : data.optString("token", "");
        assertFalse("Isolated API returned no CSRF token", csrfToken.isEmpty());

        HttpURLConnection logoutConnection = openConnection("POST", "/api/v1/auth/logout", cookies, csrfToken);
        try {
            logoutConnection.setDoOutput(true);
            logoutConnection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            try (OutputStream output = logoutConnection.getOutputStream()) {
                output.write("{}".getBytes(StandardCharsets.UTF_8));
            }
            int status = logoutConnection.getResponseCode();
            assertTrue("Isolated API did not invalidate the server session", status >= 200 && status < 300);
            mergeSetCookies(cookies, logoutConnection.getHeaderFields());
        } finally {
            logoutConnection.disconnect();
        }
    }

    private HttpURLConnection openConnection(
            String method,
            String path,
            Map<String, String> cookies,
            String csrfToken
    ) throws Exception {
        HttpURLConnection connection = (HttpURLConnection) new URL(ISOLATED_API_BASE_URL + path).openConnection();
        connection.setRequestMethod(method);
        connection.setInstanceFollowRedirects(false);
        connection.setConnectTimeout(8_000);
        connection.setReadTimeout(8_000);
        connection.setRequestProperty("Accept", "application/json");
        connection.setRequestProperty("Cache-Control", "no-store");
        if (!cookies.isEmpty()) {
            connection.setRequestProperty("Cookie", formatCookies(cookies));
        }
        if (csrfToken != null) {
            connection.setRequestProperty("X-CSRF-TOKEN", csrfToken);
        }
        return connection;
    }

    private String readResponse(HttpURLConnection connection) throws Exception {
        try (java.io.InputStream input = connection.getInputStream()) {
            byte[] bytes = new byte[4096];
            StringBuilder response = new StringBuilder();
            int count;
            while ((count = input.read(bytes)) != -1) {
                response.append(new String(bytes, 0, count, StandardCharsets.UTF_8));
            }
            return response.toString();
        }
    }

    private Map<String, String> parseCookies(String cookieHeader) {
        Map<String, String> cookies = new LinkedHashMap<>();
        for (String cookie : cookieHeader.split(";")) {
            String[] pair = cookie.trim().split("=", 2);
            if (pair.length == 2 && !pair[0].isEmpty()) {
                cookies.put(pair[0], pair[1]);
            }
        }
        return cookies;
    }

    private void mergeSetCookies(Map<String, String> cookies, Map<String, List<String>> headers) {
        for (Map.Entry<String, List<String>> entry : headers.entrySet()) {
            if (entry.getKey() == null || !"Set-Cookie".equalsIgnoreCase(entry.getKey()) || entry.getValue() == null) {
                continue;
            }
            for (String header : entry.getValue()) {
                String[] parts = header.split(";", 2);
                String[] pair = parts[0].split("=", 2);
                if (pair.length != 2 || pair[0].trim().isEmpty()) {
                    continue;
                }
                String name = pair[0].trim();
                boolean deleted = parts.length > 1 && parts[1].toLowerCase(Locale.ROOT).contains("max-age=0");
                if (deleted) {
                    cookies.remove(name);
                } else {
                    cookies.put(name, pair[1].trim());
                }
            }
        }
    }

    private String formatCookies(Map<String, String> cookies) {
        StringBuilder header = new StringBuilder();
        for (Map.Entry<String, String> entry : cookies.entrySet()) {
            if (header.length() > 0) {
                header.append("; ");
            }
            header.append(entry.getKey()).append('=').append(entry.getValue());
        }
        return header.toString();
    }

    private Context targetContext() {
        return InstrumentationRegistry.getInstrumentation().getTargetContext();
    }
}
