<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

class SubscriptionProfileService
{
    public const FORMATS = ['clash', 'mihomo'];

    public const MODES = ['rule', 'global', 'direct'];

    public function __construct(private SubscriptionRouteService $routes) {}

    public function respond(Request $request, string $token): Response
    {
        $record = DB::table('subscriptions as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.token_hash', hash('sha256', $token))->where('u.status', 'active')->where('u.role', 'member')
            ->first(['s.id', 's.user_id', 's.status', 's.expires_at']);
        $now = now();
        if (! $record || $record->status !== 'active' || ($record->expires_at && Carbon::parse($record->expires_at)->lessThanOrEqualTo($now))) {
            return $this->unavailable();
        }

        $entitlement = DB::table('entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')
            ->where('e.user_id', $record->user_id)->where('e.status', 'active')
            ->where('e.starts_at', '<=', $now->toIso8601String())->where('e.ends_at', '>', $now->toIso8601String())
            ->orderByDesc('e.ends_at')->first(['e.ends_at', 'e.data_used_gb', 'p.traffic_gb']);
        if (! $entitlement || $entitlement->data_used_gb >= $entitlement->traffic_gb) {
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

        $route = $this->routes->resolve($record->user_id, (string) config('subscription_routes.scope'));
        if (! $route) {
            return $this->unavailable();
        }

        $nodes = $route['nodes'];
        $proxies = collect($nodes)->map(function (object $node): array {
            $nodeName = in_array($node->name, ['GLOBAL', 'DIRECT', 'REJECT', '迅连宝测试'], true)
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
        $profile = [
            'mixed-port' => 7890,
            'allow-lan' => false,
            'mode' => $mode,
            'log-level' => 'warning',
            'proxies' => $proxies,
            'proxy-groups' => [
                [
                    'name' => '迅连宝测试',
                    'type' => 'select',
                    'proxies' => [...$proxyNames, 'DIRECT'],
                ],
                [
                    'name' => 'GLOBAL',
                    'type' => 'select',
                    'proxies' => ['迅连宝测试'],
                ],
            ],
            'rules' => ['MATCH,迅连宝测试'],
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
