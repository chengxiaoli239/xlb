<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class TestOrderLifecycle
{
    public const STATUSES = ['pending_test', 'cancelled_test', 'expired_test', 'paid_test'];

    public static function effectiveStatus(stdClass $order): string
    {
        return $order->status === 'pending_test' && $order->expires_at && Carbon::parse($order->expires_at)->lessThanOrEqualTo(now())
            ? 'expired_test' : $order->status;
    }

    public function record(string $orderId, ?string $actorId, ?string $from, string $to, string $at): void
    {
        DB::table('order_events')->insert(['id' => (string) Str::uuid(), 'order_id' => $orderId, 'actor_user_id' => $actorId, 'from_status' => $from, 'to_status' => $to, 'created_at' => $at]);
        if ($actorId !== null) {
            $action = $from === null ? 'order.created' : 'order.cancelled';
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_user_id' => $actorId, 'action' => $action, 'target_type' => 'order', 'target_id' => $orderId, 'created_at' => $at]);
        }
    }

    public function expireDue(): int
    {
        $count = 0;
        $at = now()->utc()->toIso8601String();
        DB::table('orders')->where('is_test', true)->where('status', 'pending_test')->where('expires_at', '<=', $at)
            ->select('id')->orderBy('id')->chunkById(100, function ($orders) use (&$count, $at): void {
                foreach ($orders as $order) {
                    $count += DB::transaction(function () use ($order, $at): int {
                        $changed = DB::table('orders')->where('id', $order->id)->where('is_test', true)->where('status', 'pending_test')
                            ->where('expires_at', '<=', $at)->update(['status' => 'expired_test', 'updated_at' => $at]);
                        if ($changed) {
                            $this->record($order->id, null, 'pending_test', 'expired_test', $at);
                        }

                        return $changed;
                    });
                }
            });

        return $count;
    }
}
