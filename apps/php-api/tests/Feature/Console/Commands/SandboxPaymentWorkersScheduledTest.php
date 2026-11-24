<?php

namespace Tests\Feature\Console\Commands;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SandboxPaymentWorkersScheduledTest extends TestCase
{
    public function test_local_scheduler_runs_sandbox_payment_outboxes(): void
    {
        $schedule = $this->scheduleList('local');

        $this->assertStringContainsString('payments:process-sandbox-fulfillments', $schedule);
        $this->assertStringContainsString('payments:process-sandbox-refunds', $schedule);
    }

    public function test_testing_scheduler_runs_sandbox_payment_outboxes(): void
    {
        $schedule = $this->scheduleList('testing');

        $this->assertStringContainsString('payments:process-sandbox-fulfillments', $schedule);
        $this->assertStringContainsString('payments:process-sandbox-refunds', $schedule);
    }

    public function test_production_scheduler_does_not_register_sandbox_payment_outboxes(): void
    {
        $schedule = $this->scheduleList('production');

        $this->assertStringNotContainsString('payments:process-sandbox-fulfillments', $schedule);
        $this->assertStringNotContainsString('payments:process-sandbox-refunds', $schedule);
    }

    private function scheduleList(string $environment): string
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'schedule:list'], base_path(), [
            'APP_ENV' => $environment,
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ]);

        $exitCode = $process->run();
        $this->assertSame(0, $exitCode, $process->getErrorOutput());

        return $process->getOutput();
    }
}
