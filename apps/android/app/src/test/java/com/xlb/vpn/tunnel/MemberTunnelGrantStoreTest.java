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
    public void grantCanBeConsumedOnlyOnceAndNeverNeedsIntentExtras() {
        var grant = MemberTunnelGrantTestFixture.create();
        MemberTunnelGrantStore.publish(grant);

        assertSame(grant, MemberTunnelGrantStore.consume());
        assertNull(MemberTunnelGrantStore.consume());
    }

    @Test
    public void clearRemovesUnconsumedGrant() {
        MemberTunnelGrantStore.publish(MemberTunnelGrantTestFixture.create());

        MemberTunnelGrantStore.clear();

        assertNull(MemberTunnelGrantStore.consume());
    }
}
