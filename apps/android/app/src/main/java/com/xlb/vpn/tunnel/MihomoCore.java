package com.xlb.vpn.tunnel;

import android.os.ParcelFileDescriptor;

import java.io.File;
import java.nio.charset.StandardCharsets;

final class MihomoCore {
    static {
        System.loadLibrary("xlbcore");
    }

    interface SocketProtector {
        boolean protect(int fd);
    }

    static void initialize(File home, SocketProtector protector) {
        nativeInit(home.getAbsolutePath(), protector);
    }

    static void load(String profile) {
        String failure = nativeLoad(profile.getBytes(StandardCharsets.UTF_8));
        if (failure != null) {
            throw new IllegalStateException(failure);
        }
    }

    static String firstRouteName(String profile) {
        return nativeFirstRouteName(profile.getBytes(StandardCharsets.UTF_8));
    }

    static void startTun(ParcelFileDescriptor descriptor) {
        String failure = nativeStartTun(descriptor.getFd());
        if (failure != null) {
            throw new IllegalStateException(failure);
        }
    }

    static void stop() {
        nativeStop();
    }

    private static native void nativeInit(String home, SocketProtector protector);
    private static native String nativeLoad(byte[] profile);
    private static native String nativeFirstRouteName(byte[] profile);
    private static native String nativeStartTun(int fd);
    private static native void nativeStop();
}
