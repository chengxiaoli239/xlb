<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use RuntimeException;

final class SandboxNodePeerRuntime
{
    /** @var array<string, array<string, mixed>> */
    private array $peers = [];

    /** @var array<string, array{max_sessions:int}> */
    private array $nodes = [];

    /** @var array<string, string> session ID to peer ID */
    private array $sessions = [];

    /** @var array<string, true> */
    private array $usedChallenges = [];

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $subscription
     * @param  array<string, mixed>  $peer
     * @param  array<string, mixed>  $device
     */
    public function registerPeer(
        array $node,
        array $subscription,
        array $peer,
        array $device,
        string $subscriptionToken,
        string $peerCredential,
    ): void {
        if (! app()->environment(['local', 'testing']) || ($node['is_test'] ?? false) !== true) {
            throw new RuntimeException('Sandbox peer runtime only accepts local test nodes.');
        }

        $nodeId = (string) ($node['id'] ?? '');
        $peerId = (string) ($peer['id'] ?? '');
        $maxSessions = $node['max_sessions'] ?? null;
        if ($nodeId === '' || $peerId === '' || ! is_int($maxSessions) || $maxSessions < 1
            || ! is_string($subscription['id'] ?? null) || ! is_string($device['id'] ?? null)
            || ! is_string($device['public_key'] ?? null) || ! is_string($device['public_key_fingerprint'] ?? null)
            || ! is_string($subscription['token_hash'] ?? null)
            || ! $this->sameId($subscription['user_id'] ?? null, $device['user_id'] ?? null)
            || ! $this->sameId($subscription['member_device_id'] ?? null, $device['id'] ?? null)
            || ! $this->sameId($subscription['node_access_peer_id'] ?? null, $peer['id'] ?? null)
            || ! $this->sameGeneration($subscription['node_access_peer_generation'] ?? null, $peer['generation'] ?? null)
            || ! $this->sameGeneration($subscription['member_device_generation'] ?? null, $device['binding_generation'] ?? null)
            || ! $this->sameId($peer['user_id'] ?? null, $device['user_id'] ?? null)
            || ! $this->sameId($peer['node_id'] ?? null, $node['id'] ?? null)
            || ! $this->sameId($peer['device_id'] ?? null, $device['id'] ?? null)
            || ! $this->sameGeneration($peer['device_binding_generation'] ?? null, $device['binding_generation'] ?? null)
            || ! $this->sameId($peer['device_key_fingerprint'] ?? null, $device['public_key_fingerprint'] ?? null)) {
            throw new RuntimeException('Sandbox peer registration is invalid.');
        }

        if (isset($this->nodes[$nodeId]) && $this->nodes[$nodeId]['max_sessions'] !== $maxSessions) {
            throw new RuntimeException('Sandbox node capacity cannot change after registration.');
        }

        $this->nodes[$nodeId] = ['max_sessions' => $maxSessions];
        $this->peers[$peerId] = [
            'node' => $node,
            'subscription' => [...$subscription, 'token_hash' => hash('sha256', $subscriptionToken)],
            'peer' => [...$peer, 'credential_hash' => hash('sha256', $peerCredential)],
            'device' => $device,
            'revocation_request_id' => null,
            'revocation_generation' => null,
            'revocation_acknowledgement_id' => null,
        ];
    }

    /** @param array<string, mixed> $attempt */
    public function authorize(array $attempt): bool
    {
        $peerId = $attempt['peer_id'] ?? null;
        $sessionId = $attempt['session_id'] ?? null;
        $challengeId = $attempt['challenge_id'] ?? null;
        if (! is_string($peerId) || ! is_string($sessionId) || $sessionId === ''
            || ! is_string($challengeId) || $challengeId === '' || isset($this->sessions[$sessionId])
            || isset($this->usedChallenges[$challengeId]) || ! isset($this->peers[$peerId])) {
            return false;
        }

        $registered = $this->peers[$peerId];
        $node = $registered['node'];
        $subscription = $registered['subscription'];
        $peer = $registered['peer'];
        $device = $registered['device'];

        if (($peer['status'] ?? null) !== 'active' || ($peer['provision_confirmation_status'] ?? null) !== 'confirmed_test'
            || ($subscription['status'] ?? null) !== 'active'
            || ($device['status'] ?? null) !== 'registered' || ($device['remove_requested_at'] ?? null) !== null
            || ! $this->notExpired($peer['expires_at'] ?? null)
            || (($subscription['expires_at'] ?? null) !== null && ! $this->notExpired($subscription['expires_at']))
            || ! $this->sameId($attempt['node_id'] ?? null, $node['id'] ?? null)
            || ! $this->sameId($attempt['peer_id'] ?? null, $peer['id'] ?? null)
            || ! $this->sameId($attempt['device_id'] ?? null, $device['id'] ?? null)
            || ! $this->sameId($attempt['subscription_id'] ?? null, $subscription['id'] ?? null)
            || ! $this->sameId($peer['node_id'] ?? null, $node['id'] ?? null)
            || ! $this->sameId($peer['device_id'] ?? null, $device['id'] ?? null)
            || ! $this->sameId($peer['user_id'] ?? null, $device['user_id'] ?? null)
            || ! $this->sameId($subscription['user_id'] ?? null, $device['user_id'] ?? null)
            || ! $this->sameId($subscription['member_device_id'] ?? null, $device['id'] ?? null)
            || ! $this->sameId($subscription['node_access_peer_id'] ?? null, $peer['id'] ?? null)
            || ! $this->sameGeneration($attempt['subscription_generation'] ?? null, $subscription['credential_generation'] ?? null)
            || ! $this->sameGeneration($attempt['peer_generation'] ?? null, $peer['generation'] ?? null)
            || ! $this->sameGeneration($attempt['device_generation'] ?? null, $device['binding_generation'] ?? null)
            || ! $this->sameGeneration($subscription['node_access_peer_generation'] ?? null, $peer['generation'] ?? null)
            || ! $this->sameGeneration($subscription['member_device_generation'] ?? null, $device['binding_generation'] ?? null)
            || ! $this->sameGeneration($peer['device_binding_generation'] ?? null, $device['binding_generation'] ?? null)
            || ! is_string($attempt['subscription_token'] ?? null)
            || ! is_string($attempt['peer_credential'] ?? null)
            || ! hash_equals($subscription['token_hash'], hash('sha256', $attempt['subscription_token']))
            || ! hash_equals($peer['credential_hash'], hash('sha256', $attempt['peer_credential']))
            || ! hash_equals($device['public_key_fingerprint'], (string) ($peer['device_key_fingerprint'] ?? ''))
            || ! hash_equals($device['public_key_fingerprint'], hash('sha256', $this->decodePublicKey($device['public_key']) ?? ''))
            || ! is_string($attempt['signature'] ?? null)) {
            return false;
        }

        $message = NodePeerConnectionProof::message(
            (string) $node['id'],
            (string) $peer['id'],
            (string) $device['id'],
            (string) $subscription['id'],
            (int) $subscription['credential_generation'],
            (int) $peer['generation'],
            (int) $device['binding_generation'],
            $challengeId,
        );
        if (! NodePeerConnectionProof::verify($device['public_key'], $message, $attempt['signature'])) {
            return false;
        }

        foreach ($this->sessions as $activePeerId) {
            if ($activePeerId === $peerId) {
                return false;
            }
        }
        $activeOnNode = count(array_filter($this->sessions, fn (string $activePeerId): bool => ($this->peers[$activePeerId]['node']['id'] ?? null) === $node['id']
        ));
        if ($activeOnNode >= $this->nodes[(string) $node['id']]['max_sessions']) {
            return false;
        }

        $this->usedChallenges[$challengeId] = true;
        $this->sessions[$sessionId] = $peerId;

        return true;
    }

    public function disconnect(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }

    public function requestRevocation(string $peerId, string $requestId, int $generation): void
    {
        $registered = $this->peers[$peerId] ?? null;
        if (! $registered || $requestId === '') {
            throw new RuntimeException('Sandbox peer revocation request is invalid.');
        }

        if ($registered['peer']['status'] === 'revocation_pending') {
            if (is_string($registered['revocation_request_id'])
                && hash_equals($registered['revocation_request_id'], $requestId)
                && $registered['revocation_generation'] === $generation) {
                return;
            }
            throw new RuntimeException('Sandbox peer already has a different revocation request.');
        }

        if ($registered['peer']['status'] !== 'active' || $generation !== (int) $registered['peer']['generation'] + 1) {
            throw new RuntimeException('Sandbox peer revocation request is invalid.');
        }

        $this->peers[$peerId]['peer']['status'] = 'revocation_pending';
        $this->peers[$peerId]['peer']['generation'] = $generation;
        $this->peers[$peerId]['revocation_request_id'] = $requestId;
        $this->peers[$peerId]['revocation_generation'] = $generation;
    }

    /** @param array<string, mixed> $receipt
     * @return array{upload_bytes:int,download_bytes:int}
     */
    public function applyRevocationReceipt(string $peerId, string $requestId, int $generation, array $receipt): array
    {
        $registered = $this->peers[$peerId] ?? null;
        if (! $registered || ! in_array($registered['peer']['status'], ['revocation_pending', 'revoked'], true)
            || ! is_string($registered['revocation_request_id'])
            || ! hash_equals($registered['revocation_request_id'], $requestId)
            || $registered['revocation_generation'] !== $generation
            || ! $this->sameId($receipt['node_id'] ?? null, $registered['node']['id'] ?? null)
            || ! $this->sameId($receipt['peer_id'] ?? null, $peerId)
            || ! $this->sameId($receipt['request_id'] ?? null, $requestId)
            || ! $this->sameGeneration($receipt['generation'] ?? null, $generation)
            || ! is_string($receipt['acknowledgement_id'] ?? null) || $receipt['acknowledgement_id'] === ''
            || ($registered['revocation_acknowledgement_id'] !== null
                && ! hash_equals($registered['revocation_acknowledgement_id'], $receipt['acknowledgement_id']))
            || ($receipt['confirmation_status'] ?? null) !== 'confirmed_test'
            || ! is_int($receipt['upload_bytes'] ?? null) || $receipt['upload_bytes'] < 0
            || ! is_int($receipt['download_bytes'] ?? null) || $receipt['download_bytes'] < 0) {
            throw new RuntimeException('Sandbox node revocation receipt identity is invalid.');
        }

        $this->peers[$peerId]['peer']['upload_bytes'] = max((int) $registered['peer']['upload_bytes'], $receipt['upload_bytes']);
        $this->peers[$peerId]['peer']['download_bytes'] = max((int) $registered['peer']['download_bytes'], $receipt['download_bytes']);
        $this->peers[$peerId]['peer']['status'] = 'revoked';
        $this->peers[$peerId]['revocation_acknowledgement_id'] = $receipt['acknowledgement_id'];
        foreach ($this->sessions as $sessionId => $activePeerId) {
            if ($activePeerId === $peerId) {
                unset($this->sessions[$sessionId]);
            }
        }

        return [
            'upload_bytes' => $this->peers[$peerId]['peer']['upload_bytes'],
            'download_bytes' => $this->peers[$peerId]['peer']['download_bytes'],
        ];
    }

    private function sameId(mixed $actual, mixed $expected): bool
    {
        return is_string($actual) && is_string($expected) && hash_equals($expected, $actual);
    }

    private function sameGeneration(mixed $actual, mixed $expected): bool
    {
        return is_int($actual) && is_int($expected) && $actual === $expected;
    }

    private function notExpired(mixed $expiresAt): bool
    {
        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        try {
            return Carbon::parse($expiresAt)->greaterThan(now());
        } catch (\Throwable) {
            return false;
        }
    }

    private function decodePublicKey(string $publicKey): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $publicKey)) {
            return null;
        }

        $der = base64_decode(strtr($publicKey, '-_', '+/').str_repeat('=', (4 - strlen($publicKey) % 4) % 4), true);

        return $der === false ? null : $der;
    }
}
