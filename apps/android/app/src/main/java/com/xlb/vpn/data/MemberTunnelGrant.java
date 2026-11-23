package com.xlb.vpn.data;

import java.net.URI;
import java.io.ByteArrayInputStream;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.cert.CertificateFactory;
import java.security.cert.X509Certificate;
import java.util.Locale;
import java.util.regex.Pattern;

/** Ephemeral server-authorized inputs required to start one proof-bound member tunnel. */
public final class MemberTunnelGrant {
    private static final int MAX_PROFILE_BYTES = 128 * 1024;
    private static final int MAX_CA_BUNDLE_BYTES = 64 * 1024;
    private static final Pattern SHA256 = Pattern.compile("^[a-f0-9]{64}$");
    private static final Pattern VERSION = Pattern.compile("^[0-9]+\\.[0-9]+\\.[0-9]+$");

    private final MemberDeviceProof deviceProof;
    private final String nodeId;
    private final String peerId;
    private final long peerGeneration;
    private final long deviceBindingGeneration;
    private final String bridgeEndpoint;
    private final String trustedServerCaPem;
    private final String profile;
    private final String profileSha256;
    private final String rendererId;
    private final String rendererVersion;

    private MemberTunnelGrant(MemberDeviceProof deviceProof, String bridgeEndpoint,
            String trustedServerCaPem, String profile, String profileSha256,
            String rendererId, String rendererVersion) {
        this.deviceProof = deviceProof;
        this.nodeId = deviceProof.nodeId();
        this.peerId = deviceProof.peerId();
        this.peerGeneration = deviceProof.peerGeneration();
        this.deviceBindingGeneration = deviceProof.deviceBindingGeneration();
        this.bridgeEndpoint = bridgeEndpoint;
        this.trustedServerCaPem = trustedServerCaPem;
        this.profile = profile;
        this.profileSha256 = profileSha256;
        this.rendererId = rendererId;
        this.rendererVersion = rendererVersion;
    }

    static MemberTunnelGrant fromAuthorizedResponse(MemberDeviceProof deviceProof, String authorizationStatus,
            String nodeId, String peerId, long peerGeneration, long deviceBindingGeneration,
            String bridgeEndpoint, String trustedServerCaPem, String profile, String profileSha256,
            String rendererId, String rendererVersion, String bridgeProtocol, boolean connectable) {
        if (deviceProof == null || !deviceProof.hasRequiredReferences()
                || !"ready".equals(authorizationStatus)
                || !deviceProof.nodeId().equals(nodeId) || !deviceProof.peerId().equals(peerId)
                || deviceProof.peerGeneration() != peerGeneration
                || deviceProof.deviceBindingGeneration() != deviceBindingGeneration
                || !validBridgeEndpoint(bridgeEndpoint) || !validCaBundle(trustedServerCaPem)
                || !validProfile(profile) || !validProfileDigest(profile, profileSha256)
                || !"xlb-mihomo-bridge-v1".equals(rendererId)
                || rendererVersion == null || !VERSION.matcher(rendererVersion).matches()
                || !"xlb-proof-v1".equals(bridgeProtocol) || !connectable) {
            return null;
        }

        return new MemberTunnelGrant(deviceProof, bridgeEndpoint, trustedServerCaPem,
                profile, profileSha256, rendererId, rendererVersion);
    }

    public MemberDeviceProof deviceProof() { return deviceProof; }
    public String nodeId() { return nodeId; }
    public String peerId() { return peerId; }
    public long peerGeneration() { return peerGeneration; }
    public long deviceBindingGeneration() { return deviceBindingGeneration; }
    public String bridgeEndpoint() { return bridgeEndpoint; }
    public String trustedServerCaPem() { return trustedServerCaPem; }
    public String profile() { return profile; }
    public String profileSha256() { return profileSha256; }
    public String rendererId() { return rendererId; }
    public String rendererVersion() { return rendererVersion; }

    @Override
    public String toString() {
        return "MemberTunnelGrant[verified]";
    }

    private static boolean validBridgeEndpoint(String endpoint) {
        if (endpoint == null || endpoint.isEmpty() || endpoint.length() > 2048) return false;
        try {
            URI uri = URI.create(endpoint);
            int port = uri.getPort();
            String path = uri.getPath();
            return "https".equals(uri.getScheme()) && uri.getHost() != null
                    && uri.getUserInfo() == null && uri.getQuery() == null && uri.getFragment() == null
                    && (path == null || path.isEmpty() || "/".equals(path))
                    && (port == -1 || (port >= 1 && port <= 65535));
        } catch (IllegalArgumentException exception) {
            return false;
        }
    }

    private static boolean validCaBundle(String caBundle) {
        if (caBundle == null) return false;
        byte[] bytes = caBundle.getBytes(StandardCharsets.UTF_8);
        if (bytes.length == 0 || bytes.length > MAX_CA_BUNDLE_BYTES) return false;
        try {
            CertificateFactory factory = CertificateFactory.getInstance("X.509");
            java.util.Collection<? extends java.security.cert.Certificate> certificates =
                    factory.generateCertificates(new ByteArrayInputStream(bytes));
            if (certificates.isEmpty() || certificates.size() > 8) return false;
            for (java.security.cert.Certificate certificate : certificates) {
                if (!(certificate instanceof X509Certificate)) return false;
                X509Certificate x509 = (X509Certificate) certificate;
                boolean[] keyUsage = x509.getKeyUsage();
                if (x509.getBasicConstraints() < 0 || keyUsage == null || keyUsage.length <= 5 || !keyUsage[5]) {
                    return false;
                }
                x509.checkValidity();
            }
            return true;
        } catch (Exception exception) {
            return false;
        }
    }

    private static boolean validProfile(String profile) {
        if (profile == null || profile.isEmpty()
                || profile.getBytes(StandardCharsets.UTF_8).length > MAX_PROFILE_BYTES) {
            return false;
        }
        String normalized = profile.toLowerCase(Locale.ROOT).replace("_", "").replace("-", "");
        for (String secretField : new String[]{"privatekey:", "peercredential:", "peersecret:", "subscriptiontoken:"}) {
            if (normalized.contains(secretField)) return false;
        }
        return true;
    }

    private static boolean validProfileDigest(String profile, String expectedDigest) {
        if (expectedDigest == null || !SHA256.matcher(expectedDigest).matches()) return false;
        try {
            byte[] actual = MessageDigest.getInstance("SHA-256").digest(profile.getBytes(StandardCharsets.UTF_8));
            byte[] expected = hexToBytes(expectedDigest);
            return MessageDigest.isEqual(actual, expected);
        } catch (Exception exception) {
            return false;
        }
    }

    private static byte[] hexToBytes(String value) {
        byte[] result = new byte[value.length() / 2];
        for (int index = 0; index < result.length; index++) {
            int offset = index * 2;
            result[index] = (byte) Integer.parseInt(value.substring(offset, offset + 2), 16);
        }
        return result;
    }
}
