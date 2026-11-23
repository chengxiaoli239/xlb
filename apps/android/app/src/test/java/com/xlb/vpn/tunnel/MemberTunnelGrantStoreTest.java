package com.xlb.vpn.tunnel;

import com.xlb.vpn.data.MemberTunnelGrant;
import com.xlb.vpn.data.MemberTunnelGrantTestFixture;

import org.junit.After;
import org.junit.Test;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertSame;
import static org.junit.Assert.assertTrue;

public final class MemberTunnelGrantStoreTest {
    @After
    public void clearPendingGrant() {
        MemberTunnelGrantStore.clear();
    }

    @Test
    public void grantCanBeConsumedOnlyOnceForItsHandoffId() {
        var grant = MemberTunnelGrantTestFixture.create();
        long handoffId = MemberTunnelGrantStore.publish(grant);

        assertSame(grant, MemberTunnelGrantStore.consume(handoffId));
        assertNull(MemberTunnelGrantStore.consume(handoffId));
    }

    @Test
    public void concurrentConsumersCanClaimTheSameHandoffOnlyOnce() throws Exception {
        var grant = MemberTunnelGrantTestFixture.create();
        long handoffId = MemberTunnelGrantStore.publish(grant);
        int consumerCount = 8;
        CountDownLatch consumersReady = new CountDownLatch(consumerCount);
        CountDownLatch startConsumers = new CountDownLatch(1);
        ExecutorService executor = Executors.newFixedThreadPool(consumerCount);
        List<Future<MemberTunnelGrant>> results = new ArrayList<>();

        try {
            for (int index = 0; index < consumerCount; index++) {
                results.add(executor.submit(() -> {
                    consumersReady.countDown();
                    if (!startConsumers.await(5, TimeUnit.SECONDS)) {
                        throw new IllegalStateException("consumer start barrier timed out");
                    }
                    return MemberTunnelGrantStore.consume(handoffId);
                }));
            }

            assertTrue("consumers did not reach the start barrier", consumersReady.await(5, TimeUnit.SECONDS));
            startConsumers.countDown();

            int successfulClaims = 0;
            for (Future<MemberTunnelGrant> result : results) {
                var claimedGrant = result.get(5, TimeUnit.SECONDS);
                if (claimedGrant != null) {
                    assertSame(grant, claimedGrant);
                    successfulClaims++;
                }
            }

            assertEquals(1, successfulClaims);
            assertFalse(MemberTunnelGrantStore.hasPendingHandoff());
        } finally {
            startConsumers.countDown();
            executor.shutdownNow();
        }
    }

    @Test
    public void staleHandoffCannotConsumeOrClearReplacementGrant() {
        var staleGrant = MemberTunnelGrantTestFixture.create();
        var replacementGrant = MemberTunnelGrantTestFixture.create();
        long staleHandoffId = MemberTunnelGrantStore.publish(staleGrant);
        long replacementHandoffId = MemberTunnelGrantStore.publish(replacementGrant);

        assertNull(MemberTunnelGrantStore.consume(staleHandoffId));
        MemberTunnelGrantStore.clear(staleHandoffId);

        assertSame(replacementGrant, MemberTunnelGrantStore.consume(replacementHandoffId));
        assertNull(MemberTunnelGrantStore.consume(replacementHandoffId));
    }

    @Test
    public void clearRemovesUnconsumedGrant() {
        long handoffId = MemberTunnelGrantStore.publish(MemberTunnelGrantTestFixture.create());

        MemberTunnelGrantStore.clear();

        assertNull(MemberTunnelGrantStore.consume(handoffId));
    }

    @Test
    public void pendingHandoffReflectsSingleUseGrantLifecycle() {
        assertFalse(MemberTunnelGrantStore.hasPendingHandoff());
        var grant = MemberTunnelGrantTestFixture.create();
        long handoffId = MemberTunnelGrantStore.publish(grant);
        assertTrue(MemberTunnelGrantStore.hasPendingHandoff());

        assertSame(grant, MemberTunnelGrantStore.consume(handoffId));

        assertFalse(MemberTunnelGrantStore.hasPendingHandoff());
    }
}
