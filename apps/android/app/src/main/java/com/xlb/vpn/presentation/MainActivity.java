package com.xlb.vpn.presentation;

import android.app.Activity;
import android.content.Context;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.InputType;
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
import com.xlb.vpn.data.SecureSessionStore;

import java.time.Instant;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.Locale;
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
    private static final int COLOR_GREEN = Color.rgb(36, 157, 109);
    private static final int COLOR_AMBER = Color.rgb(220, 152, 43);
    private static final int COLOR_AMBER_DARK = Color.rgb(151, 97, 17);
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

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().setStatusBarColor(COLOR_CANVAS);
        getWindow().setNavigationBarColor(COLOR_CANVAS);
        getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR | View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR);
        api = new PlatformApi(BuildConfig.API_BASE_URL, new SecureSessionStore(this));
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
        worker.shutdownNow();
        super.onDestroy();
    }

    private void renderLoading() {
        setPage();
        TextView loading = text("正在安全检查登录状态…", 16, COLOR_MUTED, false);
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
        TextView subtitle = text("登录后自动登记此手机并同步客户端版本。", 15, COLOR_MUTED, false);
        subtitle.setPadding(0, dp(7), 0, dp(21));
        content.addView(subtitle);

        LinearLayout card = card();
        card.addView(text("会员邮箱", 13, COLOR_INK, true));
        EditText email = input("member@xlb.local", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);
        card.addView(email, spacedParams(7, 17));
        card.addView(text("登录密码", 13, COLOR_INK, true));
        EditText password = input("输入账户密码", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        card.addView(password, spacedParams(7, 19));
        Button login = primaryButton("登录并同步设备");
        login.setOnClickListener(view -> {
            String emailValue = email.getText().toString().trim();
            String passwordValue = password.getText().toString();
            if (emailValue.isEmpty() || passwordValue.isEmpty()) {
                showMessage("请输入会员邮箱和密码", true);
                return;
            }
            hideKeyboard(password);
            perform("正在登录并登记设备…", false, () -> {
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

        LinearLayout stateCard = card();
        LinearLayout stateRow = new LinearLayout(this);
        stateRow.setOrientation(LinearLayout.HORIZONTAL);
        stateRow.setGravity(Gravity.CENTER_VERTICAL);
        View dot = new View(this);
        dot.setBackground(rounded(current.device == null ? COLOR_AMBER : COLOR_GREEN, dp(20), null));
        stateRow.addView(dot, new LinearLayout.LayoutParams(dp(10), dp(10)));
        TextView state = text(current.device == null ? "设备同步待处理" : "账户已同步", 17, COLOR_INK, true);
        LinearLayout.LayoutParams stateParams = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        stateParams.setMarginStart(dp(10));
        stateRow.addView(state, stateParams);
        stateCard.addView(stateRow);
        stateCard.addView(text(current.message == null ? "设备登记和客户端版本回报已完成" : current.message, 14,
                current.message == null ? COLOR_MUTED : COLOR_AMBER_DARK, false));
        TextView message = (TextView) stateCard.getChildAt(1);
        message.setPadding(dp(20), dp(8), 0, 0);
        content.addView(stateCard, spacedParams(0, 12));

        LinearLayout deviceCard = card();
        deviceCard.addView(text("本机设备", 13, COLOR_MUTED, true));
        TextView model = text(current.device == null ? android.os.Build.MODEL : current.device.deviceName, 19, COLOR_INK, true);
        model.setPadding(0, dp(6), 0, dp(10));
        deviceCard.addView(model);
        if (current.device != null) {
            deviceCard.addView(infoRow("设备名额", current.device.registered + " / " + current.device.maximum));
            deviceCard.addView(infoRow("会员权益", current.device.entitlementActive ? "有效" : "未生效"));
            deviceCard.addView(infoRow("最近回报", formatTime(current.device.lastSeenAt)));
        }
        content.addView(deviceCard, spacedParams(0, 12));

        LinearLayout vpnCard = card();
        vpnCard.addView(text("连接状态", 13, COLOR_MUTED, true));
        TextView disconnected = text("尚未连接 VPN", 19, COLOR_INK, true);
        disconnected.setPadding(0, dp(6), 0, dp(7));
        vpnCard.addView(disconnected);
        vpnCard.addView(text("当前版本仅接通账户与设备登记。没有加载真实订阅或 VPN 节点。", 14, COLOR_MUTED, false));
        content.addView(vpnCard, spacedParams(0, 16));

        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        Button refresh = secondaryButton(busy ? "同步中…" : "重新同步");
        refresh.setEnabled(!busy);
        refresh.setOnClickListener(view -> perform("正在刷新设备回报…", false, () -> api.synchronize(current.account)));
        actions.addView(refresh, new LinearLayout.LayoutParams(0, dp(52), 1f));
        Button logout = textButton("退出登录");
        logout.setOnClickListener(view -> perform("正在退出…", true, () -> {
            api.logout();
            return null;
        }));
        LinearLayout.LayoutParams logoutParams = new LinearLayout.LayoutParams(0, dp(52), 1f);
        logoutParams.setMarginStart(dp(10));
        actions.addView(logout, logoutParams);
        content.addView(actions);
    }

    private void addFooter() {
        TextView endpoint = text(BuildConfig.DEBUG ? "开发接口 · 127.0.0.1:4175" : "迅连宝服务", 12, COLOR_MUTED, false);
        endpoint.setGravity(Gravity.CENTER);
        endpoint.setPadding(0, dp(22), 0, dp(4));
        content.addView(endpoint);
    }

    private View infoRow(String label, String value) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setPadding(0, dp(8), 0, dp(5));
        row.addView(text(label, 14, COLOR_MUTED, false), new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        row.addView(text(value, 14, COLOR_INK, true));
        return row;
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
        input.setInputType(type);
        input.setHint(hint);
        input.setTextSize(16f);
        input.setTextColor(COLOR_INK);
        input.setHintTextColor(COLOR_HINT);
        input.setSingleLine(true);
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

    private String formatTime(String value) {
        try {
            DateTimeFormatter formatter = DateTimeFormatter.ofPattern("M月d日 HH:mm", Locale.SIMPLIFIED_CHINESE);
            return Instant.parse(value).atZone(ZoneId.systemDefault()).format(formatter);
        } catch (Exception ignored) {
            return value;
        }
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density);
    }

    private interface ApiOperation {
        PlatformApi.ClientSnapshot run() throws Exception;
    }
}
