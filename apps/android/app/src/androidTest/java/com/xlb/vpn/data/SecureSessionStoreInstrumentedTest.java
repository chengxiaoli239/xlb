package com.xlb.vpn.data;

import android.content.Context;
import android.content.SharedPreferences;
import android.test.AndroidTestCase;

public final class SecureSessionStoreInstrumentedTest extends AndroidTestCase {
    private static final String PREFERENCES_NAME = "secure_client_state";

    @Override
    protected void setUp() throws Exception {
        super.setUp();
        clearStoredState();
    }

    @Override
    protected void tearDown() throws Exception {
        clearStoredState();
        super.tearDown();
    }

    public void testConsentedRecoveryStateSurvivesStoreRecreation() {
        SecureSessionStore firstStore = new SecureSessionStore(getContext());
        String secret = firstStore.anonymousRecoverySecret();
        firstStore.recordAnonymousRecoveryConsent();

        SecureSessionStore recreatedStore = new SecureSessionStore(getContext());

        assertEquals(secret, recreatedStore.anonymousRecoverySecret());
        assertTrue(recreatedStore.hasAnonymousRecoveryConsent());
    }

    public void testClearingSessionCookieDoesNotClearRecoveryConsent() {
        SecureSessionStore store = new SecureSessionStore(getContext());
        store.anonymousRecoverySecret();
        store.recordAnonymousRecoveryConsent();
        store.updateCookie("xlb_session", "session-value", false);

        store.updateCookie("xlb_session", "", true);

        SecureSessionStore recreatedStore = new SecureSessionStore(getContext());
        assertEquals("", recreatedStore.cookieHeader());
        assertTrue(recreatedStore.hasAnonymousRecoveryConsent());
    }

    public void testExplicitLogoutRetainsSecretButClosesAutomaticRecovery() {
        SecureSessionStore store = new SecureSessionStore(getContext());
        String secret = store.anonymousRecoverySecret();
        store.recordAnonymousRecoveryConsent();
        store.updateCookie("xlb_session", "session-value", false);

        store.clearSession();

        SecureSessionStore recreatedStore = new SecureSessionStore(getContext());
        assertEquals(secret, recreatedStore.anonymousRecoverySecret());
        assertFalse(recreatedStore.hasAnonymousRecoveryConsent());
        assertEquals("", recreatedStore.cookieHeader());
    }

    public void testRotatingInstallationIdRemovesPriorRecoveryCredentialAndConsent() {
        SecureSessionStore store = new SecureSessionStore(getContext());
        String oldInstallationId = store.installationId();
        String oldSecret = store.anonymousRecoverySecret();
        store.recordAnonymousRecoveryConsent();

        store.rotateInstallationId();

        SecureSessionStore recreatedStore = new SecureSessionStore(getContext());
        assertFalse(oldInstallationId.equals(recreatedStore.installationId()));
        assertFalse(recreatedStore.hasAnonymousRecoveryConsent());
        assertFalse(oldSecret.equals(recreatedStore.anonymousRecoverySecret()));
    }

    private void clearStoredState() {
        Context context = getContext();
        SharedPreferences preferences = context.getSharedPreferences(PREFERENCES_NAME, Context.MODE_PRIVATE);
        assertTrue(preferences.edit().clear().commit());
    }
}
