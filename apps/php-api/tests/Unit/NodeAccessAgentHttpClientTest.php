<?php

namespace Tests\Unit;

use App\Services\NodeAccessAgentHttpClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NodeAccessAgentHttpClientTest extends TestCase
{
    /** @var list<string> */
    private array $credentialFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['client_certificate', 'client_key', 'ca_bundle'] as $name) {
            $path = tempnam(sys_get_temp_dir(), 'xlb-agent-test-');
            $this->assertNotFalse($path);
            file_put_contents($path, 'test credential placeholder');
            $this->credentialFiles[] = $path;
            config(['node_access.agent.'.$name => $path]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->credentialFiles as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_control_and_usage_responses_unwrap_the_agent_data_envelope(): void
    {
        Http::fake([
            'https://node-agent.example.test/v1/member-peers/ensure' => Http::response([
                'data' => ['node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1'],
            ]),
            'https://node-agent.example.test/v1/member-peers/peer-1/usage' => Http::response([
                'data' => ['node_id' => 'node-1', 'peer_id' => 'peer-1', 'generation' => 1, 'status' => 'active', 'upload_bytes' => 12, 'download_bytes' => 34],
            ]),
        ]);

        $client = new NodeAccessAgentHttpClient;
        $node = (object) ['agent_endpoint' => 'https://node-agent.example.test'];

        $this->assertSame(
            ['node_id' => 'node-1', 'peer_id' => 'peer-1', 'request_id' => 'request-1'],
            $client->post($node, 'ensure', []),
        );
        $this->assertSame(
            ['node_id' => 'node-1', 'peer_id' => 'peer-1', 'generation' => 1, 'status' => 'active', 'upload_bytes' => 12, 'download_bytes' => 34],
            $client->get($node, 'peer-1/usage'),
        );

        Http::assertSentCount(2);
    }

    public function test_agent_response_without_a_data_object_is_rejected(): void
    {
        Http::fake(['https://node-agent.example.test/*' => Http::response(['accepted' => true])]);
        $client = new NodeAccessAgentHttpClient;

        $this->expectException(RuntimeException::class);
        $client->post((object) ['agent_endpoint' => 'https://node-agent.example.test'], 'ensure', []);
    }

    public function test_client_certificate_symlink_is_rejected_before_http_request(): void
    {
        Http::fake();
        $certificatePath = $this->credentialFiles[0];
        $symlinkPath = tempnam(sys_get_temp_dir(), 'xlb-agent-link-');
        $this->assertNotFalse($symlinkPath);
        unlink($symlinkPath);
        $this->assertTrue(symlink($certificatePath, $symlinkPath));
        $this->credentialFiles[] = $symlinkPath;
        config(['node_access.agent.client_certificate' => $symlinkPath]);

        try {
            (new NodeAccessAgentHttpClient)->post(
                (object) ['agent_endpoint' => 'https://node-agent.example.test'], 'ensure', [],
            );
            $this->fail('Node Agent client accepted a symlinked certificate file.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Node agent mutual TLS credentials are unavailable.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_group_or_other_writable_private_key_is_rejected_before_http_request(): void
    {
        Http::fake();
        $this->assertTrue(chmod($this->credentialFiles[1], 0660));

        try {
            (new NodeAccessAgentHttpClient)->post(
                (object) ['agent_endpoint' => 'https://node-agent.example.test'], 'ensure', [],
            );
            $this->fail('Node Agent client accepted a group-writable private key.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Node agent mutual TLS credentials are unavailable.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_group_or_other_writable_ca_bundle_is_rejected_before_http_request(): void
    {
        Http::fake();
        $this->assertTrue(chmod($this->credentialFiles[2], 0664));

        try {
            (new NodeAccessAgentHttpClient)->post(
                (object) ['agent_endpoint' => 'https://node-agent.example.test'], 'ensure', [],
            );
            $this->fail('Node Agent client accepted a group-writable CA bundle.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Node agent mutual TLS credentials are unavailable.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
