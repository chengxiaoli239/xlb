package com.xlb.vpn.tunnel;

import com.xlb.vpn.data.MemberTunnelGrantTestFixture;

import org.junit.After;
import org.junit.Test;

import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertSame;

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
}
