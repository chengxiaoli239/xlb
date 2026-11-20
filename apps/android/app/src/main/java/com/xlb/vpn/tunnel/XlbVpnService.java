package com.xlb.vpn.tunnel;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.net.VpnService;
import android.os.Build;
import android.os.IBinder;
import android.os.ParcelFileDescriptor;

import com.xlb.vpn.R;
import com.xlb.vpn.presentation.MainActivity;

import java.io.File;
import java.io.IOException;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.TimeoutException;
import java.util.concurrent.atomic.AtomicInteger;

public final class XlbVpnService extends VpnService {
    public static final String ACTION_START = "com.xlb.vpn.START_TUNNEL";
    public static final String ACTION_STOP = "com.xlb.vpn.STOP_TUNNEL";
    public static final String ACTION_STATE = "com.xlb.vpn.TUNNEL_STATE";
    public static final String EXTRA_PROFILE = "profile";
    public static final String EXTRA_ROUTE_NAME = "route_name";
    public static final String EXTRA_STATE = "state";
    public static final String EXTRA_MESSAGE = "message";
    public static final String INTERNAL_PERMISSION = "com.xlb.vpn.permission.INTERNAL";
    public static final String STATE_CONNECTING = "connecting";
    public static final String STATE_CONNECTED = "connected";
    public static final String STATE_DISCONNECTED = "disconnected";
    public static final String STATE_ERROR = "error";
    private static volatile String currentState = STATE_DISCONNECTED;
    private static volatile String currentRouteName = "";

    private static final String CHANNEL_ID = "xlb_vpn";
    private static final int NOTIFICATION_ID = 31;
    private static final String[] PROBE_URLS = {
            "https://www.gstatic.com/generate_204",
            "https://cp.cloudflare.com/generate_204"
    };
    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private final ExecutorService tunnelWorker = Executors.newSingleThreadExecutor();
    private final AtomicInteger connectionAttempt = new AtomicInteger();
    private ParcelFileDescriptor tun;

    public static String currentState() {
        return currentState;
    }

    public static String currentRouteName() {
        return currentRouteName;
    }

    public static String firstRouteName(String profile) {
        return MihomoCore.firstRouteName(profile);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent == null || ACTION_STOP.equals(intent.getAction())) {
            connectionAttempt.incrementAndGet();
            worker.execute(this::stopTunnel);
            return START_NOT_STICKY;
        }
        if (!ACTION_START.equals(intent.getAction())) {
            stopSelf();
            return START_NOT_STICKY;
        }
        String profile = intent.getStringExtra(EXTRA_PROFILE);
        String requestedRouteName = intent.getStringExtra(EXTRA_ROUTE_NAME);
        currentRouteName = "";
        intent.removeExtra(EXTRA_PROFILE);
        intent.removeExtra(EXTRA_ROUTE_NAME);
        if (profile == null || profile.isBlank()) {
            publish(STATE_ERROR, "没有可用的线路配置");
            stopSelf();
            return START_NOT_STICKY;
        }
        createChannel();
        startForeground(NOTIFICATION_ID, notification("正在连接"));
        publish(STATE_CONNECTING, null);
        int attempt = connectionAttempt.incrementAndGet();
        worker.execute(() -> startTunnel(profile, requestedRouteName, attempt));
        return START_NOT_STICKY;
    }

    @Override
    public IBinder onBind(Intent intent) {
        return super.onBind(intent);
    }

    @Override
    public void onRevoke() {
        connectionAttempt.incrementAndGet();
        worker.execute(this::stopTunnel);
        super.onRevoke();
    }

    @Override
    public void onDestroy() {
        connectionAttempt.incrementAndGet();
        worker.execute(this::closeCore);
        worker.shutdown();
        tunnelWorker.shutdownNow();
        super.onDestroy();
    }

    private void startTunnel(String profile, String requestedRouteName, int attempt) {
        Future<?> operation = tunnelWorker.submit(() -> startTunnelInternal(profile, requestedRouteName));
        try {
            operation.get(20, TimeUnit.SECONDS);
            if (connectionAttempt.get() == attempt) {
                boolean verified = TunnelVerifier.verify(this::probeVpnRoute,
                        () -> connectionAttempt.get() != attempt, Thread::sleep, 5);
                if (connectionAttempt.get() == attempt && verified) {
                    publish(STATE_CONNECTED, null);
                    startForeground(NOTIFICATION_ID, notification("已连接"));
                } else if (connectionAttempt.compareAndSet(attempt, attempt + 1)) {
                    closeCore();
                    publish(STATE_ERROR, "线路连接或网络验证失败，请重试");
                    stopSelf();
                }
            } else {
                closeCore();
            }
        } catch (TimeoutException exception) {
            operation.cancel(true);
            if (connectionAttempt.compareAndSet(attempt, attempt + 1)) {
                publish(STATE_ERROR, "VPN 初始化超时，请检查订阅规则或网络");
                stopSelf();
            }
        } catch (ExecutionException | InterruptedException exception) {
            if (exception instanceof InterruptedException) Thread.currentThread().interrupt();
            if (connectionAttempt.compareAndSet(attempt, attempt + 1)) {
                publish(STATE_ERROR, safeMessage(exception.getCause() == null ? exception : exception.getCause()));
                stopSelf();
            }
        }
    }

    private boolean probeVpnRoute() throws IOException {
        ConnectivityManager manager = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
        Network network = manager == null ? null : manager.getActiveNetwork();
        NetworkCapabilities capabilities = network == null ? null : manager.getNetworkCapabilities(network);
        if (capabilities == null || !capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN)) {
            return false;
        }
        for (String address : PROBE_URLS) {
            HttpURLConnection connection = (HttpURLConnection) network.openConnection(new URL(address));
            connection.setInstanceFollowRedirects(false);
            connection.setConnectTimeout(2_500);
            connection.setReadTimeout(2_500);
            connection.setRequestProperty("Cache-Control", "no-store");
            try {
                if (connection.getResponseCode() == HttpURLConnection.HTTP_NO_CONTENT) return true;
            } catch (IOException ignored) {
                // Try the second independent HTTPS probe before the next verification attempt.
            } finally {
                connection.disconnect();
            }
        }
        return false;
    }

    private void startTunnelInternal(String profile, String requestedRouteName) {
        try {
            closeCore();
            File home = new File(getFilesDir(), "mihomo");
            if (!home.exists() && !home.mkdirs()) {
                throw new IOException("无法创建内核工作目录");
            }
            MihomoCore.initialize(home, this::protect);
            MihomoCore.load(profile);
            currentRouteName = requestedRouteName == null ? "" : requestedRouteName;
            publish(STATE_CONNECTING, null);
            if (VpnService.prepare(this) != null) {
                throw new IllegalStateException("系统 VPN 授权已失效");
            }
            tun = new Builder()
                    .setSession("迅连宝")
                    .setMtu(9000)
                    .addAddress("172.19.0.1", 30)
                    .addAddress("fdfe:dcba:9876::1", 126)
                    .addRoute("0.0.0.0", 0)
                    .addRoute("::", 0)
                    .addDnsServer("172.19.0.2")
                    .addDnsServer("fdfe:dcba:9876::2")
                    .establish();
            if (tun == null) {
                throw new IllegalStateException("系统未能建立 VPN 通道");
            }
            MihomoCore.startTun(tun);
        } catch (Exception | UnsatisfiedLinkError exception) {
            throw new IllegalStateException(safeMessage(exception), exception);
        }
    }

    static String safeMessage(Throwable exception) {
        String message = exception.getMessage();
        if (message == null) return "VPN 初始化失败";
        switch (message) {
            case "invalid profile": return "测试配置格式不正确";
            case "profile has no proxies": return "测试配置没有可用节点";
            case "profile has no inline proxies": return "测试订阅没有内联节点，暂不支持远程节点列表";
            case "proxy provider has no proxies": return "订阅没有返回可用线路，请确认订阅有效后重试";
            case "profile has no usable inline proxy": return "测试订阅的首个节点缺少名称或类型";
            case "core is not ready": return "VPN 内核未准备好";
            case "tunnel already running": return "VPN 隧道已经运行";
            case "tunnel descriptor unavailable": return "系统 VPN 通道不可用";
            case "tunnel startup failed": return "VPN 隧道启动失败";
            case "无法创建内核工作目录":
            case "系统 VPN 授权已失效":
            case "系统未能建立 VPN 通道": return message;
            case "测试配置的 DNS 设置暂不兼容":
            case "测试配置的代理提供者暂不兼容":
            case "测试配置的代理组暂不兼容":
            case "测试配置包含暂不支持的监听器设置":
            case "测试配置的规则或规则集暂不兼容":
            case "测试配置中的节点设置暂不兼容":
            case "测试配置格式或字段暂不兼容":
            case "测试配置的规则存在循环引用":
            case "测试配置引用的规则集提供者缺失":
            case "测试配置的规则集类型不匹配":
            case "测试配置的规则集提供者暂不兼容":
            case "测试配置引用的规则集缺失":
            case "测试配置的规则集设置暂不兼容":
            case "测试配置引用的子规则缺失":
            case "测试配置中的规则格式不受支持":
            case "测试配置中的规则引用了不存在的策略":
            case "测试配置的规则引用缺失": return message;
            default: return "VPN 初始化失败";
        }
    }

    private void stopTunnel() {
        closeCore();
        currentRouteName = "";
        publish(STATE_DISCONNECTED, null);
        stopSelf();
    }

    private void closeCore() {
        try {
            MihomoCore.stop();
        } catch (UnsatisfiedLinkError ignored) {
            // A failed native load must still close the Android descriptor.
        }
        if (tun != null) {
            try {
                tun.close();
            } catch (IOException ignored) {
            }
            tun = null;
        }
    }

    private void publish(String state, String message) {
        currentState = state;
        Intent intent = new Intent(ACTION_STATE).setPackage(getPackageName()).putExtra(EXTRA_STATE, state);
        if (message != null) intent.putExtra(EXTRA_MESSAGE, message);
        if (!currentRouteName.isEmpty()) intent.putExtra(EXTRA_ROUTE_NAME, currentRouteName);
        sendBroadcast(intent, INTERNAL_PERMISSION);
    }

    private void createChannel() {
        NotificationManager manager = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        manager.createNotificationChannel(new NotificationChannel(CHANNEL_ID, "迅连宝连接", NotificationManager.IMPORTANCE_LOW));
    }

    private Notification notification(String state) {
        PendingIntent open = PendingIntent.getActivity(this, 0, new Intent(this, MainActivity.class),
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        return new Notification.Builder(this, CHANNEL_ID)
                .setSmallIcon(R.drawable.xlb_mark)
                .setContentTitle("迅连宝")
                .setContentText(state)
                .setOngoing(true)
                .setContentIntent(open)
                .build();
    }
}
