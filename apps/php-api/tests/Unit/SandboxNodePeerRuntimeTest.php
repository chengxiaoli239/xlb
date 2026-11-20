<?php

namespace Tests\Unit;

use App\Services\NodePeerConnectionProof;
use App\Services\SandboxNodePeerRuntime;
use RuntimeException;
use Tests\TestCase;

class SandboxNodePeerRuntimeTest extends TestCase
{
    public function test_copied_subscription_and_peer_credentials_fail_simultaneously_and_after_disconnect(): void
    {
        $runtime = new SandboxNodePeerRuntime;
        $owner = $this->peerFixture('owner', 2);
        $otherDevice = $this->peerFixture('other', 2);
        $runtime->registerPeer(...$owner['registration']);
        $runtime->registerPeer(...$otherDevice['registration']);

        $ownerAttempt = $this->attempt($owner);
        $this->assertTrue($runtime->authorize($ownerAttempt));

        $copiedToOtherDevice = $this->attempt($otherDevice, [
            'peer_id' => $owner['peer']['id'],
            'subscription_id' => $owner['subscription']['id'],
            'subscription_generation' => $owner['subscription']['credential_generation'],
            'peer_generation' => $owner['peer']['generation'],
            'subscription_token' => $owner['subscription_token'],
            'peer_credential' => $owner['peer_credential'],
        ]);
        $this->assertFalse($runtime->authorize($copiedToOtherDevice));

        $runtime->disconnect($ownerAttempt['session_id']);
        $copiedToDifferentPeer = $this->attempt($otherDevice, [
            'subscription_id' => $owner['subscription']['id'],
            'subscription_generation' => $owner['subscription']['credential_generation'],
            'subscription_token' => $owner['subscription_token'],
            'peer_credential' => $owner['peer_credential'],
        ]);
        $this->assertFalse($runtime->authorize($copiedToDifferentPeer));

        $copiedAfterDisconnect = $this->attempt($otherDevice, [
            'subscription_id' => $owner['subscription']['id'],
            'subscription_generation' => $owner['subscription']['credential_generation'],
            'subscription_token' => $owner['subscription_token'],
            'peer_credential' => $owner['peer_credential'],
        ]);
        $this->assertFalse($runtime->authorize($copiedAfterDisconnect));
    }

    public function test_credential_cannot_be_presented_as_a_different_devices_peer(): void
    {
        $runtime = new SandboxNodePeerRuntime;
        $owner = $this->peerFixture('owner', 2);
        $otherDevice = $this->peerFixture('other', 2);
        $runtime->registerPeer(...$owner['registration']);
        $runtime->registerPeer(...$otherDevice['registration']);

        $attempt = $this->attempt($otherDevice, [
            'subscription_token' => $owner['subscription_token'],
            'peer_credential' => $owner['peer_credential'],
        ]);

        $this->assertFalse($runtime->authorize($attempt));
    }

    public function test_peer_and_node_session_capacity_cannot_be_oversold(): void
    {
        $runtime = new SandboxNodePeerRuntime;
        $first = $this->peerFixture('first', 1);
        $second = $this->peerFixture('second', 1);
        $runtime->registerPeer(...$first['registration']);
        $runtime->registerPeer(...$second['registration']);

        $firstAttempt = $this->attempt($first);
        $this->assertTrue($runtime->authorize($firstAttempt));
        $this->assertFalse($runtime->authorize($this->attempt($first)));
        $this->assertFalse($runtime->authorize($this->attempt($second)));

        $runtime->disconnect($firstAttempt['session_id']);
        $this->assertTrue($runtime->authorize($this->attempt($second)));
    }

    public function test_matching_revocation_receipt_disables_peer_and_preserves_final_counters(): void
    {
        $runtime = new SandboxNodePeerRuntime;
        $fixture = $this->peerFixture('revoked', 2);
        $runtime->registerPeer(...$fixture['registration']);
        $attempt = $this->attempt($fixture);
        $this->assertTrue($runtime->authorize($attempt));
        $requestId = 'revoke-'.$fixture['peer']['id'];
        $generation = $fixture['peer']['generation'] + 1;
        $runtime->requestRevocation($fixture['peer']['id'], $requestId, $generation);
        $runtime->requestRevocation($fixture['peer']['id'], $requestId, $generation);
        $this->assertFalse($runtime->authorize($this->attempt($fixture)));
        try {
            $runtime->requestRevocation($fixture['peer']['id'], 'different-request', $generation);
            $this->fail('A retry with a different request ID must be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $wrongReceipt = [
            'node_id' => 'node-other', 'peer_id' => $fixture['peer']['id'], 'request_id' => $requestId,
            'generation' => $generation, 'acknowledgement_id' => 'wrong-node', 'upload_bytes' => 30,
            'download_bytes' => 45, 'confirmation_status' => 'confirmed_test',
        ];
        try {
            $runtime->applyRevocationReceipt($fixture['peer']['id'], $requestId, $generation, $wrongReceipt);
            $this->fail('A receipt for another node must not revoke this peer.');
        } catch (RuntimeException) {
            $this->assertFalse($runtime->authorize($this->attempt($fixture)));
        }

        foreach ([
            [...$wrongReceipt, 'node_id' => $fixture['node']['id'], 'peer_id' => 'peer-other'],
            [...$wrongReceipt, 'node_id' => $fixture['node']['id'], 'request_id' => 'request-other'],
            [...$wrongReceipt, 'node_id' => $fixture['node']['id'], 'generation' => $generation + 1],
        ] as $invalidReceipt) {
            try {
                $runtime->applyRevocationReceipt($fixture['peer']['id'], $requestId, $generation, $invalidReceipt);
                $this->fail('A receipt with mismatched peer, request, or generation must be rejected.');
            } catch (RuntimeException) {
                $this->assertFalse($runtime->authorize($this->attempt($fixture)));
            }
        }

        $receipt = [...$wrongReceipt, 'node_id' => $fixture['node']['id'], 'acknowledgement_id' => 'valid-receipt'];
        $final = $runtime->applyRevocationReceipt($fixture['peer']['id'], $requestId, $generation, $receipt);

        $this->assertSame(30, $final['upload_bytes']);
        $this->assertSame(45, $final['download_bytes']);
        $this->assertFalse($runtime->authorize($this->attempt($fixture)));

        $stale = [...$receipt, 'upload_bytes' => 5, 'download_bytes' => 12];
        $this->assertSame($final, $runtime->applyRevocationReceipt($fixture['peer']['id'], $requestId, $generation, $stale));
        try {
            $runtime->applyRevocationReceipt($fixture['peer']['id'], $requestId, $generation, [...$receipt, 'acknowledgement_id' => 'conflicting-receipt']);
            $this->fail('A retry cannot replace the durable acknowledgement identity.');
        } catch (RuntimeException) {
            $this->assertSame($final, $runtime->applyRevocationReceipt($fixture['peer']['id'], $requestId, $generation, $stale));
        }
    }

    public function test_connection_proof_is_bound_to_peer_device_and_both_generations(): void
    {
        $runtime = new SandboxNodePeerRuntime;
        $fixture = $this->peerFixture('proof', 2);
        $runtime->registerPeer(...$fixture['registration']);

        $valid = $this->attempt($fixture);
        $this->assertTrue($runtime->authorize($valid));

        $staleGeneration = $this->attempt($fixture, ['subscription_generation' => 99]);
        $this->assertFalse($runtime->authorize($staleGeneration));
        $staleDeviceGeneration = $this->attempt($fixture, ['device_generation' => 99]);
        $this->assertFalse($runtime->authorize($staleDeviceGeneration));
        $this->assertFalse($runtime->authorize($valid));
        $runtime->disconnect($valid['session_id']);
        $this->assertFalse($runtime->authorize($valid));
    }

    public function test_expired_peer_cannot_open_a_session(): void
    {
        $runtime = new SandboxNodePeerRuntime;
        $fixture = $this->peerFixture('expired', 2);
        $fixture['peer']['expires_at'] = now()->subSecond()->toIso8601String();
        $fixture['registration'][2] = $fixture['peer'];
        $runtime->registerPeer(...$fixture['registration']);

        $this->assertFalse($runtime->authorize($this->attempt($fixture)));
    }

    /** @return array<string, mixed> */
    private function peerFixture(string $suffix, int $nodeCapacity): array
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($privateKey);
        $this->assertTrue(openssl_pkey_export($privateKey, $privateKeyPem));
        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);
        $publicKeyDer = base64_decode(preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $details['key']), true);
        $this->assertNotFalse($publicKeyDer);
        $publicKey = rtrim(strtr(base64_encode($publicKeyDer), '+/', '-_'), '=');
        $deviceId = 'device-'.$suffix;
        $peerId = 'peer-'.$suffix;
        $subscriptionId = 'subscription-'.$suffix;
        $node = ['id' => 'node-sandbox-1', 'is_test' => true, 'max_sessions' => $nodeCapacity];
        $device = [
            'id' => $deviceId, 'user_id' => 'user-'.$suffix, 'status' => 'registered', 'remove_requested_at' => null,
            'public_key' => $publicKey, 'public_key_fingerprint' => hash('sha256', $publicKeyDer), 'binding_generation' => 2,
        ];
        $peer = [
            'id' => $peerId, 'user_id' => $device['user_id'], 'node_id' => $node['id'], 'device_id' => $deviceId,
            'device_key_fingerprint' => $device['public_key_fingerprint'], 'device_binding_generation' => $device['binding_generation'],
            'generation' => 3, 'expires_at' => now()->addDay()->toIso8601String(),
            'status' => 'active', 'provision_confirmation_status' => 'confirmed_test',
            'upload_bytes' => 10, 'download_bytes' => 20,
        ];
        $subscriptionToken = 'subscription-secret-'.$suffix;
        $peerCredential = 'peer-secret-'.$suffix;
        $subscription = [
            'id' => $subscriptionId, 'user_id' => $device['user_id'], 'member_device_id' => $deviceId,
            'node_access_peer_id' => $peerId, 'node_access_peer_generation' => $peer['generation'],
            'member_device_generation' => $device['binding_generation'],
            'credential_generation' => 7, 'status' => 'active', 'token_hash' => hash('sha256', $subscriptionToken),
        ];

        return [
            'node' => $node,
            'device' => $device,
            'peer' => $peer,
            'subscription' => $subscription,
            'subscription_token' => $subscriptionToken,
            'peer_credential' => $peerCredential,
            'private_key' => $privateKey,
            'registration' => [$node, $subscription, $peer, $device, $subscriptionToken, $peerCredential],
        ];
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $overrides @return array<string, mixed> */
    private function attempt(array $fixture, array $overrides = []): array
    {
        $state = array_merge([
            'node_id' => $fixture['node']['id'],
            'peer_id' => $fixture['peer']['id'],
            'device_id' => $fixture['device']['id'],
            'subscription_id' => $fixture['subscription']['id'],
            'subscription_generation' => $fixture['subscription']['credential_generation'],
            'peer_generation' => $fixture['peer']['generation'],
            'device_generation' => $fixture['device']['binding_generation'],
            'challenge_id' => 'challenge-'.bin2hex(random_bytes(8)),
            'session_id' => 'session-'.bin2hex(random_bytes(8)),
            'subscription_token' => $fixture['subscription_token'],
            'peer_credential' => $fixture['peer_credential'],
        ], $overrides);
        $message = NodePeerConnectionProof::message(
            $state['node_id'],
            $state['peer_id'],
            $state['device_id'],
            $state['subscription_id'],
            $state['subscription_generation'],
            $state['peer_generation'],
            $state['device_generation'],
            $state['challenge_id'],
        );
        $this->assertTrue(openssl_sign($message, $signature, $fixture['private_key'], OPENSSL_ALGO_SHA256));
        $state['signature'] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $state;
    }
}
