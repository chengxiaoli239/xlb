<?php

namespace Tests\Unit;

use App\Services\HttpNodePeerSubscriptionBindingDriver;
use App\Services\NodeAccessAgentHttpClient;
use Mockery;
use Tests\TestCase;

class HttpNodePeerSubscriptionBindingDriverTest extends TestCase
{
    public function test_binding_request_carries_device_peer_and_subscription_generations(): void
    {
        $node = (object) ['id' => 'node-1'];
        $peer = (object) [
            'id' => 'peer-1',
            'generation' => 7,
            'device_binding_generation' => 4,
        ];
        $subscription = (object) [
            'id' => 'subscription-1',
            'credential_generation' => 3,
        ];
        $client = Mockery::mock(NodeAccessAgentHttpClient::class);
        $client->shouldReceive('post')->once()->with(
            $node,
            'bind-subscription',
            Mockery::on(function (array $payload): bool {
                $this->assertSame('node-1', $payload['node_id']);
                $this->assertSame('peer-1', $payload['peer_id']);
                $this->assertSame('stable-request', $payload['request_id']);
                $this->assertSame('subscription-1', $payload['subscription_id']);
                $this->assertSame('new-token-secret', $payload['subscription_token']);
                $this->assertSame(3, $payload['subscription_generation']);
                $this->assertSame(7, $payload['peer_generation']);
                $this->assertSame(4, $payload['device_generation']);

                return true;
            }),
        )->andReturn(['accepted' => true]);

        $result = (new HttpNodePeerSubscriptionBindingDriver($client))->bind(
            $node,
            $peer,
            $subscription,
            'stable-request',
            'new-token-secret',
        );

        $this->assertSame(['accepted' => true], $result);
    }
}
