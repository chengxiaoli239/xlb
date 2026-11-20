package com.xlb.vpn.tunnel;

import java.io.IOException;
import java.util.function.BooleanSupplier;

final class TunnelVerifier {
    interface Probe {
        boolean check() throws IOException;
    }

    interface Waiter {
        void pause(long milliseconds) throws InterruptedException;
    }

    private TunnelVerifier() {}

    static boolean verify(Probe probe, BooleanSupplier cancelled, Waiter waiter, int attempts) {
        for (int index = 0; index < attempts; index++) {
            if (cancelled.getAsBoolean()) return false;
            try {
                if (probe.check() && !cancelled.getAsBoolean()) return true;
            } catch (IOException ignored) {
                // A failed network probe may recover while the VPN route settles.
            }
            if (index + 1 < attempts) {
                try {
                    waiter.pause(700);
                } catch (InterruptedException exception) {
                    Thread.currentThread().interrupt();
                    return false;
                }
            }
        }
        return false;
    }
}
