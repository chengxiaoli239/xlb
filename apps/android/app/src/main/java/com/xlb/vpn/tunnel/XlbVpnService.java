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

import com.xlb.vpn.BuildConfig;
import com.xlb.vpn.R;
import com.xlb.vpn.data.DeviceProofCertificate;
import com.xlb.vpn.data.MemberTunnelGrant;
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

public final class XlbVpnService extends VpnService {
    public static final String ACTION_START = "com.xlb.vpn.START_TUNNEL";
    public static final String ACTION_START_MEMBER = "com.xlb.vpn.START_MEMBER_TUNNEL";
    public static final String ACTION_STOP = "com.xlb.vpn.STOP_TUNNEL";
    public static final String ACTION_STATE = "com.xlb.vpn.TUNNEL_STATE";
    public static final String EXTRA_PROFILE = "profile";
    public static final String EXTRA_ROUTE_NAME = "route_name";
    public static final String EXTRA_DEBUG_TEST_ROUTE = "debug_test_route";
    public static final String EXTRA_MEMBER_GRANT_HANDOFF_ID = "member_grant_handoff_id";
    public static final String EXTRA_STATE = "state";
    public static final String EXTRA_MESSAGE = "message";
    public static final String INTERNAL_PERMISSION = "com.xlb.vpn.permission.INTERNAL";
    public static final String STATE_CONNECTING = "connecting";
    public static final String STATE_CONNECTED = "connected";
    public static final String STATE_DISCONNECTED = "disconnected";
    public static final String STATE_ERROR = "error";
    private static volatile String currentState = STATE_DISCONNECTED;
    private static volatile String currentRouteName = "";
    private static volatile boolean currentTunnelIsMember;

    private static final String CHANNEL_ID = "xlb_vpn";
    private static final int NOTIFICATION_ID = 31;
    private static final String[] PROBE_URLS = {
            "https://www.gstatic.com/generate_204",
            "https://cp.cloudflare.com/generate_204"
    };
    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private final ExecutorService tunnelWorker = Executors.newSingleThreadExecutor();
    private final TunnelAttemptGate tunnelAttempts = new TunnelAttemptGate();
    private final Object memberSessionLock = new Object();
    private volatile boolean foregroundServiceStarted;
    private ParcelFileDescriptor tun;
    private MemberTunnelSession memberSession;

    public static String currentState() {
        return currentState;
    }

    public static String currentRouteName() {
        return currentRouteName;
    }

    public static boolean currentTunnelIsMember() {
        return currentTunnelIsMember;
    }

    public static String firstRouteName(String profile) {
        return MihomoCore.firstRouteName(profile);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent == null || ACTION_STOP.equals(intent.getAction())) {
            int attempt = tunnelAttempts.invalidate();
            MemberTunnelGrantStore.clear();
            requestMemberSessionStop();
            worker.execute(() -> stopTunnel(attempt));
            return START_NOT_STICKY;
        }
        if (ACTION_START_MEMBER.equals(intent.getAction())) {
            long handoffId = intent.getLongExtra(EXTRA_MEMBER_GRANT_HANDOFF_ID, 0);
            MemberTunnelGrant grant = MemberTunnelGrantStore.consume(handoffId);
            if (grant == null) {
                boolean tunnelInProgress = STATE_CONNECTING.equals(currentState)
                        || STATE_CONNECTED.equals(currentState);
                boolean memberCleanupPending = currentTunnelIsMember();
                if (!foregroundServiceStarted) {
                    createChannel();
                    String foregroundMessage = STATE_CONNECTED.equals(currentState) ? "已连接" :
                            STATE_CONNECTING.equals(currentState) ? "正在连接" :
                            memberCleanupPending ? "正在清理会员线路" : "连接请求已过期";
                    promoteForeground(foregroundMessage);
                }
                if (!tunnelInProgress && !memberCleanupPending
                        && !MemberTunnelGrantStore.hasPendingHandoff()) {
                    stopSelfResult(startId);
                }
                return START_NOT_STICKY;
            }
            currentRouteName = "";
            if (!MemberTunnelAdmissionPolicy.mayStartMemberTunnel()) {
                rejectStart("会员安全线路尚未接入", handoffId);
                return START_NOT_STICKY;
            }
            int attempt = tunnelAttempts.begin();
            setMemberTunnelForAttempt(attempt, true);
            createChannel();
            promoteForeground("正在连接");
            publish(STATE_CONNECTING, null);
            worker.execute(() -> startMemberTunnel(grant, attempt, handoffId));
            return START_NOT_STICKY;
        }
        if (!ACTION_START.equals(intent.getAction())) {
            stopSelf();
            return START_NOT_STICKY;
        }
        String profile = intent.getStringExtra(EXTRA_PROFILE);
        String requestedRouteName = intent.getStringExtra(EXTRA_ROUTE_NAME);
        boolean debugTestRoute = intent.getBooleanExtra(EXTRA_DEBUG_TEST_ROUTE, false);
        currentRouteName = "";
        intent.removeExtra(EXTRA_PROFILE);
        intent.removeExtra(EXTRA_ROUTE_NAME);
        intent.removeExtra(EXTRA_DEBUG_TEST_ROUTE);
        if (!MemberTunnelAdmissionPolicy.mayStart(BuildConfig.DEBUG, debugTestRoute)) {
            rejectStart("会员安全线路尚未接入");
            return START_NOT_STICKY;
        }
        if (profile == null || profile.isBlank()) {
            rejectStart("没有可用的线路配置");
            return START_NOT_STICKY;
        }
        int attempt = tunnelAttempts.begin();
        createChannel();
        promoteForeground("正在连接");
        publish(STATE_CONNECTING, null);
        worker.execute(() -> startTunnel(profile, requestedRouteName, attempt));
        return START_NOT_STICKY;
    }

    @Override
    public IBinder onBind(Intent intent) {
        return super.onBind(intent);
    }

    @Override
    public void onRevoke() {
        int attempt = tunnelAttempts.invalidate();
        MemberTunnelGrantStore.clear();
        requestMemberSessionStop();
        worker.execute(() -> stopTunnel(attempt));
        super.onRevoke();
    }

    @Override
    public void onDestroy() {
        int attempt = tunnelAttempts.invalidate();
        requestMemberSessionStop();
        worker.execute(() -> {
            if (closeMemberSession()) setMemberTunnelForAttempt(attempt, false);
            closeCore();
        });
        worker.shutdown();
        tunnelWorker.shutdownNow();
        super.onDestroy();
    }

    private void startTunnel(String profile, String requestedRouteName, int attempt) {
        if (!tunnelAttempts.isCurrent(attempt)) return;
        if (!closeMemberSession()) {
            tunnelAttempts.runIfCurrent(attempt, () -> {
                publish(STATE_ERROR, "会员线路资源尚未清理，请重试");
                stopSelf();
            });
            return;
        }
        if (!tunnelAttempts.isCurrent(attempt)) return;
        setMemberTunnelForAttempt(attempt, false);
        Future<?> operation = tunnelWorker.submit(() -> startTunnelInternal(profile, requestedRouteName, attempt));
        try {
            operation.get(20, TimeUnit.SECONDS);
            if (tunnelAttempts.isCurrent(attempt)) {
                boolean verified = TunnelVerifier.verify(this::probeVpnRoute,
                        () -> !tunnelAttempts.isCurrent(attempt), Thread::sleep, 5);
                if (verified) {
                    tunnelAttempts.runIfCurrent(attempt, () -> {
                        publish(STATE_CONNECTED, null);
                        promoteForeground("已连接");
                    });
                } else {
                    closeCore();
                    tunnelAttempts.finishIfCurrent(attempt, () -> {
                        publish(STATE_ERROR, "线路连接或网络验证失败，请重试");
                        stopSelf();
                    });
                }
            } else {
                closeCore();
            }
        } catch (TimeoutException exception) {
            operation.cancel(true);
            tunnelAttempts.finishIfCurrent(attempt, () -> {
                publish(STATE_ERROR, "VPN 初始化超时，请检查订阅规则或网络");
                stopSelf();
            });
        } catch (ExecutionException | InterruptedException exception) {
            if (exception instanceof InterruptedException) Thread.currentThread().interrupt();
            if (!tunnelAttempts.isCurrent(attempt)) {
                closeCore();
                currentRouteName = "";
                return;
            }
            tunnelAttempts.finishIfCurrent(attempt, () -> {
                publish(STATE_ERROR, safeMessage(exception.getCause() == null ? exception : exception.getCause()));
                stopSelf();
            });
        }
    }

    private void startMemberTunnel(MemberTunnelGrant grant, int attempt, long handoffId) {
        if (!tunnelAttempts.isCurrent(attempt)) return;
        if (!closeMemberSession()) {
            tunnelAttempts.runIfCurrent(attempt, () -> {
                publish(STATE_ERROR, "会员线路资源尚未清理，请重试");
                stopSelf();
            });
            return;
        }
        MemberTunnelSession session = new MemberTunnelSession(memberTunnelRuntime(grant, attempt));
        synchronized (memberSessionLock) {
            if (!tunnelAttempts.isCurrent(attempt)) return;
            memberSession = session;
        }
        try {
            String routeName = MihomoCore.firstRouteName(grant.profile());
            if (!tunnelAttempts.runIfCurrent(attempt, () -> currentRouteName = routeName)) {
                requestMemberSessionStop();
                closeMemberSession();
                return;
            }
        } catch (RuntimeException | UnsatisfiedLinkError exception) {
            if (!tunnelAttempts.runIfCurrent(attempt,
                    () -> rejectStart("会员线路配置不可用", handoffId))) {
                requestMemberSessionStop();
                closeMemberSession();
            }
            return;
        }
        if (!tunnelAttempts.isCurrent(attempt)) {
            requestMemberSessionStop();
            closeMemberSession();
            return;
        }
        Future<?> operation = tunnelWorker.submit(() -> {
            try {
                session.start(grant);
            } catch (Exception exception) {
                throw new IllegalStateException(exception);
            }
        });
        try {
            operation.get(20, TimeUnit.SECONDS);
            boolean published = tunnelAttempts.runIfCurrent(attempt, () -> {
                publish(STATE_CONNECTED, null);
                promoteForeground("已连接");
            });
            if (!published) closeMemberSession();
        } catch (TimeoutException exception) {
            operation.cancel(true);
            session.requestStop();
            tunnelAttempts.finishIfCurrent(attempt, () -> {
                publish(STATE_ERROR, "VPN 初始化超时，请检查线路或网络");
                stopSelf();
            });
        } catch (ExecutionException | InterruptedException exception) {
            if (exception instanceof InterruptedException) Thread.currentThread().interrupt();
            if (closeMemberSession()) setMemberTunnelForAttempt(attempt, false);
            tunnelAttempts.finishIfCurrent(attempt, () -> {
                Throwable cause = exception.getCause() == null ? exception : exception.getCause();
                publish(STATE_ERROR, safeMessage(cause));
                stopSelf();
            });
        }
    }

    private MemberTunnelSession.Runtime memberTunnelRuntime(MemberTunnelGrant grant, int attempt) {
        return new MemberTunnelSession.Runtime() {
            private DeviceProofCertificate certificate;
            private DeviceProofCertificate.BridgeCredentials credentials;

            @Override
            public MemberTunnelSession.ProofHandle createDeviceCertificate(MemberTunnelGrant authorizedGrant)
                    throws Exception {
                initializeCore();
                certificate = DeviceProofCertificate.create(authorizedGrant.deviceProof());
                return certificate::close;
            }

            @Override
            public AutoCloseable startBridge(MemberTunnelSession.ProofHandle ignored,
                    MemberTunnelGrant authorizedGrant) throws Exception {
                if (certificate == null) throw new IllegalStateException("device proof certificate is unavailable");
                credentials = certificate.startProofBridge(
                        authorizedGrant.bridgeEndpoint(), authorizedGrant.trustedServerCaPem());
                return credentials::clear;
            }

            @Override
            public void loadMemberProfile(MemberTunnelGrant authorizedGrant, AutoCloseable ignored) {
                if (credentials == null) throw new IllegalStateException("proof bridge credentials are unavailable");
                MihomoCore.loadMemberProfile(authorizedGrant.profile(),
                        credentials.usernameForProfile(), credentials.passwordForProfile());
            }

            @Override
            public void establishTun() throws Exception {
                XlbVpnService.this.establishTun();
            }

            @Override
            public void verifyTunnel() throws Exception {
                if (!TunnelVerifier.verify(XlbVpnService.this::probeVpnRoute,
                        () -> !tunnelAttempts.isCurrent(attempt), Thread::sleep, 5)) {
                    throw new IOException("线路连接或网络验证失败");
                }
            }

            @Override
            public void stopTunAndCore() {
                closeCore();
            }

            @Override
            public void stopBridge() {
                if (certificate != null) certificate.stopProofBridge();
            }
        };
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

    private void startTunnelInternal(String profile, String requestedRouteName, int attempt) {
        try {
            ensureCurrentTunnelAttempt(attempt);
            closeCore();
            ensureCurrentTunnelAttempt(attempt);
            initializeCore();
            ensureCurrentTunnelAttempt(attempt);
            MihomoCore.load(profile);
            ensureCurrentTunnelAttempt(attempt);
            if (!tunnelAttempts.runIfCurrent(attempt, () -> {
                currentRouteName = requestedRouteName == null ? "" : requestedRouteName;
                publish(STATE_CONNECTING, null);
            })) {
                throw new java.util.concurrent.CancellationException("tunnel startup was canceled");
            }
            ensureCurrentTunnelAttempt(attempt);
            establishTun();
        } catch (Exception | UnsatisfiedLinkError exception) {
            throw new IllegalStateException(safeMessage(exception), exception);
        }
    }

    private void ensureCurrentTunnelAttempt(int attempt) {
        if (!tunnelAttempts.isCurrent(attempt)) {
            throw new java.util.concurrent.CancellationException("tunnel startup was canceled");
        }
    }

    private void initializeCore() throws IOException {
        File home = new File(getFilesDir(), "mihomo");
        if (!home.exists() && !home.mkdirs()) {
            throw new IOException("无法创建内核工作目录");
        }
        MihomoCore.initialize(home, this::protect);
    }

    private void establishTun() {
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

    private void stopTunnel(int attempt) {
        if (!closeMemberSession()) {
            closeCore();
            currentRouteName = "";
            tunnelAttempts.runIfCurrent(attempt, () -> {
                publish(STATE_ERROR, "VPN 资源清理尚未完成，请重试");
                stopSelf();
            });
            return;
        }
        closeCore();
        currentRouteName = "";
        tunnelAttempts.runIfCurrent(attempt, () -> {
            currentTunnelIsMember = false;
            publish(STATE_DISCONNECTED, null);
            stopSelf();
        });
    }

    private boolean closeMemberSession() {
        MemberTunnelSession active;
        synchronized (memberSessionLock) {
            active = memberSession;
        }
        if (active == null) return true;
        try {
            active.close();
            synchronized (memberSessionLock) {
                if (memberSession == active) memberSession = null;
            }
            return true;
        } catch (Exception ignored) {
            // Keep the session reference so a later stop can retry any failed resource cleanup.
            return false;
        }
    }

    private void requestMemberSessionStop() {
        synchronized (memberSessionLock) {
            if (memberSession != null) memberSession.requestStop();
        }
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

    private void rejectStart(String message) {
        rejectStart(message, 0);
    }

    private void rejectStart(String message, long memberGrantHandoffId) {
        int attempt = tunnelAttempts.invalidate();
        if (memberGrantHandoffId > 0) {
            MemberTunnelGrantStore.clear(memberGrantHandoffId);
        } else {
            MemberTunnelGrantStore.clear();
        }
        requestMemberSessionStop();
        createChannel();
        promoteForeground("连接不可用");
        worker.execute(() -> {
            if (!closeMemberSession()) {
                closeCore();
                currentRouteName = "";
                tunnelAttempts.runIfCurrent(attempt, () -> {
                    publish(STATE_ERROR, "VPN 资源清理尚未完成，请重试");
                    removeForeground();
                    stopSelf();
                });
                return;
            }
            closeCore();
            currentRouteName = "";
            tunnelAttempts.runIfCurrent(attempt, () -> {
                currentTunnelIsMember = false;
                publish(STATE_ERROR, message);
                removeForeground();
                stopSelf();
            });
        });
    }

    private void setMemberTunnelForAttempt(int attempt, boolean isMember) {
        tunnelAttempts.runIfCurrent(attempt, () -> currentTunnelIsMember = isMember);
    }

    private void promoteForeground(String message) {
        startForeground(NOTIFICATION_ID, notification(message));
        foregroundServiceStarted = true;
    }

    private void removeForeground() {
        stopForeground(STOP_FOREGROUND_REMOVE);
        foregroundServiceStarted = false;
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
