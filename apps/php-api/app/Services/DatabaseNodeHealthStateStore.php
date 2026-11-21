<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DatabaseNodeHealthStateStore implements NodeHealthStateStore
{
    public function get(string $nodeId): ?NodeHealthState
    {
        $this->validateNodeId($nodeId);
        $row = DB::table('node_health_probe_states')->where('node_id', $nodeId)->first();

        if ($row === null) {
            return null;
        }

        return new NodeHealthState(
            $row->status,
            (int) $row->failure_streak,
            (int) $row->success_streak,
            $row->last_probe_at === null ? null : new DateTimeImmutable($row->last_probe_at),
        );
    }

    public function put(string $nodeId, NodeHealthState $state): void
    {
        $this->validateNodeId($nodeId);
        DB::table('node_health_probe_states')->updateOrInsert(
            ['node_id' => $nodeId],
            $this->stateValues($state),
        );
    }

    public function compareAndSwap(string $nodeId, ?NodeHealthState $expected, NodeHealthState $next): bool
    {
        $this->validateNodeId($nodeId);
        $values = $this->stateValues($next);

        if ($expected === null) {
            return DB::table('node_health_probe_states')->insertOrIgnore(['node_id' => $nodeId, ...$values]) === 1;
        }

        $query = DB::table('node_health_probe_states')
            ->where('node_id', $nodeId)
            ->where('status', $expected->status)
            ->where('failure_streak', $expected->failureStreak)
            ->where('success_streak', $expected->successStreak);

        if ($expected->lastProbeAt === null) {
            $query->whereNull('last_probe_at');
        } else {
            $query->where('last_probe_at', $this->formatDate($expected->lastProbeAt));
        }

        return $query->update($values) === 1;
    }

    /** @return array{status:string,failure_streak:int,success_streak:int,last_probe_at:?string} */
    private function stateValues(NodeHealthState $state): array
    {
        return [
            'status' => $state->status,
            'failure_streak' => $state->failureStreak,
            'success_streak' => $state->successStreak,
            'last_probe_at' => $state->lastProbeAt === null ? null : $this->formatDate($state->lastProbeAt),
        ];
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d\TH:i:s.uP');
    }

    private function validateNodeId(string $nodeId): void
    {
        if (trim($nodeId) === '') {
            throw new InvalidArgumentException('Node ID is required to access health state.');
        }
    }
}
