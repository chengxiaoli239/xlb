<?php

namespace Tests\Unit;

use App\Services\NodeAccessAgentAcknowledgement;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Tests\TestCase;

class NodeAccessAgentAcknowledgementTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (glob($directory.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }

        parent::tearDown();
    }

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
        $trust = $this->trustedBridgeTrustBundle();
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
                'bridge_endpoint' => 'https://node-agent.example.invalid:9444',
                'bridge_server_ca_pem' => $trust['pem'],
            ],
        ];
        $node = (object) [
            'id' => 'node-1', 'member_bridge_endpoint' => 'https://node-agent.example.invalid:9444',
            'member_bridge_ca_ciphertext' => Crypt::encryptString($trust['pem']),
        ];
        $this->assertTrue(NodeAccessAgentAcknowledgement::isValidMemberBridgeTrustBundle($trust['pem']));
        $this->assertTrue(NodeAccessAgentAcknowledgement::isApprovedMemberBridgeDescriptor(
            'https://node-agent.example.invalid:9444', $trust['pem'], $node,
        ));
        $validated = NodeAccessAgentAcknowledgement::provision(
            $result,
            $node,
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
        $this->assertSame($result['profile_artifact']['bridge_endpoint'], $validated['bridge_endpoint']);
        $this->assertSame($trust['pem'], $validated['bridge_server_ca_pem']);
    }

    public function test_production_profile_artifact_rejects_unconfigured_endpoint_and_ca(): void
    {
        $trust = $this->trustedBridgeTrustBundle();
        $content = 'profile-renderer-contract-test-payload';
        $artifact = [
            'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
            'entitlement_id' => 'entitlement-1', 'device_id' => 'device-1',
            'device_key_fingerprint' => str_repeat('a', 64), 'generation' => 2,
            'device_binding_generation' => 3, 'format' => 'mihomo-yaml',
            'renderer_id' => 'xlb-mihomo-bridge-v1', 'renderer_version' => '1.2.3',
            'sha256' => hash('sha256', $content), 'bridge_protocol' => 'xlb-proof-v1',
            'connectable' => true, 'content' => $content,
            'bridge_endpoint' => 'https://attacker.invalid:9444', 'bridge_server_ca_pem' => $trust['pem'],
        ];

        try {
            NodeAccessAgentAcknowledgement::provision(
                [
                    'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
                    'generation' => 2, 'acknowledgement_id' => 'ack-1', 'confirmation_status' => 'confirmed',
                    'profile_artifact' => $artifact,
                ],
                (object) [
                    'id' => 'node-1', 'member_bridge_endpoint' => 'https://node-agent.example.invalid:9444',
                    'member_bridge_ca_ciphertext' => Crypt::encryptString($trust['pem']),
                ],
                (object) [
                    'id' => 'peer-1', 'device_id' => 'device-1', 'entitlement_id' => 'entitlement-1',
                    'device_key_fingerprint' => str_repeat('a', 64), 'device_binding_generation' => 3,
                ],
                'request-1',
                2,
            );
            $this->fail('A bridge endpoint outside the administrator-controlled node host must be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $artifact['bridge_endpoint'] = 'https://node-agent.example.invalid:9445';
        try {
            NodeAccessAgentAcknowledgement::provision(
                [
                    'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
                    'generation' => 2, 'acknowledgement_id' => 'ack-1', 'confirmation_status' => 'confirmed',
                    'profile_artifact' => $artifact,
                ],
                (object) [
                    'id' => 'node-1', 'member_bridge_endpoint' => 'https://node-agent.example.invalid:9444',
                    'member_bridge_ca_ciphertext' => Crypt::encryptString($trust['pem']),
                ],
                (object) [
                    'id' => 'peer-1', 'device_id' => 'device-1', 'entitlement_id' => 'entitlement-1',
                    'device_key_fingerprint' => str_repeat('a', 64), 'device_binding_generation' => 3,
                ],
                'request-1',
                2,
            );
            $this->fail('A bridge endpoint with an unconfigured port must be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $artifact['bridge_endpoint'] = 'https://node-agent.example.invalid/v1/tunnel/connect';
        try {
            NodeAccessAgentAcknowledgement::provision(
                [
                    'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
                    'generation' => 2, 'acknowledgement_id' => 'ack-1', 'confirmation_status' => 'confirmed',
                    'profile_artifact' => $artifact,
                ],
                (object) [
                    'id' => 'node-1', 'member_bridge_endpoint' => 'https://node-agent.example.invalid:9444',
                    'member_bridge_ca_ciphertext' => Crypt::encryptString($trust['pem']),
                ],
                (object) [
                    'id' => 'peer-1', 'device_id' => 'device-1', 'entitlement_id' => 'entitlement-1',
                    'device_key_fingerprint' => str_repeat('a', 64), 'device_binding_generation' => 3,
                ],
                'request-1',
                2,
            );
            $this->fail('A path-bearing bridge endpoint must be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $unapprovedTrust = $this->trustedBridgeTrustBundle();
        $artifact['bridge_endpoint'] = 'https://node-agent.example.invalid:9444';
        $artifact['bridge_server_ca_pem'] = $unapprovedTrust['pem'];
        try {
            NodeAccessAgentAcknowledgement::provision(
                [
                    'node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1',
                    'generation' => 2, 'acknowledgement_id' => 'ack-1', 'confirmation_status' => 'confirmed',
                    'profile_artifact' => $artifact,
                ],
                (object) [
                    'id' => 'node-1', 'member_bridge_endpoint' => 'https://node-agent.example.invalid:9444',
                    'member_bridge_ca_ciphertext' => encrypt($trust['pem']),
                ],
                (object) [
                    'id' => 'peer-1', 'device_id' => 'device-1', 'entitlement_id' => 'entitlement-1',
                    'device_key_fingerprint' => str_repeat('a', 64), 'device_binding_generation' => 3,
                ],
                'request-1',
                2,
            );
            $this->fail('A CA outside the backend-approved node trust bundle must be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }

    /** @return array{pem:string,path:string} */
    private function trustedBridgeTrustBundle(): array
    {
        $directory = sys_get_temp_dir().'/xlb-bridge-ca-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $this->temporaryDirectories[] = $directory;
        $configurationPath = $directory.'/openssl.cnf';
        $configuration = <<<'OPENSSL'
[req]
distinguished_name = distinguished_name
prompt = no

[distinguished_name]
CN = xlb member bridge test CA

[v3_ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
OPENSSL;
        $this->assertNotFalse(file_put_contents($configurationPath, $configuration));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => 'xlb member bridge test CA'], $key, [
            'config' => $configurationPath, 'digest_alg' => 'sha256',
        ]);
        $this->assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $key, 365, [
            'config' => $configurationPath, 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256',
        ]);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_x509_export($certificate, $pem));
        $path = $directory.'/ca.pem';
        $this->assertNotFalse(file_put_contents($path, $pem));

        return ['pem' => $pem, 'path' => $path];
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
