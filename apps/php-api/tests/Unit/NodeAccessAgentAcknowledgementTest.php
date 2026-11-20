<?php

namespace Tests\Unit;

use App\Services\NodeAccessAgentAcknowledgement;
use RuntimeException;
use Tests\TestCase;

class NodeAccessAgentAcknowledgementTest extends TestCase
{
    public function test_provision_acknowledgement_must_match_node_peer_request_and_generation(): void
    {
        $result = [
            'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
            'generation' => 1, 'acknowledgement_id' => 'ack-1', 'profile' => 'member-profile',
            'confirmation_status' => 'confirmed_test',
        ];

        $validated = NodeAccessAgentAcknowledgement::provision(
            $result,
            (object) ['id' => 'node-1'],
            (object) ['id' => 'peer-1'],
            'request-1',
            1,
        );

        $this->assertSame('member-profile', $validated['profile']);
        $this->assertSame('confirmed_test', $validated['confirmation_status']);
    }

    public function test_provision_acknowledgement_rejects_a_wrong_node_peer_request_or_generation(): void
    {
        $valid = [
            'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
            'generation' => 1, 'acknowledgement_id' => 'ack-1', 'profile' => 'member-profile',
            'confirmation_status' => 'confirmed_test',
        ];

        foreach ([
            [...$valid, 'node_id' => 'node-other'],
            [...$valid, 'peer_id' => 'peer-other'],
            [...$valid, 'request_id' => 'request-other'],
            [...$valid, 'generation' => 2],
        ] as $result) {
            try {
                NodeAccessAgentAcknowledgement::provision(
                    $result,
                    (object) ['id' => 'node-1'],
                    (object) ['id' => 'peer-1'],
                    'request-1',
                    1,
                );
                $this->fail('Acknowledgement identity mismatch must be rejected.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_provision_acknowledgement_without_a_member_profile_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);

        NodeAccessAgentAcknowledgement::provision(
            [
                'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
                'generation' => 1, 'acknowledgement_id' => 'ack-1', 'confirmation_status' => 'confirmed_test',
            ],
            (object) ['id' => 'node-1'],
            (object) ['id' => 'peer-1'],
            'request-1',
            1,
        );
    }

    public function test_production_provisioning_receipt_requires_a_typed_renderer_artifact(): void
    {
        $this->expectException(RuntimeException::class);

        NodeAccessAgentAcknowledgement::provision(
            [
                'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
                'generation' => 1, 'acknowledgement_id' => 'ack-1', 'profile' => 'legacy-untyped-profile',
                'confirmation_status' => 'confirmed',
            ],
            (object) ['id' => 'node-1'],
            (object) [
                'id' => 'peer-1', 'entitlement_id' => 'entitlement-1',
                'device_key_fingerprint' => str_repeat('a', 64), 'device_binding_generation' => 1,
            ],
            'request-1',
            1,
        );
    }

    public function test_production_profile_artifact_rejects_wrong_device_binding(): void
    {
        $content = 'fixture-only-invalid-artifact';
        $this->expectException(RuntimeException::class);

        NodeAccessAgentAcknowledgement::provision(
            [
                'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
                'generation' => 1, 'acknowledgement_id' => 'ack-1', 'confirmation_status' => 'confirmed',
                'profile_artifact' => [
                    'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
                    'entitlement_id' => 'entitlement-1',
                    'device_key_fingerprint' => str_repeat('b', 64), 'device_binding_generation' => 1,
                    'generation' => 1, 'format' => 'mihomo-yaml', 'renderer_id' => 'xlb-mihomo-bridge-v1',
                    'renderer_version' => '1.0.0', 'sha256' => hash('sha256', $content), 'content' => $content,
                    'bridge_protocol' => 'xlb-proof-v1', 'connectable' => true,
                ],
            ],
            (object) ['id' => 'node-1'],
            (object) ['id' => 'peer-1', 'device_key_fingerprint' => str_repeat('a', 64), 'device_binding_generation' => 1],
            'request-1',
            1,
        );
    }

    public function test_production_profile_artifact_accepts_the_shared_renderer_contract(): void
    {
        $content = 'profile-renderer-contract-test-payload';
        $fingerprint = str_repeat('a', 64);
        $result = [
            'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
            'generation' => 2, 'acknowledgement_id' => 'ack-1', 'confirmation_status' => 'confirmed',
            'profile_artifact' => [
                'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
                'entitlement_id' => 'entitlement-1',
                'device_id' => 'device-1', 'device_key_fingerprint' => $fingerprint,
                'generation' => 2, 'device_binding_generation' => 3,
                'format' => 'mihomo-yaml', 'renderer_id' => 'xlb-mihomo-bridge-v1',
                'renderer_version' => '1.2.3', 'sha256' => hash('sha256', $content),
                'bridge_protocol' => 'xlb-proof-v1', 'connectable' => true, 'content' => $content,
            ],
        ];

        $validated = NodeAccessAgentAcknowledgement::provision(
            $result,
            (object) ['id' => 'node-1'],
            (object) [
                'id' => 'peer-1', 'device_id' => 'device-1', 'entitlement_id' => 'entitlement-1',
                'device_key_fingerprint' => $fingerprint, 'device_binding_generation' => 3,
            ],
            'request-1',
            2,
        );

        $this->assertSame($content, $validated['profile']);
        $this->assertSame('mihomo-yaml', $validated['profile_format']);
        $this->assertSame('xlb-mihomo-bridge-v1', $validated['profile_renderer_id']);
        $this->assertSame(hash('sha256', $content), $validated['profile_sha256']);
    }

    public function test_revocation_acknowledgement_requires_matching_identity_and_final_counters(): void
    {
        $result = [
            'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-2',
            'generation' => 2, 'acknowledgement_id' => 'ack-2', 'upload_bytes' => 100,
            'download_bytes' => 200, 'confirmation_status' => 'confirmed_test',
        ];

        $validated = NodeAccessAgentAcknowledgement::revocation(
            $result,
            (object) ['id' => 'node-1'],
            (object) ['id' => 'peer-1'],
            'request-2',
            2,
        );

        $this->assertSame(100, $validated['upload_bytes']);
        $this->assertSame(200, $validated['download_bytes']);

        foreach ([
            [...$result, 'node_id' => 'node-other'],
            [...$result, 'peer_id' => 'peer-other'],
            [...$result, 'request_id' => 'request-other'],
            [...$result, 'generation' => 3],
            [...$result, 'upload_bytes' => -1],
            [...$result, 'download_bytes' => -1],
        ] as $invalidResult) {
            try {
                NodeAccessAgentAcknowledgement::revocation(
                    $invalidResult,
                    (object) ['id' => 'node-1'],
                    (object) ['id' => 'peer-1'],
                    'request-2',
                    2,
                );
                $this->fail('Invalid revocation acknowledgement must be rejected.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_subscription_binding_acknowledgement_must_match_node_peer_request_subscription_and_generation(): void
    {
        $result = [
            'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-3',
            'subscription_id' => 'subscription-1', 'generation' => 2,
            'acknowledgement_id' => 'ack-3', 'confirmation_status' => 'confirmed_test',
        ];

        $validated = NodeAccessAgentAcknowledgement::subscriptionBinding(
            $result,
            (object) ['id' => 'node-1'],
            (object) ['id' => 'peer-1'],
            'request-3',
            'subscription-1',
            2,
        );

        $this->assertSame('ack-3', $validated['acknowledgement_id']);
        $this->assertSame('confirmed_test', $validated['confirmation_status']);

        foreach ([
            [...$result, 'node_id' => 'node-other'],
            [...$result, 'peer_id' => 'peer-other'],
            [...$result, 'request_id' => 'request-other'],
            [...$result, 'subscription_id' => 'subscription-other'],
            [...$result, 'generation' => 3],
        ] as $invalidResult) {
            try {
                NodeAccessAgentAcknowledgement::subscriptionBinding(
                    $invalidResult,
                    (object) ['id' => 'node-1'],
                    (object) ['id' => 'peer-1'],
                    'request-3',
                    'subscription-1',
                    2,
                );
                $this->fail('Subscription acknowledgement identity mismatch must be rejected.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }
}
