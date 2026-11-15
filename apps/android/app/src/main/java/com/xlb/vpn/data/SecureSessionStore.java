package com.xlb.vpn.data;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import org.json.JSONException;
import org.json.JSONObject;

import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;
import java.util.UUID;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

public final class SecureSessionStore {
    private static final String ANDROID_KEYSTORE = "AndroidKeyStore";
    private static final String COOKIES = "cookies";
    private static final int GCM_IV_SIZE = 12;
    private static final int GCM_TAG_BITS = 128;
    private static final String INSTALLATION_ID = "installation_id";
    private static final String STATE_KEY = "encrypted_state";
    private static final String TRANSFORMATION = "AES/GCM/NoPadding";

    private final SharedPreferences preferences;
    private final String keyAlias = "xlb-session-aes-v1";

    public SecureSessionStore(Context context) {
        preferences = context.getSharedPreferences("secure_client_state", Context.MODE_PRIVATE);
    }

    public synchronized String installationId() {
        JSONObject state = readState();
        String existing = state.optString(INSTALLATION_ID, "").trim();
        if (!existing.isEmpty()) {
            return existing;
        }
        String created = UUID.randomUUID().toString();
        try {
            state.put(INSTALLATION_ID, created);
        } catch (JSONException exception) {
            throw new IllegalStateException("无法保存设备标识", exception);
        }
        writeState(state);
        return created;
    }

    public synchronized String cookieHeader() {
        JSONObject cookies = readState().optJSONObject(COOKIES);
        if (cookies == null) {
            return "";
        }
        List<String> values = new ArrayList<>();
        Iterator<String> names = cookies.keys();
        while (names.hasNext()) {
            String name = names.next();
            String value = cookies.optString(name, "").trim();
            if (!value.isEmpty()) {
                values.add(name + "=" + value);
            }
        }
        return String.join("; ", values);
    }

    public synchronized void updateCookie(String name, String value, boolean delete) {
        JSONObject state = readState();
        JSONObject cookies = state.optJSONObject(COOKIES);
        if (cookies == null) {
            cookies = new JSONObject();
            try {
                state.put(COOKIES, cookies);
            } catch (JSONException exception) {
                throw new IllegalStateException("无法保存会话", exception);
            }
        }
        if (delete) {
            cookies.remove(name);
        } else {
            try {
                cookies.put(name, value);
            } catch (JSONException exception) {
                throw new IllegalStateException("无法保存会话", exception);
            }
        }
        writeState(state);
    }

    public synchronized void clearSession() {
        JSONObject state = readState();
        try {
            state.put(COOKIES, new JSONObject());
        } catch (JSONException exception) {
            throw new IllegalStateException("无法清理会话", exception);
        }
        writeState(state);
    }

    private JSONObject readState() {
        String encrypted = preferences.getString(STATE_KEY, null);
        if (encrypted == null || encrypted.isEmpty()) {
            return new JSONObject();
        }
        try {
            byte[] packed = Base64.decode(encrypted, Base64.NO_WRAP);
            byte[] iv = new byte[GCM_IV_SIZE];
            byte[] ciphertext = new byte[packed.length - GCM_IV_SIZE];
            System.arraycopy(packed, 0, iv, 0, GCM_IV_SIZE);
            System.arraycopy(packed, GCM_IV_SIZE, ciphertext, 0, ciphertext.length);
            Cipher cipher = Cipher.getInstance(TRANSFORMATION);
            cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(), new GCMParameterSpec(GCM_TAG_BITS, iv));
            return new JSONObject(new String(cipher.doFinal(ciphertext), StandardCharsets.UTF_8));
        } catch (Exception exception) {
            preferences.edit().remove(STATE_KEY).apply();
            return new JSONObject();
        }
    }

    private void writeState(JSONObject state) {
        try {
            Cipher cipher = Cipher.getInstance(TRANSFORMATION);
            cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey());
            byte[] encrypted = cipher.doFinal(state.toString().getBytes(StandardCharsets.UTF_8));
            byte[] packed = new byte[cipher.getIV().length + encrypted.length];
            System.arraycopy(cipher.getIV(), 0, packed, 0, cipher.getIV().length);
            System.arraycopy(encrypted, 0, packed, cipher.getIV().length, encrypted.length);
            preferences.edit().putString(STATE_KEY, Base64.encodeToString(packed, Base64.NO_WRAP)).apply();
        } catch (Exception exception) {
            throw new IllegalStateException("无法加密保存会话", exception);
        }
    }

    private SecretKey getOrCreateKey() throws Exception {
        KeyStore keyStore = KeyStore.getInstance(ANDROID_KEYSTORE);
        keyStore.load(null);
        if (keyStore.containsAlias(keyAlias)) {
            return (SecretKey) keyStore.getKey(keyAlias, null);
        }
        KeyGenerator generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE);
        generator.init(new KeyGenParameterSpec.Builder(
                keyAlias,
                KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT
        )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true)
                .build());
        return generator.generateKey();
    }
}
