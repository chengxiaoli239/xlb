<?php

namespace App\Services;

use RuntimeException;

final class NodeAccessAgentAcknowledgement
{
    /**
     * @return array{
     *     acknowledgement_id:string,
     *     profile:string,
     *     profile_format:string,
     *     profile_renderer_id:string,
     *     profile_renderer_version:string,
     *     profile_sha256:string,
     *     bridge_endpoint:string,
     *     bridge_server_ca_pem:string,
     *     confirmation_status:string
     * }
     */
    public static function provision(array $result, object $node, object $peer, string $requestId, int $generation): array
    {
        self::assertIdentity($result, $node, $peer, $requestId, $generation);
        $acknowledgementId = $result['acknowledgement_id'] ?? null;
        $confirmationStatus = $result['confirmation_status'] ?? null;
        self::assertAcknowledgement($acknowledgementId, $confirmationStatus);

        if ($confirmationStatus === 'confirmed_test') {
            $profile = $result['profile'] ?? null;
            if (! is_string($profile) || $profile === '' || strlen($profile) > 131072
                || ! app()->environment(['local', 'testing'])) {
                throw new RuntimeException('The isolated test provisioning acknowledgement is invalid.');
            }

            return [
                'acknowledgement_id' => $acknowledgementId,
                'profile' => $profile,
                'profile_format' => 'sandbox-only',
                'profile_renderer_id' => 'isolated-test-agent',
                'profile_renderer_version' => '0',
                'profile_sha256' => hash('sha256', $profile),
                'confirmation_status' => $confirmationStatus,
            ];
        }

        $artifact = self::profileArtifact($result['profile_artifact'] ?? null, $node, $peer, $requestId, $generation);

        return [
            'acknowledgement_id' => $acknowledgementId,
            'profile' => $artifact['content'],
            'profile_format' => $artifact['format'],
            'profile_renderer_id' => $artifact['renderer_id'],
            'profile_renderer_version' => $artifact['renderer_version'],
            'profile_sha256' => $artifact['sha256'],
            'bridge_endpoint' => $artifact['bridge_endpoint'],
            'bridge_server_ca_pem' => $artifact['bridge_server_ca_pem'],
            'confirmation_status' => $confirmationStatus,
        ];
    }

    /**
     * @return array{format:string,renderer_id:string,renderer_version:string,sha256:string,content:string,bridge_endpoint:string,bridge_server_ca_pem:string}
     */
    private static function profileArtifact(
        mixed $artifact,
        object $node,
        object $peer,
        string $requestId,
        int $generation,
    ): array {
        if (! is_array($artifact)) {
            throw new RuntimeException('A production provisioning receipt must include a rendered profile artifact.');
        }

        foreach ([
            'node_id' => (string) $node->id,
            'peer_id' => (string) $peer->id,
            'entitlement_id' => (string) ($peer->entitlement_id ?? ''),
            'request_id' => $requestId,
            'device_id' => (string) ($peer->device_id ?? ''),
            'device_key_fingerprint' => (string) ($peer->device_key_fingerprint ?? ''),
        ] as $field => $expected) {
            if (! is_string($artifact[$field] ?? null) || ! hash_equals($expected, $artifact[$field])) {
                throw new RuntimeException('The rendered profile identity is invalid.');
            }
        }
        if (($artifact['generation'] ?? null) !== $generation
            || ($artifact['device_binding_generation'] ?? null) !== (int) $peer->device_binding_generation) {
            throw new RuntimeException('The rendered profile generation is invalid.');
        }

        $format = $artifact['format'] ?? null;
        $rendererId = $artifact['renderer_id'] ?? null;
        $rendererVersion = $artifact['renderer_version'] ?? null;
        $content = $artifact['content'] ?? null;
        $sha256 = $artifact['sha256'] ?? null;
        $bridgeEndpoint = $artifact['bridge_endpoint'] ?? null;
        $bridgeServerCaPem = $artifact['bridge_server_ca_pem'] ?? null;
        $rendererFormats = [
            'xlb-mihomo-bridge-v1' => 'mihomo-yaml',
            'xlb-native-bridge-v1' => 'xlb-client-manifest-v1',
        ];
        if (! is_string($rendererId) || ! is_string($format) || ($rendererFormats[$rendererId] ?? null) !== $format
            || ! is_string($rendererVersion) || ! preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $rendererVersion)
            || ! is_string($content) || $content === '' || strlen($content) > 131072
            || ! is_string($sha256) || ! preg_match('/\A[a-f0-9]{64}\z/', $sha256)
            || ! hash_equals($sha256, hash('sha256', $content))
            || ($artifact['bridge_protocol'] ?? null) !== 'xlb-proof-v1'
            || ($artifact['connectable'] ?? null) !== true
            || ! self::isApprovedMemberBridgeDescriptor($bridgeEndpoint, $bridgeServerCaPem, $node)
            || self::containsClientSecret($content)) {
            throw new RuntimeException('The production profile artifact does not satisfy the renderer contract.');
        }

        return [
            'format' => $format,
            'renderer_id' => $rendererId,
            'renderer_version' => $rendererVersion,
            'sha256' => $sha256,
            'content' => $content,
            'bridge_endpoint' => $bridgeEndpoint,
            'bridge_server_ca_pem' => $bridgeServerCaPem,
        ];
    }

    public static function isApprovedMemberBridgeDescriptor(mixed $endpoint, mixed $caBundle, object $node): bool
    {
        if (! is_string($endpoint) || strlen($endpoint) > 2048 || ! is_string($caBundle)
            || $caBundle === '' || strlen($caBundle) > 65536) {
            return false;
        }
        $endpointParts = parse_url($endpoint);
        $nodeAgentParts = parse_url((string) ($node->agent_endpoint ?? ''));
        if (! is_array($endpointParts) || strtolower((string) ($endpointParts['scheme'] ?? '')) !== 'https'
            || empty($endpointParts['host']) || isset($endpointParts['user']) || isset($endpointParts['pass'])
            || ! in_array((string) ($endpointParts['path'] ?? ''), ['', '/'], true)
            || isset($endpointParts['query']) || isset($endpointParts['fragment'])
            || ! is_array($nodeAgentParts) || strtolower((string) ($nodeAgentParts['scheme'] ?? '')) !== 'https'
            || empty($nodeAgentParts['host']) || ! hash_equals(
                strtolower((string) $nodeAgentParts['host']), strtolower((string) $endpointParts['host']),
            )) {
            return false;
        }
        $port = $endpointParts['port'] ?? null;
        if ($port !== null && (! is_int($port) || $port < 1 || $port > 65535)) {
            return false;
        }

        $approvedBundlePath = config('node_access.member_bridge.ca_bundle');
        if (! is_string($approvedBundlePath) || $approvedBundlePath === '' || ! is_file($approvedBundlePath)
            || ! is_readable($approvedBundlePath)) {
            return false;
        }
        $approvedBundle = file_get_contents($approvedBundlePath);
        if (! is_string($approvedBundle)) {
            return false;
        }
        $approvedFingerprints = self::caFingerprints($approvedBundle);
        $descriptorFingerprints = self::caFingerprints($caBundle);
        if ($approvedFingerprints === [] || $descriptorFingerprints === []) {
            return false;
        }
        foreach ($descriptorFingerprints as $fingerprint) {
            if (! in_array($fingerprint, $approvedFingerprints, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function caFingerprints(string $bundle): array
    {
        if ($bundle === '' || strlen($bundle) > 65536
            || preg_match_all('/-----BEGIN CERTIFICATE-----\s*[A-Za-z0-9+\/=\r\n]+?-----END CERTIFICATE-----/', $bundle, $matches) < 1) {
            return [];
        }
        $remainder = preg_replace('/-----BEGIN CERTIFICATE-----\s*[A-Za-z0-9+\/=\r\n]+?-----END CERTIFICATE-----/', '', $bundle);
        if (! is_string($remainder) || trim($remainder) !== '' || count($matches[0]) > 8) {
            return [];
        }
        $fingerprints = [];
        foreach ($matches[0] as $pem) {
            $certificate = openssl_x509_read($pem);
            if ($certificate === false) {
                return [];
            }
            $details = openssl_x509_parse($certificate, false);
            $extensions = is_array($details) ? ($details['extensions'] ?? []) : [];
            $basicConstraints = $extensions['basicConstraints'] ?? '';
            $keyUsage = $extensions['keyUsage'] ?? '';
            $validFrom = is_array($details) ? ($details['validFrom_time_t'] ?? 0) : 0;
            $validTo = is_array($details) ? ($details['validTo_time_t'] ?? 0) : 0;
            if (! is_string($basicConstraints) || ! str_contains($basicConstraints, 'CA:TRUE')
                || ! is_string($keyUsage) || ! str_contains($keyUsage, 'Certificate Sign')
                || ! is_int($validFrom) || $validFrom > time()
                || ! is_int($validTo) || $validTo <= time()) {
                return [];
            }
            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
            if (! is_string($fingerprint) || $fingerprint === '') {
                return [];
            }
            $fingerprints[] = strtolower(str_replace(':', '', $fingerprint));
        }

        return $fingerprints;
    }

    private static function containsClientSecret(string $content): bool
    {
        $normalized = strtolower($content);
        foreach (['private_key', 'peer_credential', 'peer_secret', 'subscription_token'] as $secretField) {
            if (str_contains($normalized, $secretField)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{acknowledgement_id:string, upload_bytes:int, download_bytes:int, confirmation_status:string} */
    public static function revocation(array $result, object $node, object $peer, string $requestId, int $generation): array
    {
        self::assertIdentity($result, $node, $peer, $requestId, $generation);
        $acknowledgementId = $result['acknowledgement_id'] ?? null;
        $uploadBytes = $result['upload_bytes'] ?? null;
        $downloadBytes = $result['download_bytes'] ?? null;
        $confirmationStatus = $result['confirmation_status'] ?? null;
        self::assertAcknowledgement($acknowledgementId, $confirmationStatus);

        if (! is_int($uploadBytes) || $uploadBytes < 0 || ! is_int($downloadBytes) || $downloadBytes < 0) {
            throw new RuntimeException('The node revocation acknowledgement is invalid.');
        }

        return [
            'acknowledgement_id' => $acknowledgementId,
            'upload_bytes' => $uploadBytes,
            'download_bytes' => $downloadBytes,
            'confirmation_status' => $confirmationStatus,
        ];
    }

    /** @return array{acknowledgement_id:string, confirmation_status:string} */
    public static function subscriptionBinding(
        array $result,
        object $node,
        object $peer,
        string $requestId,
        string $subscriptionId,
        int $generation,
    ): array {
        foreach ([
            'node_id' => (string) $node->id,
            'peer_id' => (string) $peer->id,
            'request_id' => $requestId,
            'subscription_id' => $subscriptionId,
        ] as $field => $expected) {
            if (! is_string($result[$field] ?? null) || ! hash_equals($expected, $result[$field])) {
                throw new RuntimeException('The node subscription binding acknowledgement identity is invalid.');
            }
        }

        if (! is_int($result['generation'] ?? null) || $result['generation'] !== $generation) {
            throw new RuntimeException('The node subscription binding acknowledgement generation is invalid.');
        }

        $acknowledgementId = $result['acknowledgement_id'] ?? null;
        $confirmationStatus = $result['confirmation_status'] ?? null;
        self::assertAcknowledgement($acknowledgementId, $confirmationStatus);

        return [
            'acknowledgement_id' => $acknowledgementId,
            'confirmation_status' => $confirmationStatus,
        ];
    }

    /** @return array{upload_bytes:int, download_bytes:int} */
    public static function usage(array $result, object $node, object $peer): array
    {
        foreach (['node_id' => (string) $node->id, 'peer_id' => (string) $peer->id] as $field => $expected) {
            if (! is_string($result[$field] ?? null) || ! hash_equals($expected, $result[$field])) {
                throw new RuntimeException('The node usage snapshot identity is invalid.');
            }
        }
        if (! is_int($result['generation'] ?? null) || $result['generation'] !== (int) $peer->generation) {
            throw new RuntimeException('The node usage snapshot generation is invalid.');
        }
        $uploadBytes = $result['upload_bytes'] ?? null;
        $downloadBytes = $result['download_bytes'] ?? null;
        if (($result['status'] ?? null) !== 'active'
            || ! is_int($uploadBytes) || $uploadBytes < (int) $peer->upload_bytes
            || ! is_int($downloadBytes) || $downloadBytes < (int) $peer->download_bytes) {
            throw new RuntimeException('The node usage snapshot is invalid or moved backwards.');
        }

        return ['upload_bytes' => $uploadBytes, 'download_bytes' => $downloadBytes];
    }

    private static function assertIdentity(array $result, object $node, object $peer, string $requestId, int $generation): void
    {
        foreach ([
            'node_id' => (string) $node->id,
            'peer_id' => (string) $peer->id,
            'request_id' => $requestId,
        ] as $field => $expected) {
            if (! is_string($result[$field] ?? null) || ! hash_equals($expected, $result[$field])) {
                throw new RuntimeException('The node acknowledgement identity is invalid.');
            }
        }

        if (! is_int($result['generation'] ?? null) || $result['generation'] !== $generation) {
            throw new RuntimeException('The node acknowledgement generation is invalid.');
        }
    }

    private static function assertAcknowledgement(mixed $acknowledgementId, mixed $confirmationStatus): void
    {
        if (! is_string($acknowledgementId) || $acknowledgementId === '' || strlen($acknowledgementId) > 255
            || ! in_array($confirmationStatus, ['confirmed', 'confirmed_test'], true)
            || ($confirmationStatus === 'confirmed_test' && ! app()->environment(['local', 'testing']))) {
            throw new RuntimeException('The node acknowledgement is invalid.');
        }
    }
}
