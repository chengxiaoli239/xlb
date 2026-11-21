<?php

namespace App\Payments;

use App\Services\SandboxRefundProcessor;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class SandboxPaymentRefundWorker
{
    public function __construct(
        private readonly PersistentPaymentEventLedger $ledger,
        private readonly SandboxRefundProcessor $refunds,
    ) {}

    public function process(string $merchantOrderId): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Sandbox payment refund is restricted to local and testing environments.');
        }

        $compensation = DB::table('provider_payment_compensations')->where('merchant_order_id', $merchantOrderId)->first();
        if (! $compensation) {
            throw new LogicException('Sandbox payment compensation is not registered.');
        }
        if ($compensation->status === 'completed') {
            return true;
        }

        try {
            $result = $this->refunds->refund(
                $merchantOrderId,
                'user-admin',
                $compensation->refund_event_id,
                'Sandbox provider refund event',
            );
            if ($result['status'] < 200 || $result['status'] >= 300
                || ($result['body']['data']['status'] ?? null) !== 'refunded_test') {
                $this->ledger->recordCompensationAttempt($merchantOrderId, false, 'sandbox_refund_not_completed');

                return false;
            }

            $this->ledger->recordCompensationAttempt($merchantOrderId, true);

            return true;
        } catch (Throwable) {
            $this->ledger->recordCompensationAttempt($merchantOrderId, false, 'sandbox_refund_failed');

            return false;
        }
    }
}
