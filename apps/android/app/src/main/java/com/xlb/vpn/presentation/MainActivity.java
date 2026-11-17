package com.xlb.vpn.presentation;

import android.app.Activity;
import android.app.AlertDialog;
import android.annotation.SuppressLint;
import android.content.Context;
import android.content.BroadcastReceiver;
import android.content.Intent;
import android.content.IntentFilter;
import android.graphics.Color;
import android.graphics.PorterDuff;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.net.VpnService;
import android.text.InputType;
import android.text.method.PasswordTransformationMethod;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.InputMethodManager;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.PopupMenu;
import android.widget.ScrollView;
import android.widget.TextView;

import com.xlb.vpn.BuildConfig;
import com.xlb.vpn.R;
import com.xlb.vpn.data.PlatformApi;
import com.xlb.vpn.data.DebugProfileFetcher;
import com.xlb.vpn.data.SecureSessionStore;
import com.xlb.vpn.data.SubscriptionQrParser;
import com.xlb.vpn.tunnel.XlbVpnService;
import com.google.zxing.integration.android.IntentIntegrator;
import com.google.zxing.integration.android.IntentResult;

import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.time.OffsetDateTime;
import java.time.format.DateTimeFormatter;
import java.util.Locale;

public final class MainActivity extends Activity {
    private int COLOR_CANVAS;
    private int COLOR_INK;
    private int COLOR_MUTED;
    private int COLOR_HINT;
    private int COLOR_TEAL;
    private int COLOR_TEAL_DARK;
    private int COLOR_TEAL_LIGHT;
    private int COLOR_ERROR;
    private int COLOR_BORDER;
    private int COLOR_NOTICE;
    private int COLOR_SURFACE;
    private int COLOR_SURFACE_ALT;
    private int COLOR_HERO;
    private static final String MESSAGE_TAG = "operation_message";
    private static final String PREF_DARK_MODE = "dark_mode";

    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private PlatformApi api;
    private LinearLayout content;
    private PlatformApi.ClientSnapshot snapshot;
    private boolean busy;
    private boolean darkMode;
    private int selectedTab;
    private String pendingProfile;
    private String tunnelState = XlbVpnService.currentState();
    private String tunnelMessage;
    private static final int VPN_PERMISSION_REQUEST = 91;
    private final BroadcastReceiver tunnelReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            tunnelState = intent.getStringExtra(XlbVpnService.EXTRA_STATE);
            tunnelMessage = intent.getStringExtra(XlbVpnService.EXTRA_MESSAGE);
            if (snapshot != null) render();
        }
    };

    @SuppressLint("UnspecifiedRegisterReceiverFlag")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        darkMode = getPreferences(MODE_PRIVATE).getBoolean(PREF_DARK_MODE, false);
        applyTheme();
        api = new PlatformApi(BuildConfig.API_BASE_URL, new SecureSessionStore(this));
        if (android.os.Build.VERSION.SDK_INT >= 33) {
            registerReceiver(tunnelReceiver, new IntentFilter(XlbVpnService.ACTION_STATE),
                    XlbVpnService.INTERNAL_PERMISSION, null, Context.RECEIVER_NOT_EXPORTED);
        } else {
            registerReceiver(tunnelReceiver, new IntentFilter(XlbVpnService.ACTION_STATE),
                    XlbVpnService.INTERNAL_PERMISSION, null);
        }
        renderLoading();
        worker.execute(() -> {
            PlatformApi.ClientSnapshot result = null;
            try {
                PlatformApi.MemberAccount account = api.currentAccount();
                if (account != null) {
                    result = api.synchronize(account);
                }
            } catch (PlatformApi.PlatformApiException ignored) {
                // A stale or absent session is rendered as the login screen.
            }
            PlatformApi.ClientSnapshot finalResult = result;
            mainHandler.post(() -> {
                if (!isFinishing() && !isDestroyed()) {
                    snapshot = finalResult;
                    render();
                }
            });
        });
    }

    @Override
    protected void onDestroy() {
        unregisterReceiver(tunnelReceiver);
        pendingProfile = null;
        worker.shutdownNow();
        super.onDestroy();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == IntentIntegrator.REQUEST_CODE) {
            IntentResult scan = IntentIntegrator.parseActivityResult(requestCode, resultCode, data);
            if (scan != null && scan.getContents() != null) {
                try {
                    importSubscription(SubscriptionQrParser.subscriptionUrl(scan.getContents()));
                } catch (DebugProfileFetcher.ProfileException exception) {
                    showMessage(exception.getMessage(), true);
                }
            }
            return;
        }
        if (requestCode != VPN_PERMISSION_REQUEST) return;
        String profile = pendingProfile;
        pendingProfile = null;
        if (resultCode != RESULT_OK || profile == null) {
            showMessage("VPN 授权未完成", true);
            return;
        }
        startVpn(profile);
    }

    private void renderLoading() {
        setPage(false);
        TextView loading = text("正在打开迅连宝…", 16, COLOR_MUTED, false);
        loading.setGravity(Gravity.CENTER);
        loading.setPadding(0, dp(90), 0, dp(90));
        content.addView(loading);
    }

    private void render() {
        setPage(snapshot != null);
        addBrandHeader();
        if (snapshot == null) {
            renderLoginForm();
        } else {
            switch (selectedTab) {
                case 1:
                    renderRoutes();
                    break;
                case 2:
                    renderSubscription(snapshot);
                    break;
                case 3:
                    renderSettings(snapshot);
                    break;
                default:
                    renderConnectionHome(snapshot);
                    break;
            }
        }
    }

    private void applyTheme() {
        if (darkMode) {
            COLOR_CANVAS = Color.rgb(20, 29, 45);
            COLOR_INK = Color.rgb(243, 250, 247);
            COLOR_MUTED = Color.rgb(155, 174, 170);
            COLOR_HINT = Color.rgb(117, 139, 134);
            COLOR_TEAL = Color.rgb(20, 124, 102);
            COLOR_TEAL_DARK = Color.rgb(84, 201, 172);
            COLOR_TEAL_LIGHT = Color.rgb(41, 68, 63);
            COLOR_ERROR = Color.rgb(255, 151, 145);
            COLOR_BORDER = Color.rgb(52, 69, 89);
            COLOR_NOTICE = Color.rgb(38, 55, 74);
            COLOR_SURFACE = Color.rgb(32, 43, 62);
            COLOR_SURFACE_ALT = Color.rgb(38, 55, 74);
            COLOR_HERO = Color.rgb(27, 58, 54);
        } else {
            COLOR_CANVAS = Color.rgb(245, 248, 246);
            COLOR_INK = Color.rgb(24, 37, 35);
            COLOR_MUTED = Color.rgb(100, 119, 110);
            COLOR_HINT = Color.rgb(145, 159, 163);
            COLOR_TEAL = Color.rgb(20, 124, 102);
            COLOR_TEAL_DARK = Color.rgb(16, 100, 82);
            COLOR_TEAL_LIGHT = Color.rgb(225, 242, 232);
            COLOR_ERROR = Color.rgb(184, 62, 62);
            COLOR_BORDER = Color.rgb(217, 229, 221);
            COLOR_NOTICE = Color.rgb(232, 240, 235);
            COLOR_SURFACE = Color.WHITE;
            COLOR_SURFACE_ALT = Color.rgb(234, 243, 237);
            COLOR_HERO = Color.rgb(230, 243, 235);
        }
        getWindow().setStatusBarColor(darkMode ? Color.rgb(17, 26, 40) : Color.rgb(234, 241, 236));
        getWindow().setNavigationBarColor(COLOR_CANVAS);
        int systemUi = darkMode ? 0 : View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR | View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR;
        getWindow().getDecorView().setSystemUiVisibility(systemUi);
    }

    private void setPage(boolean authenticated) {
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(COLOR_CANVAS);
        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        scroll.setBackgroundColor(COLOR_CANVAS);
        content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(dp(20), dp(4), dp(20), dp(16));
        scroll.addView(content);
        root.addView(scroll, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        if (authenticated) {
            root.addView(createBottomNavigation(), new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(68)));
        }
        setContentView(root);
    }

    private void addBrandHeader() {
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        header.setPadding(0, dp(6), 0, dp(18));
        FrameLayout mark = new FrameLayout(this);
        mark.setBackground(rounded(COLOR_TEAL, dp(11), null));
        ImageView logo = image(R.drawable.xlb_light_logo, 36, null);
        mark.addView(logo, centeredParams(dp(36), dp(36)));
        header.addView(mark, new LinearLayout.LayoutParams(dp(44), dp(44)));
        TextView brand = text("迅连宝", 19, COLOR_INK, true);
        LinearLayout.LayoutParams brandParams = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        brandParams.setMarginStart(dp(11));
        header.addView(brand, brandParams);
        TextView mode = text(darkMode ? "深色" : "浅色", 11, COLOR_MUTED, true);
        mode.setGravity(Gravity.CENTER);
        mode.setMinHeight(dp(34));
        mode.setPadding(dp(10), 0, dp(10), 0);
        mode.setContentDescription("切换" + (darkMode ? "浅色" : "深色") + "主题");
        mode.setOnClickListener(view -> toggleTheme());
        header.addView(mode);
        ImageView menuButton = image(R.drawable.xlb_light_menu, 20, COLOR_MUTED);
        menuButton.setPadding(dp(7), dp(7), dp(7), dp(7));
        menuButton.setBackground(rounded(COLOR_SURFACE_ALT, dp(8), null));
        menuButton.setContentDescription("打开菜单");
        menuButton.setOnClickListener(view -> showMenu(menuButton));
        LinearLayout.LayoutParams menuParams = new LinearLayout.LayoutParams(dp(34), dp(34));
        menuParams.setMarginStart(dp(4));
        header.addView(menuButton, menuParams);
        content.addView(header);
    }

    private void renderLoginForm() {
        content.addView(text("登录账户", 27, COLOR_INK, true));
        TextView subtitle = text("登录后查看连接状态和已使用的设备。", 15, COLOR_MUTED, false);
        subtitle.setPadding(0, dp(7), 0, dp(21));
        content.addView(subtitle);

        LinearLayout card = card();
        card.addView(text("会员邮箱", 13, COLOR_INK, true));
        EditText email = input("member@xlb.local", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);
        card.addView(email, spacedParams(7, 17));
        card.addView(text("登录密码", 13, COLOR_INK, true));
        EditText password = input("输入账户密码", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        card.addView(password, spacedParams(7, 19));
        Button login = primaryButton("登录");
        login.setOnClickListener(view -> {
            String emailValue = email.getText().toString().trim();
            String passwordValue = password.getText().toString();
            if (emailValue.isEmpty() || passwordValue.isEmpty()) {
                showMessage("请输入会员邮箱和密码", true);
                return;
            }
            hideKeyboard(password);
            perform("正在登录…", false, () -> {
                PlatformApi.MemberAccount account = api.login(emailValue, passwordValue);
                return api.synchronize(account);
            });
        });
        card.addView(login);
        content.addView(card, spacedParams(0, 16));
        content.addView(notice("测试环境连接本机 PHP API。密码只用于本次登录，不会保存在手机中。"));
    }

    private void renderConnectionHome(PlatformApi.ClientSnapshot current) {
        content.addView(text("连接中心", 27, COLOR_INK, true));
        TextView subtitle = text("一键连接，保持网络稳定。", 13, COLOR_MUTED, false);
        subtitle.setPadding(0, dp(2), 0, dp(14));
        content.addView(subtitle);

        FrameLayout hero = new FrameLayout(this);
        hero.setBackground(rounded(COLOR_HERO, dp(20), null));
        hero.setClipToOutline(true);
        FrameLayout.LayoutParams haloParams = new FrameLayout.LayoutParams(dp(148), dp(148), Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        haloParams.topMargin = dp(15);
        hero.addView(image(darkMode ? R.drawable.xlb_dark_halo : R.drawable.xlb_light_halo, 148, null), haloParams);
        FrameLayout.LayoutParams ringParams = new FrameLayout.LayoutParams(dp(108), dp(108), Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        ringParams.topMargin = dp(35);
        hero.addView(image(darkMode ? R.drawable.xlb_dark_ring : R.drawable.xlb_light_ring, 108, null), ringParams);
        FrameLayout.LayoutParams coreParams = new FrameLayout.LayoutParams(dp(64), dp(64), Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        coreParams.topMargin = dp(57);
        hero.addView(image(R.drawable.xlb_light_core, 64, null), coreParams);
        FrameLayout.LayoutParams logoParams = new FrameLayout.LayoutParams(dp(48), dp(48), Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        logoParams.topMargin = dp(65);
        hero.addView(image(R.drawable.xlb_light_connection_logo, 48, null), logoParams);
        hero.setContentDescription(connectionActionLabel());
        hero.setFocusable(true);

        TextView status = text(connectionStatus(), 17, COLOR_INK, true);
        status.setGravity(Gravity.CENTER);
        FrameLayout.LayoutParams statusParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.TOP);
        statusParams.topMargin = dp(165);
        hero.addView(status, statusParams);
        TextView route = text(connectionRouteLabel(), 12, COLOR_MUTED, false);
        route.setGravity(Gravity.CENTER);
        FrameLayout.LayoutParams routeParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.TOP);
        routeParams.topMargin = dp(191);
        hero.addView(route, routeParams);
        Button connect = primaryButton(connectionActionLabel());
        connect.setTextSize(14f);
        connect.setMinHeight(dp(44));
        connect.setPadding(dp(12), 0, dp(12), 0);
        connect.setEnabled(!busy && !XlbVpnService.STATE_CONNECTING.equals(tunnelState));
        FrameLayout.LayoutParams connectParams = new FrameLayout.LayoutParams(dp(170), dp(44), Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        connectParams.topMargin = dp(235);
        hero.addView(connect, connectParams);
        connect.setOnClickListener(view -> connectionAction());
        hero.setOnClickListener(view -> connectionAction());
        content.addView(hero, spacedParams(0, 19));

        TextView routeLabel = text("CURRENT ROUTE", 10, COLOR_TEAL, true);
        routeLabel.setPadding(0, 0, 0, dp(8));
        content.addView(routeLabel);
        content.addView(routeSummaryCard(), spacedParams(0, 21));
        content.addView(subscriptionSummaryCard(current), spacedParams(0, 10));
        content.addView(subscriptionEntry(), spacedParams(0, 8));
        if (tunnelMessage != null) {
            content.addView(text(tunnelMessage, 13, COLOR_ERROR, false), spacedParams(4, 4));
        }
    }

    private void renderRoutes() {
        content.addView(text("线路", 27, COLOR_INK, true));
        TextView subtitle = text("选择适合当前网络的连接线路。", 13, COLOR_MUTED, false);
        subtitle.setPadding(0, dp(2), 0, dp(18));
        content.addView(subtitle);
        LinearLayout empty = card();
        empty.setGravity(Gravity.CENTER_HORIZONTAL);
        ImageView globe = image(R.drawable.xlb_light_globe, 36, COLOR_TEAL);
        LinearLayout.LayoutParams globeParams = new LinearLayout.LayoutParams(dp(44), dp(44));
        globeParams.bottomMargin = dp(12);
        empty.addView(globe, globeParams);
        empty.addView(text("暂无可用线路", 17, COLOR_INK, true));
        TextView hint = text("线路上线后会显示在这里。", 13, COLOR_MUTED, false);
        hint.setPadding(0, dp(6), 0, 0);
        empty.addView(hint);
        content.addView(empty, spacedParams(0, 12));
    }

    private void renderSubscription(PlatformApi.ClientSnapshot current) {
        content.addView(text("订阅", 27, COLOR_INK, true));
        TextView subtitle = text("查看套餐权益和客户端订阅状态。", 13, COLOR_MUTED, false);
        subtitle.setPadding(0, dp(2), 0, dp(18));
        content.addView(subtitle);
        content.addView(subscriptionSummaryCard(current), spacedParams(0, 12));
        LinearLayout credentials = card();
        credentials.addView(text("订阅凭证", 17, COLOR_INK, true));
        String credentialState = current.subscriptionCount > 0 ? "已准备好，可用于兼容客户端。" : "尚无可用订阅凭证。";
        credentials.addView(text(credentialState, 13, COLOR_MUTED, false), spacedParams(7, 0));
        Button importButton = secondaryButton(BuildConfig.DEBUG ? "扫码导入测试订阅" : "订阅二维码导入暂未开放");
        importButton.setEnabled(BuildConfig.DEBUG && !busy && !XlbVpnService.STATE_CONNECTED.equals(tunnelState));
        importButton.setOnClickListener(view -> startSubscriptionScan());
        credentials.addView(importButton, spacedParams(14, 0));
        content.addView(credentials, spacedParams(0, 12));
    }

    private void renderSettings(PlatformApi.ClientSnapshot current) {
        content.addView(text("设置", 27, COLOR_INK, true));
        TextView subtitle = text("账户与已登录设备。", 13, COLOR_MUTED, false);
        subtitle.setPadding(0, dp(2), 0, dp(18));
        content.addView(subtitle);
        LinearLayout account = card();
        account.addView(text(current.account.name, 17, COLOR_INK, true));
        account.addView(text(current.account.email, 13, COLOR_MUTED, false), spacedParams(5, 0));
        content.addView(account, spacedParams(0, 12));
        addDevicesCard(current);

        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        Button refresh = secondaryButton(busy ? "刷新中…" : "刷新账户");
        refresh.setEnabled(!busy);
        refresh.setOnClickListener(view -> refreshAccount(current.account));
        actions.addView(refresh, new LinearLayout.LayoutParams(0, dp(52), 1f));
        Button logout = textButton("退出登录");
        logout.setOnClickListener(view -> handleLogout());
        LinearLayout.LayoutParams logoutParams = new LinearLayout.LayoutParams(0, dp(52), 1f);
        logoutParams.setMarginStart(dp(10));
        actions.addView(logout, logoutParams);
        content.addView(actions, spacedParams(10, 0));
    }

    private LinearLayout routeSummaryCard() {
        LinearLayout route = new LinearLayout(this);
        route.setOrientation(LinearLayout.HORIZONTAL);
        route.setGravity(Gravity.CENTER_VERTICAL);
        route.setPadding(dp(14), dp(12), dp(12), dp(12));
        route.setBackground(rounded(COLOR_SURFACE, dp(14), null));
        FrameLayout iconFrame = new FrameLayout(this);
        iconFrame.setBackground(rounded(COLOR_SURFACE_ALT, dp(12), null));
        iconFrame.addView(image(R.drawable.xlb_light_globe, 30, COLOR_TEAL), centeredParams(dp(30), dp(30)));
        route.addView(iconFrame, new LinearLayout.LayoutParams(dp(44), dp(44)));
        LinearLayout info = new LinearLayout(this);
        info.setOrientation(LinearLayout.VERTICAL);
        info.setPadding(dp(13), 0, dp(4), 0);
        info.addView(text("智能线路", 14, COLOR_INK, true));
        info.addView(text(XlbVpnService.STATE_CONNECTED.equals(tunnelState) ? "VPN 隧道已建立" : "连接后显示线路详情", 11, COLOR_MUTED, false), spacedParams(4, 0));
        route.addView(info, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        ImageView dot = image(R.drawable.xlb_light_route_dot, 10, null);
        route.addView(dot, new LinearLayout.LayoutParams(dp(10), dp(10)));
        TextView state = text(XlbVpnService.STATE_CONNECTED.equals(tunnelState) ? "已连接" : "待连接", 10,
                XlbVpnService.STATE_CONNECTED.equals(tunnelState) ? COLOR_TEAL : COLOR_MUTED, true);
        LinearLayout.LayoutParams stateParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        stateParams.setMarginStart(dp(6));
        route.addView(state, stateParams);
        return route;
    }

    private LinearLayout subscriptionSummaryCard(PlatformApi.ClientSnapshot current) {
        LinearLayout summary = new LinearLayout(this);
        summary.setOrientation(LinearLayout.HORIZONTAL);
        summary.setGravity(Gravity.CENTER_VERTICAL);
        summary.setPadding(dp(14), dp(14), dp(12), dp(14));
        summary.setBackground(rounded(COLOR_SURFACE, dp(14), null));
        FrameLayout iconFrame = new FrameLayout(this);
        iconFrame.setBackground(rounded(COLOR_SURFACE_ALT, dp(10), null));
        iconFrame.addView(image(R.drawable.xlb_light_subscription, 28, COLOR_TEAL), centeredParams(dp(28), dp(28)));
        summary.addView(iconFrame, new LinearLayout.LayoutParams(dp(38), dp(38)));

        LinearLayout detail = new LinearLayout(this);
        detail.setOrientation(LinearLayout.VERTICAL);
        detail.setPadding(dp(14), 0, dp(4), 0);
        String planName = current.entitlement == null || current.entitlement.planName.isEmpty() ? "会员权益" : current.entitlement.planName;
        String title = current.entitlement == null ?
                (current.device != null && current.device.entitlementActive ? "会员权益有效" : "暂无有效套餐") :
                planName + (current.entitlement.trafficGb > 0 ? " · " + formatGb(current.entitlement.trafficGb) + " GB" : "");
        detail.addView(text(title, 14, COLOR_INK, true));
        String formattedExpiry = current.entitlement == null ? null : formatDate(current.entitlement.endsAt);
        String expiry = formattedExpiry == null ? "套餐有效期暂不可用" : "有效期至 " + formattedExpiry;
        detail.addView(text(expiry, 11, COLOR_MUTED, false), spacedParams(3, 0));
        String usage = current.entitlement == null ? "流量用量暂不可用" : formatGb(current.entitlement.dataUsedGb) + " GB 已使用";
        detail.addView(text(usage, 11, COLOR_TEAL_DARK, true), spacedParams(6, 0));
        summary.addView(detail, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        TextView manage = text("管理  ›", 11, COLOR_TEAL, true);
        manage.setContentDescription("管理套餐和订阅");
        manage.setOnClickListener(view -> selectTab(2));
        summary.addView(manage);
        summary.setOnClickListener(view -> selectTab(2));
        return summary;
    }

    private View subscriptionEntry() {
        LinearLayout entry = new LinearLayout(this);
        entry.setOrientation(LinearLayout.HORIZONTAL);
        entry.setGravity(Gravity.CENTER_VERTICAL);
        entry.setPadding(dp(12), 0, dp(12), 0);
        entry.setMinimumHeight(dp(50));
        entry.setBackground(rounded(COLOR_SURFACE_ALT, dp(10), null));
        FrameLayout iconFrame = new FrameLayout(this);
        iconFrame.setBackground(rounded(darkMode ? Color.rgb(53, 83, 106) : Color.rgb(212, 235, 221), dp(7), null));
        iconFrame.addView(image(R.drawable.xlb_light_qr, 22, COLOR_TEAL), centeredParams(dp(22), dp(22)));
        entry.addView(iconFrame, new LinearLayout.LayoutParams(dp(28), dp(28)));
        TextView label = text("扫码导入 Clash / Mihomo", 12, COLOR_INK, true);
        LinearLayout.LayoutParams labelParams = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        labelParams.setMarginStart(dp(12));
        entry.addView(label, labelParams);
        entry.addView(text("打开  ›", 11, COLOR_TEAL, true));
        entry.setContentDescription("打开订阅导入");
        entry.setOnClickListener(view -> selectTab(2));
        return entry;
    }

    private String connectionStatus() {
        if (XlbVpnService.STATE_CONNECTED.equals(tunnelState)) return "已连接";
        if (XlbVpnService.STATE_CONNECTING.equals(tunnelState)) return "正在连接";
        if (XlbVpnService.STATE_ERROR.equals(tunnelState)) return "连接未成功";
        return "未连接";
    }

    private String connectionRouteLabel() {
        if (XlbVpnService.STATE_CONNECTED.equals(tunnelState)) return BuildConfig.DEBUG ? "测试线路" : "迅连宝线路";
        if (XlbVpnService.STATE_CONNECTING.equals(tunnelState)) return "正在准备安全连接";
        return "智能线路 · 自动选择";
    }

    private String connectionActionLabel() {
        if (XlbVpnService.STATE_CONNECTED.equals(tunnelState)) return "断开连接";
        if (XlbVpnService.STATE_CONNECTING.equals(tunnelState)) return "正在连接…";
        if (XlbVpnService.STATE_ERROR.equals(tunnelState)) return "重试连接";
        return "连接";
    }

    private void connectionAction() {
        if (busy || XlbVpnService.STATE_CONNECTING.equals(tunnelState)) return;
        if (XlbVpnService.STATE_CONNECTED.equals(tunnelState)) {
            startService(new Intent(this, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP));
            return;
        }
        if (!BuildConfig.DEBUG) {
            showMessage("当前没有可用的服务线路，请稍后重试。", true);
            return;
        }
        new AlertDialog.Builder(this)
                .setTitle("连接测试线路")
                .setItems(new String[]{"扫码导入订阅", "输入测试订阅地址"}, (dialog, which) -> {
                    if (which == 0) startSubscriptionScan();
                    else showTestUrlDialog();
                })
                .setNegativeButton("取消", null)
                .show();
    }

    private void startSubscriptionScan() {
        if (!BuildConfig.DEBUG) {
            showMessage("订阅二维码导入暂未开放。", false);
            return;
        }
        new IntentIntegrator(this)
                .setDesiredBarcodeFormats(IntentIntegrator.QR_CODE)
                .setPrompt("扫描 HTTPS 订阅二维码")
                .setBeepEnabled(false)
                .setBarcodeImageEnabled(false)
                .setOrientationLocked(false)
                .initiateScan();
    }

    private void showTestUrlDialog() {
        EditText url = input("HTTPS 测试订阅地址", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        new AlertDialog.Builder(this)
                .setTitle("输入测试订阅")
                .setView(url)
                .setNegativeButton("取消", null)
                .setPositiveButton("连接", (dialog, which) -> {
                    String address = url.getText().toString().trim();
                    hideKeyboard(url);
                    if (address.isEmpty()) showMessage("请输入 HTTPS 测试订阅地址", true);
                    else importSubscription(address);
                })
                .show();
    }

    private void refreshAccount(PlatformApi.MemberAccount account) {
        perform("正在刷新…", false, () -> api.synchronize(account));
    }

    private void handleLogout() {
        startService(new Intent(this, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP));
        perform("正在退出…", true, () -> {
            api.logout();
            return null;
        });
    }

    private void toggleTheme() {
        darkMode = !darkMode;
        getPreferences(MODE_PRIVATE).edit().putBoolean(PREF_DARK_MODE, darkMode).apply();
        applyTheme();
        render();
    }

    private void showMenu(View anchor) {
        PopupMenu menu = new PopupMenu(this, anchor);
        menu.getMenu().add("设置").setOnMenuItemClickListener(item -> {
            selectTab(3);
            return true;
        });
        menu.getMenu().add(darkMode ? "切换浅色主题" : "切换深色主题").setOnMenuItemClickListener(item -> {
            toggleTheme();
            return true;
        });
        if (snapshot != null) {
            menu.getMenu().add("刷新账户").setOnMenuItemClickListener(item -> {
                refreshAccount(snapshot.account);
                return true;
            });
            menu.getMenu().add("退出登录").setOnMenuItemClickListener(item -> {
                handleLogout();
                return true;
            });
        }
        menu.show();
    }

    private void selectTab(int tab) {
        selectedTab = tab;
        render();
    }

    private LinearLayout createBottomNavigation() {
        LinearLayout navigation = new LinearLayout(this);
        navigation.setOrientation(LinearLayout.VERTICAL);
        navigation.setBackgroundColor(COLOR_CANVAS);
        View divider = new View(this);
        divider.setBackgroundColor(COLOR_BORDER);
        navigation.addView(divider, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(1)));
        LinearLayout items = new LinearLayout(this);
        items.setOrientation(LinearLayout.HORIZONTAL);
        items.setGravity(Gravity.CENTER_VERTICAL);
        String[] labels = {"首页", "线路", "订阅", "设置"};
        int[] icons = {R.drawable.xlb_light_home, R.drawable.xlb_light_route,
                R.drawable.xlb_light_subscription_tab, R.drawable.xlb_light_settings};
        for (int index = 0; index < labels.length; index++) {
            final int tab = index;
            LinearLayout item = new LinearLayout(this);
            item.setOrientation(LinearLayout.VERTICAL);
            item.setGravity(Gravity.CENTER);
            FrameLayout iconBackground = new FrameLayout(this);
            iconBackground.setBackground(rounded(index == selectedTab ? COLOR_TEAL_LIGHT : COLOR_SURFACE_ALT, dp(8), null));
            ImageView icon = image(icons[index], 24, index == selectedTab ? COLOR_TEAL : COLOR_MUTED);
            iconBackground.addView(icon, centeredParams(dp(24), dp(24)));
            item.addView(iconBackground, new LinearLayout.LayoutParams(dp(30), dp(30)));
            TextView label = text(labels[index], 11, index == selectedTab ? COLOR_TEAL : COLOR_MUTED, index == selectedTab);
            label.setGravity(Gravity.CENTER);
            LinearLayout.LayoutParams labelParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            labelParams.topMargin = dp(2);
            item.addView(label, labelParams);
            item.setContentDescription(labels[index]);
            item.setOnClickListener(view -> selectTab(tab));
            items.addView(item, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f));
        }
        navigation.addView(items, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        return navigation;
    }

    private void importSubscription(String address) {
        if (busy || !BuildConfig.DEBUG) return;
        busy = true;
        showMessage("正在获取测试配置…", false);
        worker.execute(() -> {
            String profile = null;
            String error = null;
            try {
                profile = DebugProfileFetcher.fetch(address);
            } catch (DebugProfileFetcher.ProfileException exception) {
                error = exception.getMessage();
            } catch (Exception exception) {
                error = "订阅下载失败，请检查网络和地址";
            }
            String result = profile;
            String failure = error;
            mainHandler.post(() -> {
                busy = false;
                if (isFinishing() || isDestroyed()) return;
                if (failure != null) {
                    render();
                    showMessage(failure, true);
                    return;
                }
                pendingProfile = result;
                Intent approval = VpnService.prepare(this);
                if (approval == null) {
                    pendingProfile = null;
                    startVpn(result);
                } else {
                    startActivityForResult(approval, VPN_PERMISSION_REQUEST);
                }
            });
        });
    }

    private void startVpn(String profile) {
        tunnelState = XlbVpnService.STATE_CONNECTING;
        tunnelMessage = null;
        render();
        Intent start = new Intent(this, XlbVpnService.class).setAction(XlbVpnService.ACTION_START)
                .putExtra(XlbVpnService.EXTRA_PROFILE, profile);
        try {
            startForegroundService(start);
        } catch (RuntimeException exception) {
            tunnelState = XlbVpnService.STATE_ERROR;
            tunnelMessage = "无法启动系统 VPN 服务";
            render();
        }
    }

    private void addDevicesCard(PlatformApi.ClientSnapshot current) {
        LinearLayout deviceCard = card();
        LinearLayout heading = new LinearLayout(this);
        heading.setOrientation(LinearLayout.HORIZONTAL);
        heading.setGravity(Gravity.CENTER_VERTICAL);
        heading.addView(text("我的设备", 18, COLOR_INK, true),
                new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        String quota = current.device == null ? "暂不可用" :
                current.device.entitlementActive ? current.device.registered + " / " + current.device.maximum + " 台" :
                        current.device.registered + " 台";
        heading.addView(text(quota, 15, COLOR_MUTED, true));
        deviceCard.addView(heading);

        if (current.device == null) {
            deviceCard.addView(text("设备列表暂不可用，请稍后刷新。", 14, COLOR_MUTED, false), spacedParams(12, 0));
        } else {
            boolean thisPhoneListed = false;
            if (current.device.devices.isEmpty()) {
                deviceCard.addView(text("暂无已加入的设备", 14, COLOR_MUTED, false), spacedParams(14, 0));
            }
            for (int index = 0; index < current.device.devices.size(); index++) {
                PlatformApi.RegisteredDevice device = current.device.devices.get(index);
                if (index > 0) {
                    View divider = new View(this);
                    divider.setBackgroundColor(COLOR_BORDER);
                    LinearLayout.LayoutParams line = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(1));
                    line.topMargin = dp(13);
                    deviceCard.addView(divider, line);
                }
                LinearLayout row = new LinearLayout(this);
                row.setOrientation(LinearLayout.HORIZONTAL);
                row.setGravity(Gravity.CENTER_VERTICAL);
                row.addView(text(device.name, 16, COLOR_INK, true),
                        new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
                if (device.current) {
                    thisPhoneListed = true;
                    TextView tag = text("本机", 12, COLOR_TEAL_DARK, true);
                    tag.setPadding(dp(9), dp(4), dp(9), dp(4));
                    tag.setBackground(rounded(COLOR_TEAL_LIGHT, dp(10), null));
                    row.addView(tag);
                }
                deviceCard.addView(row, spacedParams(14, 0));
                LinearLayout deviceActions = new LinearLayout(this);
                deviceActions.setOrientation(LinearLayout.HORIZONTAL);
                deviceActions.setGravity(Gravity.CENTER_VERTICAL);
                deviceActions.addView(text(platformName(device.platform), 13, COLOR_MUTED, false),
                        new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
                Button remove = textButton("移除");
                remove.setTextSize(13f);
                remove.setMinHeight(dp(36));
                remove.setEnabled(!busy);
                remove.setOnClickListener(view -> confirmDeviceRemoval(current.account, device));
                deviceActions.addView(remove);
                deviceCard.addView(deviceActions);
            }
            if (!thisPhoneListed) {
                String hint = !current.device.entitlementActive ? "需要有效套餐后才能添加设备。" : "此手机尚未加入设备列表。";
                deviceCard.addView(text(hint, 14, COLOR_MUTED, false), spacedParams(14, 0));
            }
        }
        content.addView(deviceCard, spacedParams(0, 16));
    }

    private void confirmDeviceRemoval(PlatformApi.MemberAccount account, PlatformApi.RegisteredDevice device) {
        if (busy) return;
        String detail = device.current ?
                "移除本机会断开 VPN 并退出登录。重新登录后会作为新设备加入。" :
                "移除后，这台设备将不再占用套餐名额。";
        new AlertDialog.Builder(this)
                .setTitle("移除" + device.name + "？")
                .setMessage(detail)
                .setNegativeButton("取消", null)
                .setPositiveButton("确认移除", (dialog, which) -> removeDevice(account, device))
                .show();
    }

    private void removeDevice(PlatformApi.MemberAccount account, PlatformApi.RegisteredDevice device) {
        if (busy) return;
        busy = true;
        showMessage("正在移除设备…", false);
        worker.execute(() -> {
            boolean removed = false;
            boolean localResetFailed = false;
            PlatformApi.ClientSnapshot updated = null;
            String failure = null;
            try {
                api.removeDevice(device.id);
                removed = true;
                if (device.current) {
                    mainHandler.post(() -> startService(new Intent(this, XlbVpnService.class)
                            .setAction(XlbVpnService.ACTION_STOP)));
                    try {
                        api.rotateInstallationId();
                    } catch (RuntimeException exception) {
                        localResetFailed = true;
                    }
                    try {
                        api.logout();
                    } catch (PlatformApi.PlatformApiException ignored) {
                        // logout always clears the local session, even if the server is unavailable.
                    } catch (RuntimeException exception) {
                        localResetFailed = true;
                    }
                } else {
                    updated = api.loadDevices(account);
                }
            } catch (PlatformApi.PlatformApiException exception) {
                failure = exception.getMessage();
            }
            boolean confirmedRemoved = removed;
            boolean needsReinstall = localResetFailed;
            PlatformApi.ClientSnapshot refreshed = updated;
            String error = failure;
            mainHandler.post(() -> {
                if (isFinishing() || isDestroyed()) return;
                busy = false;
                if (!confirmedRemoved) {
                    showMessage(error == null ? "设备移除失败，请重试" : error, true);
                    return;
                }
                if (device.current) {
                    snapshot = null;
                } else {
                    snapshot = refreshed == null ? new PlatformApi.ClientSnapshot(account, null, null) : refreshed;
                }
                render();
                String completion = needsReinstall ? "设备已移除，本地状态清理失败，请重装应用" :
                        refreshed == null && !device.current ? "设备已移除，列表暂未刷新" : "设备已移除";
                showMessage(completion, needsReinstall);
            });
        });
    }

    private String platformName(String platform) {
        switch (platform) {
            case "android": return "Android";
            case "ios": return "iPhone / iPad";
            case "windows": return "Windows";
            case "macos": return "macOS";
            case "linux": return "Linux";
            case "browser": return "浏览器";
            default: return "其他设备";
        }
    }

    private LinearLayout card() {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(18), dp(17), dp(18), dp(17));
        card.setBackground(rounded(COLOR_SURFACE, dp(16), null));
        card.setElevation(dp(1));
        return card;
    }

    private EditText input(String hint, int type) {
        EditText input = new EditText(this);
        input.setSingleLine(true);
        input.setInputType(type);
        if ((type & InputType.TYPE_TEXT_VARIATION_PASSWORD) != 0) {
            input.setTransformationMethod(PasswordTransformationMethod.getInstance());
        }
        input.setHint(hint);
        input.setTextSize(16f);
        input.setTextColor(COLOR_INK);
        input.setHintTextColor(COLOR_HINT);
        input.setPadding(dp(14), 0, dp(14), 0);
        input.setBackground(rounded(COLOR_SURFACE, dp(12), COLOR_BORDER));
        input.setMinHeight(dp(54));
        return input;
    }

    private Button primaryButton(String label) {
        Button button = baseButton(label, Color.WHITE, COLOR_TEAL);
        return button;
    }

    private Button secondaryButton(String label) {
        return baseButton(label, COLOR_TEAL_DARK, COLOR_TEAL_LIGHT);
    }

    private Button textButton(String label) {
        return baseButton(label, COLOR_MUTED, COLOR_SURFACE, COLOR_BORDER);
    }

    private Button baseButton(String label, int textColor, int fill) {
        return baseButton(label, textColor, fill, null);
    }

    private Button baseButton(String label, int textColor, int fill, Integer stroke) {
        Button button = new Button(this);
        button.setText(label);
        button.setTextSize(15f);
        button.setAllCaps(false);
        button.setTextColor(textColor);
        button.setBackground(rounded(fill, dp(13), stroke));
        button.setMinHeight(dp(52));
        button.setStateListAnimator(null);
        return button;
    }

    private TextView notice(String message) {
        TextView notice = text(message, 13, COLOR_MUTED, false);
        notice.setPadding(dp(14), dp(13), dp(14), dp(13));
        notice.setBackground(rounded(COLOR_NOTICE, dp(12), null));
        notice.setLineSpacing(dp(3), 1f);
        return notice;
    }

    private ImageView image(int resource, int size, Integer tint) {
        ImageView image = new ImageView(this);
        image.setImageResource(resource);
        image.setScaleType(ImageView.ScaleType.FIT_CENTER);
        image.setMaxWidth(dp(size));
        image.setMaxHeight(dp(size));
        if (tint != null) image.setColorFilter(tint, PorterDuff.Mode.SRC_IN);
        image.setContentDescription(null);
        return image;
    }

    private FrameLayout.LayoutParams centeredParams(int width, int height) {
        return new FrameLayout.LayoutParams(width, height, Gravity.CENTER);
    }

    private String formatGb(double value) {
        return String.format(Locale.US, "%.1f", Math.max(0, value));
    }

    private String formatDate(String value) {
        if (value == null || value.isEmpty()) return null;
        try {
            return OffsetDateTime.parse(value).format(DateTimeFormatter.ofPattern("yyyy年MM月dd日", Locale.CHINA));
        } catch (Exception ignored) {
            return null;
        }
    }

    private TextView text(String value, int size, int color, boolean bold) {
        TextView text = new TextView(this);
        text.setText(value);
        text.setTextSize(size);
        text.setTextColor(color);
        if (bold) {
            text.setTypeface(Typeface.create("sans-serif-medium", Typeface.NORMAL));
        }
        text.setIncludeFontPadding(true);
        return text;
    }

    private GradientDrawable rounded(int fill, int radius, Integer stroke) {
        GradientDrawable drawable = new GradientDrawable();
        drawable.setColor(fill);
        drawable.setCornerRadius(radius);
        if (stroke != null) {
            drawable.setStroke(dp(1), stroke);
        }
        return drawable;
    }

    private LinearLayout.LayoutParams spacedParams(int top, int bottom) {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        params.topMargin = dp(top);
        params.bottomMargin = dp(bottom);
        return params;
    }

    private void perform(String busyMessage, boolean clearOnFailure, ApiOperation operation) {
        if (busy) {
            return;
        }
        busy = true;
        showMessage(busyMessage, false);
        worker.execute(() -> {
            PlatformApi.ClientSnapshot result = null;
            Exception failure = null;
            try {
                result = operation.run();
            } catch (Exception exception) {
                failure = exception;
            }
            PlatformApi.ClientSnapshot finalResult = result;
            Exception finalFailure = failure;
            mainHandler.post(() -> {
                if (isFinishing() || isDestroyed()) {
                    return;
                }
                busy = false;
                if (finalResult != null) {
                    if (snapshot == null) selectedTab = 0;
                    snapshot = finalResult;
                    render();
                } else if (clearOnFailure) {
                    snapshot = null;
                    render();
                } else {
                    showMessage(finalFailure == null ? "操作失败，请重试" : finalFailure.getMessage(), true);
                }
            });
        });
    }

    private void showMessage(String message, boolean error) {
        if (content == null) {
            return;
        }
        View existing = content.findViewWithTag(MESSAGE_TAG);
        if (existing != null) {
            content.removeView(existing);
        }
        TextView messageView = text(message == null ? "操作失败，请重试" : message, 13, error ? COLOR_ERROR : COLOR_MUTED, false);
        messageView.setTag(MESSAGE_TAG);
        messageView.setPadding(0, dp(8), 0, dp(8));
        content.addView(messageView, Math.min(1, content.getChildCount()));
    }

    private void hideKeyboard(View view) {
        InputMethodManager manager = (InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
        if (manager != null) {
            manager.hideSoftInputFromWindow(view.getWindowToken(), 0);
        }
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density);
    }

    private interface ApiOperation {
        PlatformApi.ClientSnapshot run() throws Exception;
    }
}
