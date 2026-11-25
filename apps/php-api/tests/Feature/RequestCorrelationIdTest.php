<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class RequestCorrelationIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_api_response_has_a_server_generated_request_id_and_ignores_client_value(): void
    {
        $clientRequestId = 'client-supplied-id';
        $response = $this->withHeader('X-Request-Id', $clientRequestId)->getJson('/api/health');

        $response->assertOk();
        $requestId = $response->headers->get('X-Request-Id');

        $this->assertIsString($requestId);
        $this->assertNotSame($clientRequestId, $requestId);
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
            $requestId,
        );
    }

    public function test_api_error_response_has_a_request_id(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'member@xlb.local',
            'password' => 'incorrect-password',
        ]);

        $response->assertUnauthorized();
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
            (string) $response->headers->get('X-Request-Id'),
        );
    }

    public function test_node_certificate_rejection_response_has_a_request_id(): void
    {
        $response = $this->postJson('/v1/node-quota/reservations', []);

        $response->assertUnauthorized()->assertJsonPath('error.code', 'node_certificate_invalid');
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
            (string) $response->headers->get('X-Request-Id'),
        );
    }

    public function test_request_log_context_is_removed_after_the_response(): void
    {
        Log::shouldReceive('shareContext')
            ->once()
            ->with(Mockery::on(static fn (array $context): bool => isset($context['request_id'])));
        Log::shouldReceive('withoutContext')->once()->with(['request_id']);

        $this->getJson('/api/health')->assertOk();
    }
}
