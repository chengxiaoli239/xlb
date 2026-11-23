package com.xlb.vpn.presentation;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import android.content.Context;
import android.os.Build;

import androidx.test.core.app.ActivityScenario;
import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;

import org.junit.Test;
import org.junit.runner.RunWith;

import java.lang.reflect.Field;
import java.util.Locale;

@RunWith(AndroidJUnit4.class)
public final class MemberVpnPermissionRecoveryInstrumentedTest {
    @Test
    public void memberPermissionRequestSurvivesActivityRecreationWithoutPersistingGrant() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        assertTrue("could not clear disposable emulator session state",
                context.getSharedPreferences("secure_client_state", Context.MODE_PRIVATE)
                        .edit().clear().commit());

        try {
            try (ActivityScenario<MainActivity> scenario = ActivityScenario.launch(MainActivity.class)) {
                scenario.onActivity(activity -> setBoolean(activity, "pendingMemberVpnPermission", true));

                scenario.recreate();

                scenario.onActivity(activity -> {
                    assertTrue("pending member permission request was lost during recreation",
                            readBoolean(activity, "pendingMemberVpnPermission"));
                    assertFalse("process-local grant must not be restored from instance state",
                            readMemberGrant(activity));
                });
            }
        } finally {
            assertTrue("could not clear disposable emulator session state",
                    context.getSharedPreferences("secure_client_state", Context.MODE_PRIVATE)
                            .edit().clear().commit());
        }
    }

    private static void setBoolean(MainActivity activity, String fieldName, boolean value) {
        try {
            Field field = MainActivity.class.getDeclaredField(fieldName);
            field.setAccessible(true);
            field.setBoolean(activity, value);
        } catch (ReflectiveOperationException exception) {
            throw new AssertionError("Could not set the test-only Activity state", exception);
        }
    }

    private static boolean readBoolean(MainActivity activity, String fieldName) {
        try {
            Field field = MainActivity.class.getDeclaredField(fieldName);
            field.setAccessible(true);
            return field.getBoolean(activity);
        } catch (ReflectiveOperationException exception) {
            throw new AssertionError("Could not read the restored Activity state", exception);
        }
    }

    private static boolean readMemberGrant(MainActivity activity) {
        try {
            Field field = MainActivity.class.getDeclaredField("pendingMemberGrant");
            field.setAccessible(true);
            return field.get(activity) != null;
        } catch (ReflectiveOperationException exception) {
            throw new AssertionError("Could not inspect the process-local grant state", exception);
        }
    }

    private static void requireDisposableEmulator() {
        String hardware = Build.HARDWARE == null ? "" : Build.HARDWARE.toLowerCase(Locale.ROOT);
        String fingerprint = Build.FINGERPRINT == null ? "" : Build.FINGERPRINT.toLowerCase(Locale.ROOT);
        boolean emulator = "ranchu".equals(hardware)
                || "goldfish".equals(hardware)
                || fingerprint.contains("sdk_gphone");
        assertTrue("Permission recovery instrumentation requires an emulator", emulator);
        assertTrue("Set disposable_avd=true only for a disposable emulator",
                "true".equals(InstrumentationRegistry.getArguments().getString("disposable_avd", "false")));
    }
}
