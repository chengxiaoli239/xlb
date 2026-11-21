<?php

namespace App\Console\Commands;

use App\Payments\SandboxPaymentRefundWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessSandboxPaymentRefunds extends Command
{
    protected $signature = 'payments:process-sandbox-refunds {--limit=50}';

    protected $description = 'Process local/testing provider refund compensation outbox';

    public function handle(SandboxPaymentRefundWorker $worker): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Sandbox payment refund is disabled outside local and testing.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('Limit must be an integer between 1 and 500.');

            return self::INVALID;
        }

        $orderIds = DB::table('provider_payment_compensations')->where('status', 'pending')
            ->where('available_at', '<=', now()->utc()->toIso8601String())
            ->orderBy('available_at')->limit($limit)->pluck('merchant_order_id');
        $succeeded = 0;
        foreach ($orderIds as $orderId) {
            if ($worker->process((string) $orderId)) {
                $succeeded++;
            }
        }

        $this->info(sprintf('Processed %d sandbox payment refund item(s); %d succeeded.', $orderIds->count(), $succeeded));

        return self::SUCCESS;
    }
}
