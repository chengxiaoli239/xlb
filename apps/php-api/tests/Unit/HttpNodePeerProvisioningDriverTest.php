<?php

namespace Tests\Unit;

use App\Services\HttpNodePeerProvisioningDriver;
use App\Services\NodeAccessAgentHttpClient;
use Mockery;
use Tests\TestCase;

class HttpNodePeerProvisioningDriverTest extends TestCase
{
    public function test_provisioning_request_binds_peer_to_device_key_and_generation(): void
    {
        $node = (object) ['id' => 'node-1'];
        $peer = (object) [
            'id' => 'peer-1',
            'entitlement_id' => 'entitlement-1',
            'device_id' => 'device-1',
            'device_name' => 'Test device',
            'public_key' => 'base64url-p256-public-key',
            'device_key_fingerprint' => str_repeat('a', 64),
            'generation' => 7,
            'device_binding_generation' => 4,
            'expires_at' => '2026-12-01T00:00:00Z',
        ];
        $client = Mockery::mock(NodeAccessAgentHttpClient::class);
        $client->shouldReceive('post')->once()->with($node, 'ensure', Mockery::on(function (array $payload): bool {
            $this->assertSame('node-1', $payload['node_id']);
            $this->assertSame('peer-1', $payload['peer_id']);
            $this->assertSame('entitlement-1', $payload['entitlement_id']);
            $this->assertSame('device-1', $payload['device_id']);
            $this->assertSame('stable-request', $payload['request_id']);
            $this->assertSame(7, $payload['generation']);
            $this->assertSame(7, $payload['peer_generation']);
            $this->assertSame(4, $payload['device_generation']);
            $this->assertSame('base64url-p256-public-key', $payload['device_public_key']);
            $this->assertSame(str_repeat('a', 64), $payload['device_key_fingerprint']);
            $this->assertSame(4, $payload['device_binding_generation']);

            return true;
        }))->andReturn(['accepted' => true]);

        $result = (new HttpNodePeerProvisioningDriver($client))->provision($node, $peer, 'stable-request', 7, 'peer-secret');

        $this->assertSame(['accepted' => true], $result);
    }
}
