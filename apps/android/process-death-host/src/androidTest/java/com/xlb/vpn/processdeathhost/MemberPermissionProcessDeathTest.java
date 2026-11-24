package com.xlb.vpn.processdeathhost;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

import android.content.Context;
import android.os.SystemClock;
import android.widget.EditText;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;
import androidx.test.uiautomator.By;
import androidx.test.uiautomator.UiDevice;
import androidx.test.uiautomator.UiObject2;
import androidx.test.uiautomator.Until;

import org.junit.Test;
import org.junit.runner.RunWith;

import java.io.File;
import java.util.List;
import java.util.concurrent.TimeUnit;
import java.util.regex.Pattern;

@RunWith(AndroidJUnit4.class)
public final class MemberPermissionProcessDeathTest {
    private static final String TARGET_PACKAGE = "com.xlb.vpn.debug";
    private static final String READY_MARKER = "permission-process-death-ready";
    private static final String CONTINUE_MARKER = "permission-process-death-continue";

    @Test
    public void memberPermissionRequestRecoversAfterTargetProcessDeathAndNextLaunch() throws Exception {
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        String email = InstrumentationRegistry.getArguments().getString("member_email");
        String password = InstrumentationRegistry.getArguments().getString("member_password");
        assertNotNull("the isolated harness must provide a sandbox member email", email);
        assertNotNull("the isolated harness must provide the local sandbox password", password);
        IsolatedMemberApi api = new IsolatedMemberApi(email, password, 4175);
        File readyMarker = new File(context.getFilesDir(), READY_MARKER);
        File continueMarker = new File(context.getFilesDir(), CONTINUE_MARKER);
        readyMarker.delete();
        continueMarker.delete();

        try {
            api.start();
            UiDevice device = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation());
            device.executeShellCommand("am start -n " + TARGET_PACKAGE
                    + "/com.xlb.vpn.presentation.MainActivity");

            List<UiObject2> fields = device.wait(Until.findObjects(By.clazz(EditText.class)),
                    TimeUnit.SECONDS.toMillis(15));
            assertNotNull("member login form did not appear", fields);
            assertTrue("member login form did not expose email and password fields", fields.size() >= 2);
            fields.get(0).setText(email);
            fields.get(1).setText(password);
            UiObject2 loginButton = device.wait(Until.findObject(By.text("登录")), TimeUnit.SECONDS.toMillis(5));
            assertNotNull("member login button did not appear", loginButton);
            loginButton.click();

            assertTrue("sandbox member did not reach the connection center",
                    device.wait(Until.hasObject(By.text("连接中心")), TimeUnit.SECONDS.toMillis(30)));
            assertTrue("member device proof was not verified before VPN consent",
                    waitForAuthorization(api, 1, TimeUnit.SECONDS.toMillis(15)));
            UiObject2 connectButton = device.wait(Until.findObject(By.desc("连接")), TimeUnit.SECONDS.toMillis(15));
            assertNotNull("member connection action did not appear", connectButton);
            assertTrue("verified member tunnel grant did not enable the connection action",
                    connectButton.isEnabled());
            connectButton.click();

            Pattern allowLabel = Pattern.compile("(?i)^(allow|ok|允许)$");
            assertTrue("VPN permission confirmation did not appear",
                    device.wait(Until.hasObject(By.text(allowLabel)), TimeUnit.SECONDS.toMillis(15)));
            String previousPid = packagePid(device, TARGET_PACKAGE);
            assertNotNull("target app process was not running before consent", previousPid);
            int authorizationsBeforeProcessDeath = api.authorizationRequests();
            assertNotEquals("test controller must run outside the target app process",
                    android.os.Process.myPid(), Integer.parseInt(previousPid));
            assertTrue("could not publish process-death readiness", readyMarker.createNewFile());

            assertTrue("host did not confirm target-process termination",
                    waitForFile(continueMarker, TimeUnit.SECONDS.toMillis(60)));
            UiObject2 allowButton = device.findObject(By.text(allowLabel));
            assertNotNull("VPN permission confirmation disappeared before approval", allowButton);
            allowButton.click();

            String restoredPid = waitForDifferentPid(device, TARGET_PACKAGE, previousPid,
                    TimeUnit.SECONDS.toMillis(3));
            if (restoredPid == null) {
                device.executeShellCommand("am start -n " + TARGET_PACKAGE
                        + "/com.xlb.vpn.presentation.MainActivity");
                restoredPid = waitForDifferentPid(device, TARGET_PACKAGE, previousPid,
                        TimeUnit.SECONDS.toMillis(15));
            }
            assertNotNull("system did not recreate the target app process", restoredPid);
            assertTrue("fresh device authorization was not signed after process death; requests="
                            + api.authorizationRequests() + ", previous=" + authorizationsBeforeProcessDeath
                            + ", verified=" + api.challengeSignatureVerified()
                            + ", fixtureFailure=" + api.failure(),
                    waitForAuthorization(api, authorizationsBeforeProcessDeath + 1,
                            TimeUnit.SECONDS.toMillis(15)));
            assertTrue("isolated member API recorded a failure: " + api.failure(), api.failure() == null);
            UiObject2 homeTab = device.findObject(By.desc("首页"));
            if (homeTab != null) homeTab.click();
            for (int attempt = 0; attempt < 3 && !device.hasObject(
                    By.text("诊断编号 member_tunnel_unavailable")); attempt++) {
                int centerX = device.getDisplayWidth() / 2;
                device.swipe(centerX, device.getDisplayHeight() * 3 / 4,
                        centerX, device.getDisplayHeight() / 4, 8);
            }
            assertTrue("restored member request did not reach the closed member-service gate",
                    device.wait(Until.hasObject(By.text("诊断编号 member_tunnel_unavailable")),
                            TimeUnit.SECONDS.toMillis(20)));
        } finally {
            readyMarker.delete();
            continueMarker.delete();
            api.close();
        }
    }

    private static String packagePid(UiDevice device, String packageName) throws Exception {
        String output = device.executeShellCommand("pidof " + packageName).trim();
        if (output.isEmpty()) return null;
        String[] pids = output.split("\\s+");
        return pids.length == 1 ? pids[0] : null;
    }

    private static String waitForDifferentPid(UiDevice device, String packageName, String previousPid,
            long timeoutMillis) throws Exception {
        long deadline = SystemClock.elapsedRealtime() + timeoutMillis;
        while (SystemClock.elapsedRealtime() < deadline) {
            String currentPid = packagePid(device, packageName);
            if (currentPid != null && !previousPid.equals(currentPid)) return currentPid;
            SystemClock.sleep(100);
        }
        return null;
    }

    private static boolean waitForFile(File file, long timeoutMillis) {
        long deadline = SystemClock.elapsedRealtime() + timeoutMillis;
        while (!file.exists() && SystemClock.elapsedRealtime() < deadline) {
            SystemClock.sleep(50);
        }
        return file.exists();
    }

    private static boolean waitForAuthorization(IsolatedMemberApi api, int expected, long timeoutMillis)
            throws InterruptedException {
        long deadline = SystemClock.elapsedRealtime() + timeoutMillis;
        while (api.authorizationRequests() < expected && SystemClock.elapsedRealtime() < deadline) {
            SystemClock.sleep(50);
        }
        return api.authorizationRequests() >= expected && api.challengeSignatureVerified();
    }
}
