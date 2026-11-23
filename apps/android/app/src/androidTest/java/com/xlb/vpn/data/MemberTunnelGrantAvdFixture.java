package com.xlb.vpn.data;

import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.MessageDigest;
import java.security.KeyStore;
import java.security.cert.X509Certificate;
import java.util.Enumeration;
import java.util.Locale;
import java.util.UUID;

/** Builds a disposable test grant with the same shape as the authorized member API response. */
public final class MemberTunnelGrantAvdFixture {
    public static final String NODE_ID = "81000000-0000-4000-8000-000000000003";
    public static final String DEVICE_ID = "81000000-0000-4000-8000-000000000001";
    public static final String PEER_ID = "81000000-0000-4000-8000-000000000002";
    public static final long PEER_GENERATION = 3;
    public static final long DEVICE_GENERATION = 7;
    public static final String PROFILE = "mixed-port: 7890\n"
            + "allow-lan: false\n"
            + "bind-address: 127.0.0.1\n"
            + "mode: global\n"
            + "log-level: warning\n"
            + "proxies:\n"
            + "  - name: XLB proof bridge\n"
            + "    type: socks5\n"
            + "    server: 127.0.0.1\n"
            + "    port: 17890\n"
            + "    udp: false\n"
            + "proxy-groups:\n"
            + "  - name: GLOBAL\n"
            + "    type: select\n"
            + "    proxies:\n"
            + "      - XLB proof bridge\n"
            + "rules:\n"
            + "  - MATCH,GLOBAL\n";

    private MemberTunnelGrantAvdFixture() {}

    public static String newInstallationId() {
        return UUID.randomUUID().toString();
    }

    public static String publicKey(String installationId) throws GeneralSecurityException {
        return new DeviceKeyStore().publicKey(installationId);
    }

    public static String publicKeyFingerprint(String publicKey) throws Exception {
        return sha256(DeviceBindingProof.decode(publicKey));
    }

    public static MemberTunnelGrant createGrant(String installationId, String publicKey,
            String bridgeEndpoint, String trustedServerCaPem) throws Exception {
        MemberDeviceProof.Signer signer = signer(installationId, publicKey);
        MemberDeviceProof proof = MemberDeviceProof.fromAuthorizedResponse("member-avd", installationId,
                publicKey, DEVICE_ID, "registered", true, "ready", PEER_ID, NODE_ID,
                PEER_GENERATION, DEVICE_GENERATION, DeviceBindingProof.encode(new byte[32]), PROFILE, signer);
        if (proof == null) throw new GeneralSecurityException("test member proof could not be created");
        String profileSha256 = sha256(PROFILE);
        MemberTunnelGrant grant = MemberTunnelGrant.fromAuthorizedResponse(proof, "ready",
                NODE_ID, PEER_ID, PEER_GENERATION, DEVICE_GENERATION, bridgeEndpoint, trustedServerCaPem,
                PROFILE, profileSha256, "xlb-mihomo-bridge-v1", "1.0.0", "xlb-proof-v1", true);
        if (grant == null) throw new GeneralSecurityException("test member grant could not be created");
        return grant;
    }

    public static String trustedSystemCaPem() throws Exception {
        KeyStore systemRoots = KeyStore.getInstance("AndroidCAStore");
        systemRoots.load(null);
        Enumeration<String> aliases = systemRoots.aliases();
        while (aliases.hasMoreElements()) {
            java.security.cert.Certificate candidate = systemRoots.getCertificate(aliases.nextElement());
            if (!(candidate instanceof X509Certificate)) continue;
            X509Certificate certificate = (X509Certificate) candidate;
            boolean[] keyUsage = certificate.getKeyUsage();
            if (certificate.getBasicConstraints() < 0 || keyUsage == null || keyUsage.length <= 5 || !keyUsage[5]) {
                continue;
            }
            try {
                certificate.checkValidity();
            } catch (java.security.cert.CertificateExpiredException | java.security.cert.CertificateNotYetValidException ignored) {
                continue;
            }
            String encoded = android.util.Base64.encodeToString(certificate.getEncoded(), android.util.Base64.NO_WRAP);
            return "-----BEGIN CERTIFICATE-----\n" + encoded + "\n-----END CERTIFICATE-----\n";
        }
        throw new GeneralSecurityException("Android system trust store has no currently valid CA certificate");
    }

    public static void deleteInstallationKey(String installationId) throws Exception {
        java.security.KeyStore store = java.security.KeyStore.getInstance("AndroidKeyStore");
        store.load(null);
        String alias = "xlb-device-p256-v1-" + installationId;
        if (store.containsAlias(alias)) store.deleteEntry(alias);
    }

    private static MemberDeviceProof.Signer signer(String installationId, String publicKey) {
        DeviceKeyStore keyStore = new DeviceKeyStore();
        return new MemberDeviceProof.Signer() {
            @Override
            public String publicKeySpkiBase64Url() {
                return publicKey;
            }

            @Override
            public String signChallengeBase64Url(byte[] challenge) throws GeneralSecurityException {
                return keyStore.sign(installationId, DeviceBindingProof.encode(challenge));
            }

            @Override
            public byte[] signDigest(byte[] digest) throws GeneralSecurityException {
                return keyStore.signDigestBytes(installationId, digest);
            }
        };
    }

    private static String sha256(String value) throws Exception {
        return sha256(value.getBytes(StandardCharsets.UTF_8));
    }

    private static String sha256(byte[] value) throws Exception {
        byte[] digest = MessageDigest.getInstance("SHA-256").digest(value);
        StringBuilder encoded = new StringBuilder(digest.length * 2);
        for (byte item : digest) encoded.append(String.format(Locale.ROOT, "%02x", item));
        return encoded.toString();
    }
}
