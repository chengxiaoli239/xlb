package com.xlb.vpn.data;

import android.content.Context;
import android.content.SharedPreferences;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;

import org.junit.After;
import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

@RunWith(AndroidJUnit4.class)
public final class SecureSessionStoreInstrumentedTest {
    private static final String PREFERENCES_NAME = "secure_client_state";

    @Before
    public void setUp() {
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

    private void clearStoredState() {
        Context context = targetContext();
        SharedPreferences preferences = context.getSharedPreferences(PREFERENCES_NAME, Context.MODE_PRIVATE);
        assertTrue(preferences.edit().clear().commit());
    }

    private Context targetContext() {
        return InstrumentationRegistry.getInstrumentation().getTargetContext();
    }
}
