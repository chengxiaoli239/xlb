<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SubscriptionRouteService
{
    public const SCOPES = ['test', 'production'];

    /** @return array<int, array<string, mixed>> */
    public function list(string $scope): array
    {
        $this->assertScope($scope);

        return DB::table('subscription_route_versions as v')
            ->where('v.scope', $scope)->orderByDesc('v.version')->get()
            ->map(fn (object $version): array => $this->present($version))->all();
    }

    /** @return array<string, mixed> */
    public function create(array $input, string $actor): array
    {
        $scope = (string) ($input['scope'] ?? '');
        $nodeIds = array_values(array_unique(array_map('strval', $input['node_ids'] ?? [])));
        $this->assertScope($scope);
        if ($nodeIds === []) {
            throw new RuntimeException('线路版本至少需要一个节点', 422);
        }

        return DB::transaction(function () use ($scope, $nodeIds, $actor, $input): array {
            $nodes = DB::table('nodes')->whereIn('id', $nodeIds)->lockForUpdate()->get();
            $this->assertNodesReady($nodes, $scope, $nodeIds);
            $version = ((int) DB::table('subscription_route_versions')->where('scope', $scope)->max('version')) + 1;
            $id = (string) Str::uuid();
            $now = now()->toIso8601String();
            DB::table('subscription_route_versions')->insert([
                'id' => $id, 'scope' => $scope, 'version' => $version, 'status' => 'draft',
                'canary_percent' => 0, 'previous_version_id' => null, 'note' => $input['note'] ?? null,
                'actor_user_id' => $actor, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($nodeIds as $nodeId) {
                DB::table('subscription_route_nodes')->insert([
                    'id' => (string) Str::uuid(), 'route_version_id' => $id, 'node_id' => $nodeId,
                    'weight' => 100, 'created_at' => $now,
                ]);
            }
            $this->audit($actor, 'subscription_route.created', $id);

            return $this->present(DB::table('subscription_route_versions')->where('id', $id)->first());
        });
    }

    /** @return array<string, mixed> */
    public function canary(string $id, int $percent, string $actor): array
    {
        if ($percent < 1 || $percent > 100) {
            throw new RuntimeException('灰度比例必须在 1 到 100 之间', 422);
        }

        return DB::transaction(function () use ($id, $percent, $actor): array {
            $version = DB::table('subscription_route_versions')->where('id', $id)->lockForUpdate()->first();
            $this->assertVersion($version, ['draft', 'canary']);
            $nodes = $this->routeNodes($id, true);
            $this->assertNodesReady($nodes, $version->scope, $nodes->pluck('id')->all());
            $previous = DB::table('subscription_route_versions')->where('scope', $version->scope)->where('status', 'published')->orderByDesc('version')->first();
            if (! $previous) {
                throw new RuntimeException('当前范围没有已发布稳定线路，首次上线请使用全量发布，不能启动灰度', 409);
            }
            $now = now()->toIso8601String();
            DB::table('subscription_route_versions')->where('id', $id)->update([
                'status' => 'canary', 'canary_percent' => $percent, 'previous_version_id' => $version->previous_version_id ?: ($previous?->id),
                'canary_started_at' => $version->canary_started_at ?: $now, 'updated_at' => $now,
            ]);
            $this->audit($actor, 'subscription_route.canary_started', $id);

            return $this->present(DB::table('subscription_route_versions')->where('id', $id)->first());
        });
    }

    /** @return array<string, mixed> */
    public function publish(string $id, string $actor): array
    {
        return DB::transaction(function () use ($id, $actor): array {
            $version = DB::table('subscription_route_versions')->where('id', $id)->lockForUpdate()->first();
            $this->assertVersion($version, ['draft', 'canary']);
            $nodes = $this->routeNodes($id, true);
            $this->assertNodesReady($nodes, $version->scope, $nodes->pluck('id')->all());
            $previous = DB::table('subscription_route_versions')->where('scope', $version->scope)->where('status', 'published')->where('id', '!=', $id)->orderByDesc('version')->lockForUpdate()->first();
            $now = now()->toIso8601String();
            if ($previous) {
                DB::table('subscription_route_versions')->where('id', $previous->id)->update([
                    'status' => 'draining', 'drain_until' => now()->addSeconds(config('subscription_routes.drain_seconds'))->toIso8601String(), 'updated_at' => $now,
                ]);
            }
            DB::table('subscription_route_versions')->where('scope', $version->scope)->where('status', 'canary')->where('id', '!=', $id)->update(['status' => 'rolled_back', 'rollback_at' => $now, 'updated_at' => $now]);
            DB::table('subscription_route_versions')->where('id', $id)->update([
                'status' => 'published', 'canary_percent' => 100, 'previous_version_id' => $version->previous_version_id ?: ($previous?->id),
                'published_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit($actor, 'subscription_route.published', $id);

            return $this->present(DB::table('subscription_route_versions')->where('id', $id)->first());
        });
    }

    /** @return array<string, mixed> */
    public function rollback(string $id, string $actor): array
    {
        return DB::transaction(function () use ($id, $actor): array {
            $version = DB::table('subscription_route_versions')->where('id', $id)->lockForUpdate()->first();
            $this->assertVersion($version, ['published', 'canary']);
            $previousId = $version->previous_version_id;
            $previous = $previousId ? DB::table('subscription_route_versions')->where('id', $previousId)->lockForUpdate()->first() : null;
            if (! $previous) {
                throw new RuntimeException('没有可回滚的上一线路版本', 409);
            }
            $nodes = $this->routeNodes($previous->id, true);
            $this->assertNodesReady($nodes, $previous->scope, $nodes->pluck('id')->all());
            $now = now()->toIso8601String();
            DB::table('subscription_route_versions')->where('id', $id)->update(['status' => 'rolled_back', 'rollback_at' => $now, 'canary_percent' => 0, 'updated_at' => $now]);
            DB::table('subscription_route_versions')->where('id', $previous->id)->update(['status' => 'published', 'canary_percent' => 100, 'drain_until' => null, 'published_at' => $now, 'updated_at' => $now]);
            $this->audit($actor, 'subscription_route.rolled_back', $id);

            return $this->present(DB::table('subscription_route_versions')->where('id', $id)->first());
        });
    }

    /** @return array{version: object, nodes: array<int, object>}|null */
    public function resolve(string $userId, string $scope): ?array
    {
        $this->assertScope($scope);
        $published = DB::table('subscription_route_versions')->where('scope', $scope)->where('status', 'published')->orderByDesc('version')->first();
        $canary = DB::table('subscription_route_versions')->where('scope', $scope)->where('status', 'canary')->orderByDesc('version')->first();
        $selected = $published;
        if ($canary && $published && $this->bucket($userId, $canary->id) < (int) $canary->canary_percent) {
            $selected = $canary;
        }
        if (! $selected) {
            return null;
        }
        $healthyStatuses = $scope === 'production' ? ['healthy'] : ['verified_test', 'healthy'];
        $nodes = DB::table('nodes as n')->join('subscription_route_nodes as rn', 'rn.node_id', '=', 'n.id')
            ->where('rn.route_version_id', $selected->id)->where('n.status', 'available')->whereIn('n.health_status', $healthyStatuses)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('node_deployments as d')->whereColumn('d.node_id', 'n.id'))
            ->orderByDesc('rn.weight')->orderBy('n.id')
            ->get(['n.id', 'n.name', 'n.endpoint_host', 'n.endpoint_port', 'n.agent_endpoint', 'n.max_sessions', 'n.active_sessions', 'n.last_health_check_at'])
            ->filter(fn (object $node): bool => $this->hasFreshHealthCheck($node->last_health_check_at));

        return $nodes->isEmpty() ? null : ['version' => $selected, 'nodes' => $nodes->all()];
    }

    public function reconcile(): int
    {
        return DB::table('subscription_route_versions')->where('status', 'draining')
            ->whereNotNull('drain_until')->where('drain_until', '<=', now()->toIso8601String())
            ->update(['status' => 'retired', 'retired_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()]);
    }

    /** @return array<string, mixed> */
    private function present(object $version): array
    {
        $nodes = DB::table('subscription_route_nodes as rn')->join('nodes as n', 'n.id', '=', 'rn.node_id')
            ->where('rn.route_version_id', $version->id)->orderByDesc('rn.weight')->orderBy('n.id')
            ->get(['n.id', 'n.name', 'n.region', 'n.status', 'n.is_test', 'n.health_status', 'rn.weight'])->all();

        return [...(array) $version, 'nodes' => $nodes];
    }

    private function assertScope(string $scope): void
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new RuntimeException('线路范围无效', 422);
        }
    }

    private function assertVersion(?object $version, array $statuses): void
    {
        if (! $version || ! in_array($version->status, $statuses, true)) {
            throw new RuntimeException('线路版本当前状态不允许此操作', 409);
        }
    }

    /** @param iterable<object> $nodes @param array<int, string> $nodeIds */
    private function assertNodesReady(iterable $nodes, string $scope, array $nodeIds): void
    {
        $rows = collect($nodes);
        if ($rows->count() !== count($nodeIds)) {
            throw new RuntimeException('线路包含不存在的节点', 422);
        }
        if (DB::table('node_deployments')->whereIn('node_id', $nodeIds)->exists()) {
            throw new RuntimeException('管理员临时云测试节点不能加入订阅线路', 422);
        }
        foreach ($rows as $node) {
            $healthyStatuses = $scope === 'production' ? ['healthy'] : ['verified_test', 'healthy'];
            if ($node->status !== 'available' || ! in_array($node->health_status, $healthyStatuses, true)
                || ! $this->hasFreshHealthCheck($node->last_health_check_at)
                || ($scope === 'production' && (bool) $node->is_test) || ($scope === 'test' && ! (bool) $node->is_test)) {
                throw new RuntimeException('线路节点尚未通过范围和健康校验', 422);
            }
        }
    }

    private function hasFreshHealthCheck(?string $lastHealthCheckAt): bool
    {
        if (! $lastHealthCheckAt) {
            return false;
        }

        try {
            return Carbon::parse($lastHealthCheckAt)->greaterThanOrEqualTo(
                now()->subSeconds(max(1, (int) config('subscription_routes.health_check_ttl_seconds', 300)))
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function routeNodes(string $routeId, bool $lock): Collection
    {
        $query = DB::table('nodes as n')->join('subscription_route_nodes as rn', 'rn.node_id', '=', 'n.id')->where('rn.route_version_id', $routeId)->select('n.*');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    private function bucket(string $userId, string $routeId): int
    {
        return hexdec(substr(hash('sha256', $userId.'|'.$routeId), 0, 8)) % 100;
    }

    private function audit(string $actor, string $action, string $targetId): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'actor_user_id' => $actor, 'action' => $action,
            'target_type' => 'subscription_route_version', 'target_id' => $targetId, 'created_at' => now()->toIso8601String(),
        ]);
    }
}
