package com.xlb.vpn.tunnel;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

import android.content.BroadcastReceiver;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.os.Build;
import android.os.SystemClock;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;

import com.xlb.vpn.data.MemberTunnelGrant;
import com.xlb.vpn.data.MemberTunnelGrantAvdFixture;

import org.junit.Test;
import org.junit.runner.RunWith;

import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicReference;

@RunWith(AndroidJUnit4.class)
public final class XlbVpnServiceAdmissionInstrumentedTest {
    @Test
    public void verifiedMemberGrantIsConsumedButNeverStartsTunnelWhileProductionGateIsClosed() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        String installationId = MemberTunnelGrantAvdFixture.newInstallationId();
        String publicKey = MemberTunnelGrantAvdFixture.publicKey(installationId);
        MemberTunnelGrant grant = MemberTunnelGrantAvdFixture.createGrant(installationId, publicKey,
                "https://node.example.invalid:9443", MemberTunnelGrantAvdFixture.trustedSystemCaPem());
        CountDownLatch rejected = new CountDownLatch(1);
        AtomicReference<String> message = new AtomicReference<>();
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                if (XlbVpnService.STATE_ERROR.equals(intent.getStringExtra(XlbVpnService.EXTRA_STATE))) {
                    message.set(intent.getStringExtra(XlbVpnService.EXTRA_MESSAGE));
                    rejected.countDown();
                }
            }
        };
        registerReceiver(context, receiver, new IntentFilter(XlbVpnService.ACTION_STATE));
        MemberTunnelGrantStore.clear();
        long staleHandoffId = MemberTunnelGrantStore.publish(grant);
        long handoffId = MemberTunnelGrantStore.publish(grant);
        Intent staleStart = new Intent(context, XlbVpnService.class)
                .setAction(XlbVpnService.ACTION_START_MEMBER)
                .putExtra(XlbVpnService.EXTRA_MEMBER_GRANT_HANDOFF_ID, staleHandoffId);
        Intent start = new Intent(context, XlbVpnService.class)
                .setAction(XlbVpnService.ACTION_START_MEMBER)
                .putExtra(XlbVpnService.EXTRA_MEMBER_GRANT_HANDOFF_ID, handoffId);

        try {
            ComponentName staleStarted = context.startService(staleStart);
            assertNotNull("VPN service did not accept the stale grant start", staleStarted);
            ComponentName started = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                    ? context.startForegroundService(start)
                    : context.startService(start);
            assertNotNull("VPN service did not start", started);
            assertTrue("member service action was not rejected", rejected.await(5, TimeUnit.SECONDS));
            assertEquals("会员安全线路尚未接入", message.get());
            assertNull(MemberTunnelGrantStore.consume(handoffId));
            assertFalse(XlbVpnService.currentTunnelIsMember());
            assertEquals("", XlbVpnService.currentRouteName());
        } finally {
            MemberTunnelGrantStore.clear();
            context.unregisterReceiver(receiver);
            context.stopService(start);
            MemberTunnelGrantAvdFixture.deleteInstallationKey(installationId);
        }
    }

    @Test
    public void memberProfileIsRejectedBeforeTunnelStartupWhenBridgeIsDisabled() throws Exception {
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        CountDownLatch rejected = new CountDownLatch(1);
        AtomicReference<String> state = new AtomicReference<>();
        AtomicReference<String> message = new AtomicReference<>();
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                String receivedState = intent.getStringExtra(XlbVpnService.EXTRA_STATE);
                if (XlbVpnService.STATE_ERROR.equals(receivedState)) {
                    state.set(receivedState);
                    message.set(intent.getStringExtra(XlbVpnService.EXTRA_MESSAGE));
                    rejected.countDown();
                }
            }
        };
        IntentFilter filter = new IntentFilter(XlbVpnService.ACTION_STATE);
        registerReceiver(context, receiver, filter);

        Intent start = new Intent(context, XlbVpnService.class)
                .setAction(XlbVpnService.ACTION_START)
                .putExtra(XlbVpnService.EXTRA_PROFILE, "proxies: []\nproxy-groups: []\nrules: []\n")
                .putExtra(XlbVpnService.EXTRA_ROUTE_NAME, "member-route")
                .putExtra(XlbVpnService.EXTRA_DEBUG_TEST_ROUTE, false);

        try {
            ComponentName started = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                    ? context.startForegroundService(start)
                    : context.startService(start);
            assertNotNull("VPN service did not start", started);
            assertTrue("VPN service did not report the member profile rejection",
                    rejected.await(5, TimeUnit.SECONDS));
            assertEquals(XlbVpnService.STATE_ERROR, state.get());
            assertEquals("会员安全线路尚未接入", message.get());
            assertEquals("", XlbVpnService.currentRouteName());
        } finally {
            context.unregisterReceiver(receiver);
            context.stopService(start);
        }
    }

    @Test
    public void stopInvalidatesQueuedDebugTunnelStartWithoutPublishingConnected() throws Exception {
        requireDisposableEmulator();
        Context context = InstrumentationRegistry.getInstrumentation().getTargetContext();
        CountDownLatch disconnected = new CountDownLatch(1);
        AtomicReference<CountDownLatch> expectedDisconnect = new AtomicReference<>(disconnected);
        AtomicInteger connectedStates = new AtomicInteger();
        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context receiverContext, Intent intent) {
                String state = intent.getStringExtra(XlbVpnService.EXTRA_STATE);
                if (XlbVpnService.STATE_CONNECTED.equals(state)) {
                    connectedStates.incrementAndGet();
                } else if (XlbVpnService.STATE_DISCONNECTED.equals(state)) {
                    expectedDisconnect.get().countDown();
                }
            }
        };
        registerReceiver(context, receiver, new IntentFilter(XlbVpnService.ACTION_STATE));
        Intent stop = new Intent(context, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP);
        Intent start = new Intent(context, XlbVpnService.class)
                .setAction(XlbVpnService.ACTION_START)
                .putExtra(XlbVpnService.EXTRA_PROFILE, MemberTunnelGrantAvdFixture.PROFILE)
                .putExtra(XlbVpnService.EXTRA_ROUTE_NAME, "disposable-race-test")
                .putExtra(XlbVpnService.EXTRA_DEBUG_TEST_ROUTE, true);

        try {
            context.startService(stop);
            assertTrue("VPN service did not reach the initial stopped state",
                    disconnected.await(5, TimeUnit.SECONDS));
            CountDownLatch stoppedAfterRace = new CountDownLatch(1);
            expectedDisconnect.set(stoppedAfterRace);

            ComponentName started = context.startForegroundService(start);
            assertNotNull("VPN service did not accept the disposable debug start", started);
            ComponentName stopped = context.startService(stop);
            assertNotNull("VPN service did not accept the stop after queued start", stopped);

            assertTrue("VPN service did not finish the stop after queued start",
                    stoppedAfterRace.await(10, TimeUnit.SECONDS));
            assertEquals(XlbVpnService.STATE_DISCONNECTED, XlbVpnService.currentState());
            assertEquals("stale start reported a connected tunnel", 0, connectedStates.get());
            assertFalse("debug start left service marked as a member tunnel",
                    XlbVpnService.currentTunnelIsMember());
        } finally {
            context.unregisterReceiver(receiver);
            context.stopService(start);
            context.stopService(stop);
        }
    }

    private static void registerReceiver(Context context, BroadcastReceiver receiver, IntentFilter filter) {
        if (Build.VERSION.SDK_INT >= 33) {
            context.registerReceiver(receiver, filter, XlbVpnService.INTERNAL_PERMISSION, null,
                    Context.RECEIVER_NOT_EXPORTED);
            return;
        }
        context.registerReceiver(receiver, filter, XlbVpnService.INTERNAL_PERMISSION, null);
    }

    private static void requireDisposableEmulator() {
        String hardware = Build.HARDWARE;
        String fingerprint = Build.FINGERPRINT.toLowerCase(java.util.Locale.ROOT);
        assertTrue("Member service instrumentation requires a disposable emulator",
                "ranchu".equals(hardware) || "goldfish".equals(hardware) || fingerprint.contains("sdk_gphone"));
        assertEquals("Set disposable_avd=true only for a disposable emulator", "true",
                InstrumentationRegistry.getArguments().getString("disposable_avd", "false"));
    }
}
