package com.xlb.vpn.presentation;

import android.app.Activity;
import android.annotation.SuppressLint;
import android.content.Context;
import android.content.BroadcastReceiver;
import android.content.Intent;
import android.content.IntentFilter;
import android.graphics.Color;
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
import android.widget.ImageView;
import android.widget.LinearLayout;
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

public final class MainActivity extends Activity {
    private static final int COLOR_CANVAS = Color.rgb(243, 247, 247);
    private static final int COLOR_INK = Color.rgb(24, 39, 45);
    private static final int COLOR_MUTED = Color.rgb(91, 109, 115);
    private static final int COLOR_HINT = Color.rgb(145, 159, 163);
    private static final int COLOR_TEAL = Color.rgb(8, 126, 121);
    private static final int COLOR_TEAL_DARK = Color.rgb(5, 94, 91);
    private static final int COLOR_TEAL_LIGHT = Color.rgb(225, 244, 241);
    private static final int COLOR_ERROR = Color.rgb(184, 62, 62);
    private static final int COLOR_BORDER = Color.rgb(220, 229, 229);
    private static final int COLOR_NOTICE = Color.rgb(232, 240, 240);
    private static final String MESSAGE_TAG = "operation_message";

    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private PlatformApi api;
    private LinearLayout content;
    private PlatformApi.ClientSnapshot snapshot;
    private boolean busy;
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
        getWindow().setStatusBarColor(COLOR_CANVAS);
        getWindow().setNavigationBarColor(COLOR_CANVAS);
        getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR | View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR);
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
        setPage();
        TextView loading = text("正在打开迅连宝…", 16, COLOR_MUTED, false);
        loading.setGravity(Gravity.CENTER);
        loading.setPadding(0, dp(90), 0, dp(90));
        content.addView(loading);
    }

    private void render() {
        setPage();
        addBrandHeader();
        if (snapshot == null) {
            renderLoginForm();
        } else {
            renderAccount(snapshot);
        }
        addFooter();
    }

    private void setPage() {
        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        scroll.setBackgroundColor(COLOR_CANVAS);
        content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(dp(22), dp(14), dp(22), dp(20));
        scroll.addView(content);
        setContentView(scroll);
    }

    private void addBrandHeader() {
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        header.setPadding(0, dp(8), 0, dp(26));
        ImageView mark = new ImageView(this);
        mark.setImageResource(R.drawable.xlb_mark);
        header.addView(mark, new LinearLayout.LayoutParams(dp(44), dp(44)));
        LinearLayout brand = new LinearLayout(this);
        brand.setOrientation(LinearLayout.VERTICAL);
        brand.addView(text("迅连宝", 20, COLOR_INK, true));
        TextView caption = text("SECURE NETWORK", 10, COLOR_MUTED, true);
        caption.setLetterSpacing(0.1f);
        caption.setPadding(0, dp(2), 0, 0);
        brand.addView(caption);
        LinearLayout.LayoutParams brandParams = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        brandParams.setMarginStart(dp(11));
        header.addView(brand, brandParams);
        TextView environment = text("本地联调", 12, COLOR_TEAL, true);
        environment.setGravity(Gravity.CENTER);
        environment.setPadding(dp(12), dp(8), dp(12), dp(8));
        environment.setBackground(rounded(COLOR_TEAL_LIGHT, dp(18), null));
        header.addView(environment);
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

    private void renderAccount(PlatformApi.ClientSnapshot current) {
        content.addView(text("你好，" + current.account.name, 25, COLOR_INK, true));
        TextView email = text(current.account.email, 14, COLOR_MUTED, false);
        email.setPadding(0, dp(5), 0, dp(18));
        content.addView(email);

        LinearLayout vpnCard = card();
        vpnCard.addView(text("连接状态", 13, COLOR_MUTED, true));
        String status = XlbVpnService.STATE_CONNECTED.equals(tunnelState) ? "VPN 隧道已建立" :
                XlbVpnService.STATE_CONNECTING.equals(tunnelState) ? "正在建立 VPN" :
                XlbVpnService.STATE_ERROR.equals(tunnelState) ? "连接未建立" : "尚未连接 VPN";
        TextView disconnected = text(status, 19, COLOR_INK, true);
        disconnected.setPadding(0, dp(6), 0, dp(7));
        vpnCard.addView(disconnected);
        if (tunnelMessage != null) {
            vpnCard.addView(text(tunnelMessage, 14, COLOR_ERROR, false));
        }
        if (BuildConfig.DEBUG) {
            vpnCard.addView(text("开发测试 · 仅连接订阅首个节点", 13, COLOR_MUTED, false));
            EditText url = input("HTTPS 测试订阅地址", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
            vpnCard.addView(url, spacedParams(14, 10));
            Button connect = primaryButton("导入并连接测试配置");
            connect.setEnabled(!busy && !XlbVpnService.STATE_CONNECTED.equals(tunnelState));
            connect.setOnClickListener(view -> {
                String address = url.getText().toString().trim();
                url.setText("");
                if (address.isEmpty()) {
                    showMessage("请输入 HTTPS 测试订阅地址", true);
                    return;
                }
                hideKeyboard(url);
                importSubscription(address);
            });
            vpnCard.addView(connect);
            Button scan = secondaryButton("扫码导入订阅");
            scan.setEnabled(!busy && !XlbVpnService.STATE_CONNECTED.equals(tunnelState));
            scan.setOnClickListener(view -> new IntentIntegrator(this)
                    .setDesiredBarcodeFormats(IntentIntegrator.QR_CODE)
                    .setPrompt("扫描订阅二维码")
                    .setBeepEnabled(false)
                    .setBarcodeImageEnabled(false)
                    .setOrientationLocked(false)
                    .initiateScan());
            vpnCard.addView(scan, spacedParams(9, 0));
            if (XlbVpnService.STATE_CONNECTING.equals(tunnelState) || XlbVpnService.STATE_CONNECTED.equals(tunnelState)) {
                Button disconnect = secondaryButton("断开连接");
                disconnect.setOnClickListener(view -> startService(new Intent(this, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP)));
                vpnCard.addView(disconnect, spacedParams(9, 0));
            }
        }
        content.addView(vpnCard, spacedParams(0, 12));
        addDevicesCard(current);

        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        Button refresh = secondaryButton(busy ? "刷新中…" : "刷新");
        refresh.setEnabled(!busy);
        refresh.setOnClickListener(view -> perform("正在刷新…", false, () -> api.synchronize(current.account)));
        actions.addView(refresh, new LinearLayout.LayoutParams(0, dp(52), 1f));
        Button logout = textButton("退出登录");
        logout.setOnClickListener(view -> {
            startService(new Intent(this, XlbVpnService.class).setAction(XlbVpnService.ACTION_STOP));
            perform("正在退出…", true, () -> {
            api.logout();
            return null;
            });
        });
        LinearLayout.LayoutParams logoutParams = new LinearLayout.LayoutParams(0, dp(52), 1f);
        logoutParams.setMarginStart(dp(10));
        actions.addView(logout, logoutParams);
        content.addView(actions);
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

    private void addFooter() {
        TextView endpoint = text(BuildConfig.DEBUG ? "开发接口 · 127.0.0.1:4175" : "迅连宝服务", 12, COLOR_MUTED, false);
        endpoint.setGravity(Gravity.CENTER);
        endpoint.setPadding(0, dp(22), 0, dp(4));
        content.addView(endpoint);
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
                deviceCard.addView(text(platformName(device.platform), 13, COLOR_MUTED, false));
            }
            if (!thisPhoneListed) {
                String hint = !current.device.entitlementActive ? "当前没有有效套餐，暂不能添加设备。" :
                        current.message != null ? current.message : "此手机尚未加入设备列表。";
                deviceCard.addView(text(hint, 14, COLOR_MUTED, false), spacedParams(14, 0));
            }
        }
        content.addView(deviceCard, spacedParams(0, 16));
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
        card.setBackground(rounded(Color.WHITE, dp(18), null));
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
        input.setBackground(rounded(Color.WHITE, dp(12), COLOR_BORDER));
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
        return baseButton(label, COLOR_MUTED, Color.WHITE, COLOR_BORDER);
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
