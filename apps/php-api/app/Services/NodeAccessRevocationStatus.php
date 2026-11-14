<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class NodeAccessRevocationStatus
{
    /** @return array{status: string, total_count: int, pending_count: int, retrying_count: int, confirmed_count: int} */
    public function forRefund(?string $refundEventId): array
    {
        if (! $refundEventId) {
            return $this->empty();
        }

        $counts = DB::table('node_access_revocation_outbox')->where('refund_event_id', $refundEventId)
            ->selectRaw('status, COUNT(*) as row_count')->groupBy('status')->pluck('row_count', 'status');
        $pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0);
        $confirmed = (int) ($counts['confirmed'] ?? 0) + (int) ($counts['confirmed_test'] ?? 0);
        $total = array_sum(array_map('intval', $counts->all()));
        $retrying = $total - $pending - $confirmed;

        if ($total === 0) {
            return $this->empty();
        }

        $status = match (true) {
            $pending > 0 => 'pending',
            $retrying > 0 => 'retrying',
            (int) ($counts['confirmed_test'] ?? 0) > 0 => 'confirmed_test',
            default => 'confirmed',
        };

        return [
            'status' => $status,
            'total_count' => $total,
            'pending_count' => $pending,
            'retrying_count' => $retrying,
            'confirmed_count' => $confirmed,
        ];
    }

    /** @return array{status: string, total_count: int, pending_count: int, retrying_count: int, confirmed_count: int} */
    private function empty(): array
    {
        return [
            'status' => 'not_required',
            'total_count' => 0,
            'pending_count' => 0,
            'retrying_count' => 0,
            'confirmed_count' => 0,
        ];
    }
}
