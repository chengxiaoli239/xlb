<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

class SubscriptionProfileService
{
    public const FORMATS = ['clash', 'mihomo'];

    public const MODES = ['rule', 'global', 'direct'];

    public function __construct(private SubscriptionRouteService $routes) {}

    public function respond(Request $request, string $token): Response
    {
        if (! app()->environment(['local', 'testing']) || config('subscription_routes.scope') !== 'test') {
            return $this->unavailable();
        }
        $record = DB::table('subscriptions as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('member_devices as d', 'd.id', '=', 's.member_device_id')
            ->where('s.token_hash', hash('sha256', $token))->where('u.status', 'active')->where('u.role', 'member')
            ->first([
                's.id', 's.user_id', 's.status', 's.expires_at', 's.member_device_id', 's.credential_generation', 's.node_access_peer_id',
                's.node_access_peer_generation', 's.member_device_generation', 'd.status as device_status',
                'd.remove_requested_at', 'd.binding_generation', 'd.public_key_fingerprint',
            ]);
        $now = now();
        if (! $record || $record->status !== 'active'
            || ($record->member_device_id !== null && ($record->device_status !== 'registered' || $record->remove_requested_at !== null))
            || ($record->expires_at && Carbon::parse($record->expires_at)->lessThanOrEqualTo($now))) {
            return $this->unavailable();
        }
        $entitlement = DB::table('entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')
            ->where('e.user_id', $record->user_id)->where('e.status', 'active')
            ->where('e.starts_at', '<=', $now->toIso8601String())->where('e.ends_at', '>', $now->toIso8601String())
            ->orderByDesc('e.ends_at')->first(['e.ends_at', 'e.data_used_gb', 'p.traffic_gb', 'p.max_devices']);
        if (! $entitlement || $entitlement->data_used_gb >= $entitlement->traffic_gb) {
            return $this->unavailable();
        }
        if (DB::table('member_devices')->where('user_id', $record->user_id)->where('status', 'registered')->count() > (int) $entitlement->max_devices) {
            return $this->unavailable();
        }

        $format = $request->query('format');
        if ($format === null) {
            $agent = strtolower($request->userAgent() ?? '');
            $accept = strtolower($request->header('Accept', ''));
            $format = str_contains($agent, 'sing-box') || str_contains($accept, 'application/json')
                ? 'sing-box' : (str_contains($agent, 'mihomo') || str_contains($agent, 'clash.meta') ? 'mihomo' : 'clash');
        }
        if (! is_string($format) || ! in_array($format, self::FORMATS, true)) {
            return $this->jsonError('format_not_supported', '当前仅提供 Clash / Mihomo YAML，Sing-box 格式尚未开放。', 406);
        }
        $mode = $request->query('mode', 'rule');
        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            return $this->jsonError('invalid_mode', '模式仅支持 rule、global 或 direct。', 400);
        }

        if ($record->member_device_id !== null) {
            $bindingConfirmed = DB::table('node_access_subscription_binding_outbox')
                ->where('subscription_id', $record->id)
                ->where('generation', (int) $record->credential_generation)
                ->whereIn('status', ['confirmed', 'confirmed_test'])->exists();
            if (! $bindingConfirmed) {
                return $this->unavailable();
            }
            $peer = $this->currentPeerBinding($record);
            if (! $peer) {
                return $this->unavailable();
            }

            return $this->devicePeerResponse($record, $peer, $entitlement, $now, $format);
        }

        $route = $this->routes->resolve($record->user_id, (string) config('subscription_routes.scope'));
        if (! $route) {
            return $this->unavailable();
        }

        $nodes = $route['nodes'];
        $proxies = collect($nodes)->map(function (object $node): array {
            $nodeName = in_array($node->name, [
                'GLOBAL', 'DIRECT', 'REJECT', '迅连宝测试', '迅连宝-手动', '迅连宝-自动', '迅连宝-故障切换',
            ], true)
                ? $node->name.' · 节点'
                : $node->name;

            return [
                'name' => $nodeName,
                'type' => 'socks5',
                'server' => $node->endpoint_host,
                'port' => (int) $node->endpoint_port,
                'udp' => false,
            ];
        })->all();
        $proxyNames = array_column($proxies, 'name');
        $automaticGroupName = '迅连宝-自动';
        $fallbackGroupName = '迅连宝-故障切换';
        $manualGroupName = '迅连宝-手动';
        $probeUrl = 'https://www.gstatic.com/generate_204';
        $profile = [
            'mixed-port' => 7890,
            'allow-lan' => false,
            'mode' => $mode,
            'log-level' => 'warning',
            'proxies' => $proxies,
            'proxy-groups' => [
                [
                    'name' => $automaticGroupName,
                    'type' => 'url-test',
                    'proxies' => $proxyNames,
                    'url' => $probeUrl,
                    'interval' => 300,
                    'tolerance' => 50,
                ],
                [
                    'name' => $fallbackGroupName,
                    'type' => 'fallback',
                    'proxies' => $proxyNames,
                    'url' => $probeUrl,
                    'interval' => 300,
                ],
                [
                    'name' => $manualGroupName,
                    'type' => 'select',
                    'proxies' => [$automaticGroupName, $fallbackGroupName, ...$proxyNames, 'DIRECT'],
                ],
                [
                    'name' => 'GLOBAL',
                    'type' => 'select',
                    'proxies' => [$manualGroupName],
                ],
            ],
            'rules' => ['MATCH,'.$manualGroupName],
        ];
        $header = $route['version']->scope === 'test'
            ? "# 迅连宝本地开发配置，不包含真实节点或可用凭证。\n# 仅用于验证 Clash / Mihomo 订阅格式和导入流程。\n"
            : "# 迅连宝订阅配置，由当前已发布线路版本生成。\n";
        $yaml = $header.Yaml::dump($profile, 6, 2);
        $expiresAt = Carbon::parse($entitlement->ends_at);
        $renewals = DB::table('entitlements')->where('user_id', $record->user_id)->where('status', 'active')
            ->where('ends_at', '>', $now->toIso8601String())->orderBy('starts_at')->get(['starts_at', 'ends_at']);
        foreach ($renewals as $renewal) {
            if (Carbon::parse($renewal->starts_at)->greaterThan($expiresAt)) {
                break;
            }
            if (Carbon::parse($renewal->ends_at)->greaterThan($expiresAt)) {
                $expiresAt = Carbon::parse($renewal->ends_at);
            }
        }
        if ($record->expires_at && Carbon::parse($record->expires_at)->lessThan($expiresAt)) {
            $expiresAt = Carbon::parse($record->expires_at);
        }
        $usedBytes = (int) round($entitlement->data_used_gb * 1024 ** 3);
        $totalBytes = (int) round($entitlement->traffic_gb * 1024 ** 3);
        DB::table('subscriptions')->where('id', $record->id)->update(['last_used_at' => $now->toIso8601String()]);

        return $this->privateResponse($yaml)
            ->header('Content-Type', 'text/yaml; charset=utf-8')
            ->header('X-XLB-Profile-Format', $format)
            ->header('X-XLB-Profile-Scope', $route['version']->scope === 'test' ? 'local-test' : $route['version']->scope)
            ->header('X-XLB-Route-Version', (string) $route['version']->version)
            ->header('X-XLB-Config-Version', $route['version']->version.'-'.substr(hash('sha256', $yaml), 0, 12))
            ->header('X-XLB-Expires-At', $expiresAt->toIso8601String())
            ->header('Subscription-Userinfo', "upload=0; download={$usedBytes}; total={$totalBytes}; expire={$expiresAt->timestamp}")
            ->header('Profile-Update-Interval', '6');
    }

    private function unavailable(): Response
    {
        return $this->privateResponse('Subscription unavailable', 404)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    private function currentPeerBinding(object $subscription): ?object
    {
        if (! is_string($subscription->node_access_peer_id) || $subscription->node_access_peer_id === ''
            || $subscription->node_access_peer_generation === null || $subscription->member_device_generation === null
            || $subscription->binding_generation === null || $subscription->public_key_fingerprint === null) {
            return null;
        }

        $query = DB::table('node_access_peers as peer')->join('nodes as node', 'node.id', '=', 'peer.node_id')
            ->join('entitlements as peer_entitlement', 'peer_entitlement.id', '=', 'peer.entitlement_id')
            ->join('plans as peer_plan', 'peer_plan.id', '=', 'peer_entitlement.plan_id')
            ->where('peer.id', $subscription->node_access_peer_id)->where('peer.user_id', $subscription->user_id)
            ->where('peer.device_id', $subscription->member_device_id)->where('peer.status', 'active')
            ->where('peer_entitlement.user_id', $subscription->user_id)->where('peer_entitlement.status', 'active')
            ->where('peer_entitlement.starts_at', '<=', now()->utc()->toIso8601String())
            ->where('peer_entitlement.ends_at', '>', now()->utc()->toIso8601String())
            ->whereColumn('peer_entitlement.data_used_gb', '<', 'peer_plan.traffic_gb')
            ->whereNotNull('peer.credential_ciphertext')->where('peer.device_key_fingerprint', $subscription->public_key_fingerprint)
            ->whereNotNull('peer.profile_ciphertext')->whereNotNull('peer.profile_sha256')
            ->where('peer.profile_format', 'sandbox-only')->where('peer.profile_renderer_id', 'isolated-test-agent')
            ->where('peer.generation', (int) $subscription->node_access_peer_generation)
            ->where('peer.device_binding_generation', (int) $subscription->member_device_generation)
            ->where('peer.device_binding_generation', (int) $subscription->binding_generation)
            ->where('node.is_test', true)->where('node.status', 'available')->where('node.health_status', 'verified_test')
            ->whereNotNull('peer.expires_at')->where('peer.expires_at', '>', now()->utc()->toIso8601String())
            ->where('peer.provision_confirmation_status', 'confirmed_test');

        if (! app()->environment(['local', 'testing']) || config('subscription_routes.scope') !== 'test') {
            return null;
        }

        return $query->first([
            'peer.id', 'peer.node_id', 'peer.generation', 'peer.status', 'peer.provision_confirmation_status',
            'peer.profile_ciphertext', 'peer.profile_sha256', 'peer.profile_format', 'peer.profile_renderer_id', 'node.is_test',
        ]);
    }

    private function devicePeerResponse(object $subscription, object $peer, object $entitlement, Carbon $now, string $format): Response
    {
        try {
            $yaml = Crypt::decryptString($peer->profile_ciphertext);
        } catch (\Throwable) {
            return $this->unavailable();
        }
        if ($yaml === '' || strlen($yaml) > 131072
            || ! hash_equals((string) $peer->profile_sha256, hash('sha256', $yaml))) {
            return $this->unavailable();
        }

        DB::table('subscriptions')->where('id', $subscription->id)->update(['last_used_at' => $now->toIso8601String()]);
        $expiresAt = Carbon::parse($entitlement->ends_at);
        $usedBytes = (int) round($entitlement->data_used_gb * 1024 ** 3);
        $totalBytes = (int) round($entitlement->traffic_gb * 1024 ** 3);

        return $this->privateResponse($yaml)
            ->header('Content-Type', 'text/yaml; charset=utf-8')
            ->header('X-XLB-Profile-Format', $format)
            ->header('X-XLB-Profile-Scope', 'local-sandbox-peer')
            ->header('X-XLB-Peer-Generation', (string) $peer->generation)
            ->header('X-XLB-Config-Version', 'peer-'.$peer->generation.'-'.substr(hash('sha256', $yaml), 0, 12))
            ->header('X-XLB-Expires-At', $expiresAt->toIso8601String())
            ->header('Subscription-Userinfo', "upload=0; download={$usedBytes}; total={$totalBytes}; expire={$expiresAt->timestamp}")
            ->header('Profile-Update-Interval', '6');
    }

    private function jsonError(string $code, string $message, int $status): Response
    {
        return $this->privateResponse(json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $status)
            ->header('Content-Type', 'application/json');
    }

    private function privateResponse(string $body, int $status = 200): Response
    {
        return response($body, $status)->header('Cache-Control', 'no-store, private')
            ->header('Vary', 'User-Agent, Accept')->header('X-Content-Type-Options', 'nosniff');
    }
}
