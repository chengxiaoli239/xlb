package com.xlb.vpn.presentation;

import android.app.Activity;
import android.app.AlertDialog;
import android.annotation.SuppressLint;
import android.content.Context;
import android.content.BroadcastReceiver;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.res.ColorStateList;
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
import android.widget.Switch;
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
    private int COLOR_TEAL_ACCENT;
    private int COLOR_TEAL_DARK;
    private int COLOR_TEAL_LIGHT;
    private int COLOR_ERROR;
    private int COLOR_BORDER;
    private int COLOR_NOTICE;
    private int COLOR_SURFACE;
    private int COLOR_SURFACE_ALT;
    private int COLOR_SUCCESS;
    private int COLOR_SUCCESS_BG;
    private int COLOR_NEUTRAL;
    private int COLOR_NEUTRAL_BG;
    private int COLOR_WARNING;
    private int COLOR_WARNING_BG;
    private int COLOR_ERROR_BG;
    private static final String MESSAGE_TAG = "operation_message";
    private static final String PREF_DARK_MODE = "dark_mode";
    private static final String PREF_AUTO_CONNECT_ON_START = "auto_connect_on_start";

    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private PlatformApi api;
    private LinearLayout content;
    private PlatformApi.ClientSnapshot snapshot;
    private boolean busy;
    private boolean darkMode;
    private boolean autoConnectOnStart;
    private boolean startupAutoConnectChecked;
    private int selectedTab;
    private String pendingProfile;
    private String pendingRouteName;
    private String loadedProfile;
    private boolean testRoute;
    private boolean routeSyncing;
    private String tunnelState = XlbVpnService.currentState();
    private String tunnelMessage;
    private String routeName;
    private String activeTunnelRouteName = XlbVpnService.currentRouteName();
    private static final int VPN_PERMISSION_REQUEST = 91;
    private final BroadcastReceiver tunnelReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            tunnelState = intent.getStringExtra(XlbVpnService.EXTRA_STATE);
            tunnelMessage = intent.getStringExtra(XlbVpnService.EXTRA_MESSAGE);
            activeTunnelRouteName = intent.getStringExtra(XlbVpnService.EXTRA_ROUTE_NAME);
            if (snapshot != null) render();
        }
    };

    @SuppressLint("UnspecifiedRegisterReceiverFlag")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        darkMode = getPreferences(MODE_PRIVATE).getBoolean(PREF_DARK_MODE, false);
        autoConnectOnStart = getPreferences(MODE_PRIVATE).getBoolean(PREF_AUTO_CONNECT_ON_START, false);
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
            String syncedRoute = accountRouteName(result);
            mainHandler.post(() -> {
                if (!isFinishing() && !isDestroyed()) {
                    applySyncedSnapshot(finalResult, syncedRoute);
                }
            });
        });
    }

    @Override
    protected void onDestroy() {
        unregisterReceiver(tunnelReceiver);
        pendingProfile = null;
        loadedProfile = null;
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
                    tunnelMessage = exception.getMessage();
                    selectedTab = 0;
                    render();
                    showMessage(exception.getMessage(), true);
                }
            }
            return;
        }
        if (requestCode != VPN_PERMISSION_REQUEST) return;
        String profile = pendingProfile;
        String selectedRoute = pendingRouteName;
        pendingProfile = null;
        pendingRouteName = null;
        if (resultCode != RESULT_OK || profile == null) {
            showMessage("VPN 授权未完成", true);
            return;
        }
        startVpn(profile, selectedRoute);
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
            COLOR_CANVAS = Color.rgb(19, 27, 25);
            COLOR_INK = Color.rgb(243, 248, 245);
            COLOR_MUTED = Color.rgb(165, 180, 174);
            COLOR_HINT = Color.rgb(124, 140, 134);
            COLOR_TEAL = Color.rgb(20, 124, 102);
            COLOR_TEAL_ACCENT = Color.rgb(89, 208, 173);
            COLOR_TEAL_DARK = Color.rgb(92, 213, 163);
            COLOR_TEAL_LIGHT = Color.rgb(25, 59, 50);
            COLOR_ERROR = Color.rgb(255, 151, 145);
            COLOR_BORDER = Color.rgb(57, 72, 66);
            COLOR_NOTICE = Color.rgb(39, 56, 51);
            COLOR_SURFACE = Color.rgb(30, 41, 37);
            COLOR_SURFACE_ALT = Color.rgb(39, 56, 51);
            COLOR_SUCCESS = Color.rgb(92, 213, 163);
            COLOR_SUCCESS_BG = Color.rgb(25, 59, 50);
            COLOR_NEUTRAL = Color.rgb(185, 195, 191);
            COLOR_NEUTRAL_BG = Color.rgb(48, 61, 58);
            COLOR_WARNING = Color.rgb(246, 199, 106);
            COLOR_WARNING_BG = Color.rgb(68, 56, 31);
            COLOR_ERROR_BG = Color.rgb(73, 41, 41);
        } else {
            COLOR_CANVAS = Color.rgb(245, 248, 246);
            COLOR_INK = Color.rgb(24, 37, 35);
            COLOR_MUTED = Color.rgb(116, 130, 125);
            COLOR_HINT = Color.rgb(145, 160, 154);
            COLOR_TEAL = Color.rgb(20, 124, 102);
            COLOR_TEAL_ACCENT = COLOR_TEAL;
            COLOR_TEAL_DARK = Color.rgb(16, 100, 82);
            COLOR_TEAL_LIGHT = Color.rgb(225, 242, 232);
            COLOR_ERROR = Color.rgb(184, 62, 62);
            COLOR_BORDER = Color.rgb(217, 229, 221);
            COLOR_NOTICE = Color.rgb(232, 240, 235);
            COLOR_SURFACE = Color.WHITE;
            COLOR_SURFACE_ALT = Color.rgb(234, 243, 237);
            COLOR_SUCCESS = Color.rgb(19, 122, 82);
            COLOR_SUCCESS_BG = Color.rgb(229, 245, 236);
            COLOR_NEUTRAL = Color.rgb(98, 112, 107);
            COLOR_NEUTRAL_BG = Color.rgb(237, 241, 239);
            COLOR_WARNING = Color.rgb(139, 91, 0);
            COLOR_WARNING_BG = Color.rgb(255, 245, 220);
            COLOR_ERROR_BG = Color.rgb(253, 235, 234);
        }
        getWindow().setStatusBarColor(darkMode ? Color.rgb(16, 23, 28) : Color.rgb(234, 241, 236));
        getWindow().setNavigationBarColor(COLOR_CANVAS);
        int systemUi = darkMode ? 0 : View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR | View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR;
        getWindow().getDecorView().setSystemUiVisibility(systemUi);
    }

    private void setPage(boolean authenticated) {
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(COLOR_CANVAS);
        root.addView(addBrandHeader(), new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(56)));
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

    private LinearLayout addBrandHeader() {
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        header.setPadding(dp(20), dp(4), dp(20), dp(4));
        header.setElevation(dp(2));
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
        return header;
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
        if (BuildConfig.DEBUG) {
            Button anonymous = secondaryButton("创建匿名账号");
            anonymous.setOnClickListener(view -> showAnonymousAccountConsent());
            card.addView(anonymous, spacedParams(10, 0));
        }
        content.addView(card, spacedParams(0, 16));
        content.addView(notice("测试环境连接本机 PHP API。密码只用于本次登录，不会保存在手机中。"));
    }

    private void showAnonymousAccountConsent() {
        new AlertDialog.Builder(this)
                .setTitle("创建匿名账号")
                .setMessage("账号将绑定当前应用安装。恢复密钥由 Android Keystore 加密保存在本机，服务端只保存摘要。当前开发版本不会自动开通会员权益，邮箱/手机号升级和跨端迁移尚未开放；卸载应用或清除数据后可能无法恢复。是否继续？")
                .setNegativeButton("取消", null)
                .setPositiveButton("同意并创建", (dialog, which) -> perform("正在创建匿名账号…", false, () -> {
                    PlatformApi.MemberAccount account = api.registerAnonymousAccount(true);
                    return api.synchronize(account);
                }))
                .show();
    }

    private void renderConnectionHome(PlatformApi.ClientSnapshot current) {
        content.addView(text("连接中心", 27, COLOR_INK, true));
        TextView subtitle = text("一键连接，保持网络稳定。", 13, COLOR_MUTED, false);
        subtitle.setPadding(0, dp(2), 0, dp(14));
        content.addView(subtitle);

        FrameLayout hero = new FrameLayout(this);
        hero.setBackground(rounded(connectionSurfaceColor(), dp(20), null));
        hero.setClipToOutline(true);
        FrameLayout.LayoutParams haloParams = new FrameLayout.LayoutParams(dp(118), dp(118), Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        haloParams.topMargin = dp(5);
        hero.addView(stateCircle(connectionStateLayer(0.14f), 59), haloParams);
        FrameLayout.LayoutParams ringParams = new FrameLayout.LayoutParams(dp(90), dp(90), Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        ringParams.topMargin = dp(19);
        hero.addView(stateCircle(connectionStateLayer(0.28f), 45), ringParams);
        FrameLayout.LayoutParams coreParams = new FrameLayout.LayoutParams(dp(56), dp(56), Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        coreParams.topMargin = dp(36);
        hero.addView(stateCircle(connectionStateLayer(0.52f), 28), coreParams);
        FrameLayout.LayoutParams logoParams = new FrameLayout.LayoutParams(dp(40), dp(40), Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        logoParams.topMargin = dp(44);
        hero.addView(image(R.drawable.xlb_light_connection_logo, 44, null), logoParams);
        hero.setContentDescription(connectionActionLabel());
        hero.setFocusable(true);

        LinearLayout status = new LinearLayout(this);
        status.setOrientation(LinearLayout.HORIZONTAL);
        status.setGravity(Gravity.CENTER);
        View indicator = new View(this);
        indicator.setBackground(rounded(connectionStatusColor(), dp(5), null));
        status.addView(indicator, new LinearLayout.LayoutParams(dp(10), dp(10)));
        TextView statusLabel = text(connectionStatus(), 17, connectionStatusColor(), true);
        LinearLayout.LayoutParams statusLabelParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        statusLabelParams.setMarginStart(dp(8));
        status.addView(statusLabel, statusLabelParams);
        FrameLayout.LayoutParams statusParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(28), Gravity.TOP);
        statusParams.topMargin = dp(106);
        hero.addView(status, statusParams);
        TextView route = text(connectionRouteLabel(), 12, COLOR_MUTED, false);
        route.setGravity(Gravity.CENTER);
        FrameLayout.LayoutParams routeParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.TOP);
        routeParams.topMargin = dp(132);
        hero.addView(route, routeParams);
        Button connect = primaryButton(connectionActionLabel());
        connect.setTextSize(14f);
        connect.setMinHeight(dp(44));
        connect.setPadding(dp(12), 0, dp(12), 0);
        connect.setEnabled(!busy && !routeSyncing && !XlbVpnService.STATE_CONNECTING.equals(tunnelState)
                && (loadedProfile != null || XlbVpnService.STATE_CONNECTED.equals(tunnelState)));
        FrameLayout.LayoutParams connectParams = new FrameLayout.LayoutParams(dp(170), dp(44), Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        connectParams.topMargin = dp(158);
        hero.addView(connect, connectParams);
        connect.setOnClickListener(view -> connectionAction());
        hero.setOnClickListener(view -> connectionAction());
        LinearLayout.LayoutParams heroParams = spacedParams(0, 19);
        heroParams.height = dp(210);
        content.addView(hero, heroParams);

        TextView routeLabel = text(routeName == null ? "线路状态" : "当前线路", 13, COLOR_TEAL_ACCENT, true);
        routeLabel.setPadding(0, 0, 0, dp(8));
        content.addView(routeLabel);
        boolean hasVisibleRoute = routeName != null || XlbVpnService.STATE_CONNECTED.equals(tunnelState)
                && activeTunnelRouteName != null && !activeTunnelRouteName.isBlank();
        content.addView(hasVisibleRoute ? routeSummaryCard() : routeUnavailableCard(current), spacedParams(0, 21));
        content.addView(subscriptionSummaryCard(current), spacedParams(0, 10));
        if (tunnelMessage != null) {
            content.addView(text(tunnelMessage, 13, COLOR_ERROR, false), spacedParams(4, 4));
        }
    }

    private void renderRoutes() {
        content.addView(text("线路", 27, COLOR_INK, true));
        TextView subtitle = text("选择适合当前网络的连接线路。", 13, COLOR_MUTED, false);
        subtitle.setPadding(0, dp(2), 0, dp(18));
        content.addView(subtitle);
        if (routeName != null && !routeName.isBlank()) {
            content.addView(text(testRoute ? "测试线路" : "账户线路", 15, COLOR_INK, true), spacedParams(0, 10));
            content.addView(routeSummaryCard(), spacedParams(0, 10));
            if (testRoute) content.addView(notice("仅用于开发联调；点击首页的连接按钮后才会启动 VPN。"));
            return;
        }
        content.addView(routeUnavailableCard(snapshot), spacedParams(0, 12));
    }

    private void renderSubscription(PlatformApi.ClientSnapshot current) {
        content.addView(text("会员", 27, COLOR_INK, true));
        TextView subtitle = text("会员套餐与线路状态分别展示。", 13, COLOR_MUTED, false);
        subtitle.setPadding(0, dp(2), 0, dp(18));
        content.addView(subtitle);
        content.addView(subscriptionSummaryCard(current), spacedParams(0, 12));
        addDevicesCard(current);
    }

    private void renderSettings(PlatformApi.ClientSnapshot current) {
        content.addView(text("设置", 27, COLOR_INK, true));
        TextView subtitle = text("账户与已登录设备。", 13, COLOR_MUTED, false);
        subtitle.setPadding(0, dp(2), 0, dp(18));
        content.addView(subtitle);

        addSettingsSectionTitle("账户", 0);
        LinearLayout account = card();
        account.addView(text(current.account.name, 17, COLOR_INK, true));
        String accountLabel = "anonymous".equals(current.account.accountType) ? "匿名账号" : current.account.email;
        account.addView(text(accountLabel, 13, COLOR_MUTED, false), spacedParams(5, 0));
        content.addView(account, spacedParams(0, 0));

        addSettingsSectionTitle("连接偏好", 18);
        content.addView(autoConnectPreferenceCard(), spacedParams(0, 0));

        addSettingsSectionTitle("设备", 18);
        addDevicesCard(current, 0);

        addSettingsSectionTitle("账户操作", 18);
        LinearLayout actions = card();
        Button refresh = secondaryButton(busy ? "刷新中…" : "刷新账户");
        refresh.setEnabled(!busy);
        refresh.setOnClickListener(view -> refreshAccount(current.account));
        actions.addView(refresh, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(48)));
        View divider = new View(this);
        divider.setBackgroundColor(COLOR_BORDER);
        actions.addView(divider, spacedParams(12, 12));
        Button logout = textButton("退出登录");
        logout.setOnClickListener(view -> handleLogout());
        actions.addView(logout, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(48)));
        content.addView(actions, spacedParams(0, 0));

        if (BuildConfig.DEBUG) addDeveloperTools();
    }

    private void addSettingsSectionTitle(String label, int topMargin) {
        TextView title = text(label, 14, COLOR_MUTED, true);
        title.setPadding(dp(2), 0, dp(2), dp(8));
        content.addView(title, spacedParams(topMargin, 0));
    }

    private LinearLayout autoConnectPreferenceCard() {
        LinearLayout preference = new LinearLayout(this);
        preference.setOrientation(LinearLayout.HORIZONTAL);
        preference.setGravity(Gravity.CENTER_VERTICAL);
        preference.setPadding(dp(18), dp(15), dp(14), dp(15));
        preference.setBackground(rounded(COLOR_SURFACE, dp(16), null));

        LinearLayout copy = new LinearLayout(this);
        copy.setOrientation(LinearLayout.VERTICAL);
        copy.addView(text("启动时自动连接", 16, COLOR_INK, true));
        copy.addView(text("下次启动时，仅在已有系统 VPN 授权且线路可用时连接。", 12,
                COLOR_MUTED, false), spacedParams(5, 0));
        TextView firstConnection = text("首次连接仍需手动点击并完成系统授权。", 12, COLOR_MUTED, false);
        firstConnection.setLineSpacing(dp(2), 1f);
        copy.addView(firstConnection, spacedParams(3, 0));
        preference.addView(copy, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        Switch toggle = new Switch(this);
        toggle.setChecked(autoConnectOnStart);
        toggle.setContentDescription("启动时自动连接");
        int checkedThumb = darkMode ? COLOR_TEAL_ACCENT : COLOR_TEAL;
        ColorStateList thumbColors = new ColorStateList(
                new int[][]{new int[]{android.R.attr.state_checked}, new int[]{}},
                new int[]{checkedThumb, COLOR_HINT});
        ColorStateList trackColors = new ColorStateList(
                new int[][]{new int[]{android.R.attr.state_checked}, new int[]{}},
                new int[]{COLOR_TEAL_LIGHT, COLOR_BORDER});
        toggle.setThumbTintList(thumbColors);
        toggle.setTrackTintList(trackColors);
        toggle.setOnCheckedChangeListener((button, checked) -> {
            autoConnectOnStart = checked;
            getPreferences(MODE_PRIVATE).edit().putBoolean(PREF_AUTO_CONNECT_ON_START, checked).apply();
        });
        LinearLayout.LayoutParams toggleParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        toggleParams.setMarginStart(dp(10));
        preference.addView(toggle, toggleParams);
        return preference;
    }

    private LinearLayout routeSummaryCard() {
        LinearLayout route = new LinearLayout(this);
        route.setOrientation(LinearLayout.HORIZONTAL);
        route.setGravity(Gravity.CENTER_VERTICAL);
        route.setPadding(dp(14), dp(12), dp(12), dp(12));
        route.setBackground(rounded(COLOR_SURFACE, dp(14), null));
        FrameLayout iconFrame = new FrameLayout(this);
        iconFrame.setBackground(rounded(COLOR_SURFACE_ALT, dp(12), null));
        iconFrame.addView(image(R.drawable.xlb_light_globe, 30, COLOR_TEAL_ACCENT), centeredParams(dp(30), dp(30)));
        route.addView(iconFrame, new LinearLayout.LayoutParams(dp(44), dp(44)));
        LinearLayout info = new LinearLayout(this);
        info.setOrientation(LinearLayout.VERTICAL);
        info.setPadding(dp(13), 0, dp(4), 0);
        String displayedRoute = XlbVpnService.STATE_CONNECTED.equals(tunnelState)
                && activeTunnelRouteName != null && !activeTunnelRouteName.isBlank()
                ? activeTunnelRouteName : routeName;
        info.addView(text(displayedRoute, 14, COLOR_INK, true));
        String detail = XlbVpnService.STATE_CONNECTED.equals(tunnelState) ? "节点连接和网络验证已通过" :
                XlbVpnService.STATE_ERROR.equals(tunnelState) ?
                        "线路已就绪，连接未建立" :
                        XlbVpnService.STATE_CONNECTING.equals(tunnelState) ?
                                "正在验证节点连接" : "线路已就绪，点击连接后启动 VPN";
        info.addView(text(detail, 11, COLOR_MUTED, false), spacedParams(4, 0));
        route.addView(info, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        View dot = new View(this);
        dot.setBackground(rounded(connectionStatusColor(), dp(5), null));
        route.addView(dot, new LinearLayout.LayoutParams(dp(10), dp(10)));
        String routeState = XlbVpnService.STATE_CONNECTED.equals(tunnelState) ? "已连接" :
                XlbVpnService.STATE_CONNECTING.equals(tunnelState) ? "连接中" :
                        XlbVpnService.STATE_ERROR.equals(tunnelState) ? "连接失败" :
                                "线路已就绪";
        TextView state = text(routeState, 11, connectionStatusColor(), true);
        LinearLayout.LayoutParams stateParams = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        stateParams.setMarginStart(dp(6));
        route.addView(state, stateParams);
        return route;
    }

    private LinearLayout routeUnavailableCard(PlatformApi.ClientSnapshot current) {
        LinearLayout status = card();
        boolean deviceLimitReached = current != null && current.message != null
                && current.message.contains("设备名额已满");
        String title = routeSyncing ? "正在同步账户线路" :
                deviceLimitReached ? "设备名额已满" :
                hasActiveEntitlement(current) ? "服务线路暂不可用" : "开通会员后获取线路";
        status.addView(text(title, 17, COLOR_INK, true));
        if (hasActiveEntitlement(current)) {
            status.addView(text("会员权益仍有效。", 13, COLOR_MUTED, false), spacedParams(7, 0));
        }
        if (current != null && current.message != null && !current.message.isEmpty()) {
            status.addView(text(current.message, 13, COLOR_MUTED, false), spacedParams(7, 0));
        }
        if (!routeSyncing && current != null) {
            Button retry = secondaryButton("重试同步");
            retry.setEnabled(!busy);
            retry.setOnClickListener(view -> refreshAccount(current.account));
            status.addView(retry, spacedParams(14, 0));
        }
        return status;
    }

    private void addDeveloperTools() {
        if (!BuildConfig.DEBUG) return;
        addSettingsSectionTitle("开发者工具 · 仅 Debug", 18);
        LinearLayout tools = card();
        tools.addView(text("测试线路", 16, COLOR_INK, true));
        tools.addView(text("仅供隔离联调使用，不属于正式会员线路，也不会自动连接。", 13, COLOR_MUTED, false), spacedParams(5, 0));
        Button scan = secondaryButton("扫码导入测试线路");
        scan.setEnabled(!busy && !XlbVpnService.STATE_CONNECTED.equals(tunnelState));
        scan.setOnClickListener(view -> startSubscriptionScan());
        tools.addView(scan, spacedParams(14, 0));
        Button enter = textButton("输入测试订阅地址");
        enter.setEnabled(!busy && !XlbVpnService.STATE_CONNECTED.equals(tunnelState));
        enter.setOnClickListener(view -> showTestUrlDialog());
        tools.addView(enter, spacedParams(8, 0));
        content.addView(tools, spacedParams(0, 0));
    }

    private LinearLayout subscriptionSummaryCard(PlatformApi.ClientSnapshot current) {
        LinearLayout summary = new LinearLayout(this);
        summary.setOrientation(LinearLayout.HORIZONTAL);
        summary.setGravity(Gravity.CENTER_VERTICAL);
        summary.setPadding(dp(14), dp(14), dp(12), dp(14));
        summary.setBackground(rounded(COLOR_SURFACE, dp(14), null));
        FrameLayout iconFrame = new FrameLayout(this);
        iconFrame.setBackground(rounded(COLOR_SURFACE_ALT, dp(10), null));
        iconFrame.addView(image(R.drawable.xlb_light_subscription, 28, COLOR_TEAL_ACCENT), centeredParams(dp(28), dp(28)));
        summary.addView(iconFrame, new LinearLayout.LayoutParams(dp(38), dp(38)));

        LinearLayout detail = new LinearLayout(this);
        detail.setOrientation(LinearLayout.VERTICAL);
        detail.setPadding(dp(14), 0, dp(4), 0);
        boolean entitlementActive = hasActiveEntitlement(current);
        String planName = current.entitlement == null || current.entitlement.planName == null ||
                current.entitlement.planName.isEmpty() ?
                "会员权益" : current.entitlement.planName;
        String title = !entitlementActive ? "暂无有效套餐" : current.entitlement == null ?
                "会员权益有效" : planName + (current.entitlement.trafficGb > 0 ?
                " · " + formatGb(current.entitlement.trafficGb) + " GB" : "");
        detail.addView(text(title, 14, COLOR_INK, true));
        String formattedExpiry = entitlementActive && current.entitlement != null ?
                formatDate(current.entitlement.endsAt) : null;
        String expiry = !entitlementActive ? "开通套餐后可查看有效期" : formattedExpiry == null ?
                "账户会员有效期暂不可用" : "账户会员有效至 " + formattedExpiry;
        detail.addView(text(expiry, 11, COLOR_MUTED, false), spacedParams(3, 0));
        String usage = current.entitlement == null ?
                (entitlementActive ? "会员权益与线路状态独立" : "开通套餐后查看权益") :
                formatGb(current.entitlement.dataUsedGb) + " GB 已使用" +
                        (XlbVpnService.STATE_CONNECTED.equals(tunnelState) ? "" : " · VPN 未连接");
        detail.addView(text(usage, 11, entitlementActive ? COLOR_TEAL_DARK : COLOR_MUTED, true), spacedParams(6, 0));
        summary.addView(detail, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        String manageLabel = entitlementActive ? "管理  ›" : "查看套餐";
        TextView manage = text(manageLabel, 13, COLOR_TEAL_ACCENT, true);
        manage.setGravity(Gravity.CENTER);
        manage.setMinHeight(dp(48));
        manage.setPadding(dp(10), 0, dp(8), 0);
        manage.setBackground(rounded(COLOR_SURFACE_ALT, dp(10), null));
        manage.setContentDescription("查看套餐权益详情");
        manage.setOnClickListener(view -> showEntitlementDetails(current));
        summary.addView(manage);
        summary.setContentDescription("当前套餐，点击查看权益详情");
        summary.setOnClickListener(view -> showEntitlementDetails(current));
        return summary;
    }

    private void showEntitlementDetails(PlatformApi.ClientSnapshot current) {
        PlatformApi.EntitlementSummary entitlement = current.entitlement;
        boolean active = hasActiveEntitlement(current);
        String planName = !active ? "暂无有效套餐" : entitlement == null || entitlement.planName == null || entitlement.planName.isEmpty()
                ? "会员权益有效" : entitlement.planName;
        String formattedExpiry = entitlement == null ? null : formatDate(entitlement.endsAt);
        String expiry = !active ? "未开通" : formattedExpiry == null ? "暂不可用" : formattedExpiry;
        String totalTraffic = entitlement == null || entitlement.trafficGb <= 0
                ? "暂不可用" : formatGb(entitlement.trafficGb) + " GB";
        String usedTraffic = entitlement == null
                ? "暂不可用" : formatGb(entitlement.dataUsedGb) + " GB";
        String details = "账户权益：" + (active ? "有效" : "未开通")
                + "\n套餐：" + planName
                + "\n账户会员有效至：" + expiry
                + "\n套餐流量：" + totalTraffic
                + "\n已使用：" + usedTraffic;
        TextView dialogTitle = text("套餐权益", 20, COLOR_INK, true);
        dialogTitle.setPadding(dp(20), dp(18), dp(20), dp(8));
        AlertDialog dialog = new AlertDialog.Builder(this)
                .setCustomTitle(dialogTitle)
                .setMessage(details)
                .setPositiveButton("完成", null)
                .create();
        dialog.show();
        if (dialog.getWindow() != null) {
            dialog.getWindow().setBackgroundDrawable(rounded(COLOR_SURFACE, dp(16), COLOR_BORDER));
        }
        TextView message = (TextView) dialog.findViewById(android.R.id.message);
        if (message != null) message.setTextColor(COLOR_MUTED);
        Button done = dialog.getButton(AlertDialog.BUTTON_POSITIVE);
        if (done != null) done.setTextColor(COLOR_TEAL_DARK);
    }

    private String connectionStatus() {
        if (XlbVpnService.STATE_CONNECTED.equals(tunnelState)) return "已连接";
        if (XlbVpnService.STATE_CONNECTING.equals(tunnelState)) return "正在连接";
        if (XlbVpnService.STATE_ERROR.equals(tunnelState)) return "连接未成功";
        return "未连接";
    }

    private String connectionRouteLabel() {
        if (XlbVpnService.STATE_CONNECTED.equals(tunnelState)
                && activeTunnelRouteName != null && !activeTunnelRouteName.isBlank()) {
            return activeTunnelRouteName;
        }
        if (routeName != null && !routeName.isBlank()) {
            return (testRoute ? "测试线路 · " : "") + routeName;
        }
        if (routeSyncing) return "正在同步账户线路";
        if (XlbVpnService.STATE_CONNECTING.equals(tunnelState)) return "正在验证节点连接";
        return hasActiveEntitlement(snapshot) ? "服务线路暂不可用" : "开通会员后获取线路";
    }

    private boolean hasActiveEntitlement(PlatformApi.ClientSnapshot current) {
        return current != null && current.entitlement != null;
    }

    private View stateCircle(int color, int radius) {
        View circle = new View(this);
        circle.setBackground(rounded(color, dp(radius), null));
        return circle;
    }

    private int connectionStateLayer(float strength) {
        int base = COLOR_SURFACE;
        int foreground = connectionStatusColor();
        float inverse = 1f - strength;
        return Color.rgb(
                Math.round(Color.red(base) * inverse + Color.red(foreground) * strength),
                Math.round(Color.green(base) * inverse + Color.green(foreground) * strength),
                Math.round(Color.blue(base) * inverse + Color.blue(foreground) * strength));
    }

    private int connectionStatusColor() {
        if (XlbVpnService.STATE_CONNECTED.equals(tunnelState)) return COLOR_SUCCESS;
        if (XlbVpnService.STATE_CONNECTING.equals(tunnelState)) return COLOR_WARNING;
        if (XlbVpnService.STATE_ERROR.equals(tunnelState)) return COLOR_ERROR;
        return COLOR_NEUTRAL;
    }

    private int connectionSurfaceColor() {
        if (XlbVpnService.STATE_CONNECTED.equals(tunnelState)) return COLOR_SUCCESS_BG;
        if (XlbVpnService.STATE_CONNECTING.equals(tunnelState)) return COLOR_WARNING_BG;
        if (XlbVpnService.STATE_ERROR.equals(tunnelState)) return COLOR_ERROR_BG;
        return COLOR_NEUTRAL_BG;
    }

    private String connectionActionLabel() {
        if (XlbVpnService.STATE_CONNECTED.equals(tunnelState)) return "断开连接";
        if (XlbVpnService.STATE_CONNECTING.equals(tunnelState)) return "正在连接…";
        if (XlbVpnService.STATE_ERROR.equals(tunnelState)) return "重试连接";
        return "连接";
    }

    private void connectionAction() {
        if (busy || routeSyncing || XlbVpnService.STATE_CONNECTING.equals(tunnelState)) return;
        if (XlbVpnService.STATE_CONNECTED.equals(tunnelState)) {
            startService(new Intent(this, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP));
            return;
        }
        if (loadedProfile == null || routeName == null) {
            showMessage(hasActiveEntitlement(snapshot) ? "服务线路暂不可用，请重试同步。" : "请先开通会员。", true);
            return;
        }
        pendingProfile = loadedProfile;
        pendingRouteName = routeName;
        Intent approval = VpnService.prepare(this);
        if (approval == null) {
            pendingProfile = null;
            pendingRouteName = null;
            startVpn(loadedProfile, routeName);
        } else {
            startActivityForResult(approval, VPN_PERMISSION_REQUEST);
        }
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
                .setOrientationLocked(true)
                .initiateScan();
    }

    private void showTestUrlDialog() {
        EditText url = input("HTTPS 测试订阅地址", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        new AlertDialog.Builder(this)
                .setTitle("输入测试订阅")
                .setView(url)
                .setNegativeButton("取消", null)
                .setPositiveButton("仅导入", (dialog, which) -> {
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
        loadedProfile = null;
        routeName = null;
        testRoute = false;
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
        String[] labels = {"首页", "线路", "会员", "设置"};
        int[] icons = {R.drawable.xlb_light_home, R.drawable.xlb_light_route,
                R.drawable.xlb_light_subscription_tab, R.drawable.xlb_light_settings};
        for (int index = 0; index < labels.length; index++) {
            final int tab = index;
            LinearLayout item = new LinearLayout(this);
            item.setOrientation(LinearLayout.VERTICAL);
            item.setGravity(Gravity.CENTER);
            FrameLayout iconBackground = new FrameLayout(this);
            iconBackground.setBackground(rounded(index == selectedTab ? COLOR_TEAL_LIGHT : COLOR_SURFACE_ALT, dp(8), null));
            ImageView icon = image(icons[index], 24, index == selectedTab ? COLOR_TEAL_ACCENT : COLOR_MUTED);
            iconBackground.addView(icon, centeredParams(dp(24), dp(24)));
            item.addView(iconBackground, new LinearLayout.LayoutParams(dp(30), dp(30)));
            TextView label = text(labels[index], 11, index == selectedTab ? COLOR_TEAL_ACCENT : COLOR_MUTED, index == selectedTab);
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
            String importedRoute = null;
            String error = null;
            try {
                profile = DebugProfileFetcher.fetch(address);
                importedRoute = XlbVpnService.firstRouteName(profile);
                if (importedRoute == null || importedRoute.isBlank()) {
                    throw new DebugProfileFetcher.ProfileException("没有找到可加载线路；请使用包含内联节点或 HTTPS 节点提供者的 Clash/Mihomo 订阅");
                }
            } catch (DebugProfileFetcher.ProfileException exception) {
                error = exception.getMessage();
            } catch (Exception exception) {
                error = "订阅下载失败，请检查网络和地址";
            }
            String result = profile;
            String selectedRoute = displayRouteName(importedRoute);
            String failure = error;
            mainHandler.post(() -> {
                busy = false;
                if (isFinishing() || isDestroyed()) return;
                if (failure != null) {
                    tunnelMessage = failure;
                    selectedTab = 0;
                    render();
                    showMessage(failure, true);
                    return;
                }
                tunnelMessage = null;
                loadedProfile = result;
                routeName = selectedRoute;
                testRoute = true;
                selectedTab = 0;
                render();
                showMessage("测试线路已加载，点击连接后启动 VPN。", false);
            });
        });
    }

    private void startVpn(String profile, String selectedRoute) {
        tunnelState = XlbVpnService.STATE_CONNECTING;
        tunnelMessage = null;
        activeTunnelRouteName = selectedRoute;
        render();
        Intent start = new Intent(this, XlbVpnService.class).setAction(XlbVpnService.ACTION_START)
                .putExtra(XlbVpnService.EXTRA_PROFILE, profile)
                .putExtra(XlbVpnService.EXTRA_ROUTE_NAME, selectedRoute);
        try {
            startForegroundService(start);
        } catch (RuntimeException exception) {
            tunnelState = XlbVpnService.STATE_ERROR;
            tunnelMessage = "无法启动系统 VPN 服务";
            render();
        }
    }

    private String displayRouteName(String value) {
        if (value == null) return null;
        String cleaned = value.replaceAll("[\\p{Cntrl}\\u202A-\\u202E\\u2066-\\u2069]", "").trim();
        if (cleaned.length() > 48) cleaned = cleaned.substring(0, 48) + "…";
        return cleaned.isEmpty() ? null : cleaned;
    }

    private String accountRouteName(PlatformApi.ClientSnapshot result) {
        if (result == null || result.accountProfile == null) return null;
        try {
            return displayRouteName(XlbVpnService.firstRouteName(result.accountProfile));
        } catch (RuntimeException | UnsatisfiedLinkError exception) {
            return null;
        }
    }

    private void applySyncedSnapshot(PlatformApi.ClientSnapshot result, String selectedRoute) {
        snapshot = result;
        routeSyncing = false;
        loadedProfile = selectedRoute == null || result == null ? null : result.accountProfile;
        routeName = selectedRoute;
        testRoute = false;
        if ((result == null || !hasActiveEntitlement(result))
                && XlbVpnService.STATE_CONNECTED.equals(tunnelState)) {
            startService(new Intent(this, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP));
        }
        render();
        maybeStartVpnAtStartup();
    }

    private void maybeStartVpnAtStartup() {
        if (startupAutoConnectChecked) return;
        startupAutoConnectChecked = true;
        boolean tunnelAlreadyActive = XlbVpnService.STATE_CONNECTED.equals(tunnelState)
                || XlbVpnService.STATE_CONNECTING.equals(tunnelState);
        boolean vpnPermissionGranted = VpnService.prepare(this) == null;
        if (!AutoConnectPolicy.shouldStart(autoConnectOnStart, vpnPermissionGranted,
                hasActiveEntitlement(snapshot), loadedProfile != null && !testRoute, tunnelAlreadyActive)) return;
        startVpn(loadedProfile, routeName);
    }

    private void addDevicesCard(PlatformApi.ClientSnapshot current) {
        addDevicesCard(current, 16);
    }

    private void addDevicesCard(PlatformApi.ClientSnapshot current, int bottomMargin) {
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
                String deviceDetail = platformName(device.platform) + (device.removalPending ? " · 撤权确认中" : "");
                deviceActions.addView(text(deviceDetail, 13, device.removalPending ? COLOR_WARNING : COLOR_MUTED, false),
                        new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
                Button remove = textButton("移除");
                remove.setTextSize(13f);
                remove.setMinWidth(dp(88));
                remove.setMinHeight(dp(44));
                remove.setPadding(dp(12), 0, dp(12), 0);
                remove.setContentDescription("移除设备：" + device.name);
                remove.setEnabled(!busy && !device.removalPending);
                if (device.removalPending) {
                    remove.setText("等待确认");
                }
                remove.setOnClickListener(view -> confirmDeviceRemoval(current.account, device));
                LinearLayout.LayoutParams removeParams = new LinearLayout.LayoutParams(
                        ViewGroup.LayoutParams.WRAP_CONTENT, dp(44));
                removeParams.setMarginStart(dp(16));
                deviceActions.addView(remove, removeParams);
                deviceCard.addView(deviceActions, spacedParams(30, 0));
            }
            if (!thisPhoneListed) {
                String hint = !current.device.entitlementActive ? "需要有效套餐后才能添加设备。" : "此手机尚未加入设备列表。";
                deviceCard.addView(text(hint, 14, COLOR_MUTED, false), spacedParams(14, 0));
            }
        }
        content.addView(deviceCard, spacedParams(0, bottomMargin));
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
            boolean removalPending = false;
            try {
                removalPending = api.removeDevice(device.id);
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
                    updated = api.synchronize(account);
                }
            } catch (PlatformApi.PlatformApiException exception) {
                failure = exception.getMessage();
            }
            boolean confirmedRemoved = removed;
            boolean needsReinstall = localResetFailed;
            PlatformApi.ClientSnapshot refreshed = updated;
            boolean pending = removalPending;
            String refreshedRoute = accountRouteName(updated);
            String error = failure;
            mainHandler.post(() -> {
                if (isFinishing() || isDestroyed()) return;
                busy = false;
                if (!confirmedRemoved) {
                    showMessage(error == null ? "设备移除失败，请重试" : error, true);
                    return;
                }
                if (device.current) {
                    applySyncedSnapshot(null, null);
                } else {
                    applySyncedSnapshot(refreshed == null ? new PlatformApi.ClientSnapshot(account, null, null) : refreshed,
                            refreshedRoute);
                }
                String completion = needsReinstall ? "移除请求已提交，本地状态清理失败，请重装应用" :
                        pending && device.current ? "本机已退出；节点确认撤权后释放设备名额" :
                                pending ? "撤权请求已提交；节点确认后释放设备名额" :
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
        if (!clearOnFailure) {
            routeSyncing = true;
            loadedProfile = null;
            routeName = null;
            testRoute = false;
            if (snapshot != null) render();
        }
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
            String syncedRoute = accountRouteName(result);
            Exception finalFailure = failure;
            mainHandler.post(() -> {
                if (isFinishing() || isDestroyed()) {
                    return;
                }
                busy = false;
                if (finalResult != null) {
                    if (snapshot == null) selectedTab = 0;
                    applySyncedSnapshot(finalResult, syncedRoute);
                } else if (clearOnFailure) {
                    applySyncedSnapshot(null, null);
                } else {
                    routeSyncing = false;
                    render();
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
