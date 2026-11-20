<?php

namespace App\Services;

use OpenSSLAsymmetricKey;

final class NodePeerConnectionProof
{
    public static function message(
        string $nodeId,
        string $peerId,
        string $deviceId,
        string $subscriptionId,
        int $subscriptionGeneration,
        int $peerGeneration,
        int $deviceGeneration,
        string $challengeId,
    ): string {
        return json_encode([
            'purpose' => 'xlb-node-peer-connect-v1',
            'node_id' => $nodeId,
            'peer_id' => $peerId,
            'device_id' => $deviceId,
            'subscription_id' => $subscriptionId,
            'subscription_generation' => $subscriptionGeneration,
            'peer_generation' => $peerGeneration,
            'device_generation' => $deviceGeneration,
            'challenge_id' => $challengeId,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function verify(string $publicKey, string $message, string $encodedSignature): bool
    {
        $der = self::decodeBase64Url($publicKey);
        $signature = self::decodeBase64Url($encodedSignature);
        if ($der === null || $signature === null || strlen($der) > 160 || strlen($signature) > 128) {
            return false;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        $details = $key instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($key) : false;

        return is_array($details) && $details['type'] === OPENSSL_KEYTYPE_EC
            && ($details['ec']['curve_name'] ?? null) === 'prime256v1'
            && openssl_verify($message, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function decodeBase64Url(string $value): ?string
    {
        if ($value === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
