<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $now = now()->toIso8601String();
        DB::table('users')->insertOrIgnore([
            ['id' => 'user-admin', 'email' => 'admin@xlb.local', 'name' => '系统管理员', 'role' => 'admin', 'status' => 'active', 'password' => Hash::make(env('LOCAL_ADMIN_PASSWORD', 'admin-local-2026')), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'user-member', 'email' => 'member@xlb.local', 'name' => '演示会员', 'role' => 'member', 'status' => 'active', 'password' => Hash::make(env('LOCAL_MEMBER_PASSWORD', 'member-local-2026')), 'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach ([
            ['weekly', '周会员', '1 周', 7, 900, 10, 2],
            ['monthly', '月会员', '1 个月', 30, 2980, 100, 2],
            ['quarterly', '季会员', '1 季度', 90, 7980, 300, 4],
            ['annual', '年会员', '1 年', 365, 27880, 1500, 5],
        ] as [$id, $name, $label, $days, $cents, $traffic, $devices]) {
            DB::table('plans')->insertOrIgnore(['id' => $id, 'family_id' => $id, 'version' => 1, 'name' => $name, 'period_label' => $label, 'duration_days' => $days, 'price_cents' => $cents, 'traffic_gb' => $traffic, 'max_devices' => $devices, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('entitlements')->insertOrIgnore(['id' => 'entitlement-member-annual', 'user_id' => 'user-member', 'plan_id' => 'annual', 'status' => 'active', 'starts_at' => now()->subDays(16)->toIso8601String(), 'ends_at' => now()->addDays(349)->toIso8601String(), 'data_used_gb' => 326.4]);
        DB::table('nodes')->insertOrIgnore(['id' => 'node-test-1', 'name' => '演示节点 01', 'region' => '测试区域', 'protocol' => 'SOCKS5', 'endpoint_host' => '198.51.100.10', 'endpoint_port' => 1080, 'status' => 'available', 'is_test' => true, 'max_sessions' => 100, 'active_sessions' => 12, 'latency_ms' => 38, 'updated_at' => $now]);
        DB::table('orders')->insertOrIgnore(['id' => 'order-demo-001', 'user_id' => 'user-member', 'plan_id' => 'annual', 'total_cents' => 27880, 'status' => 'paid_test', 'created_at' => $now]);
        DB::table('commission_entries')->insertOrIgnore(['id' => 'commission-demo-001', 'order_id' => 'order-demo-001', 'partner_name' => '演示渠道 A', 'amount_cents' => 4170, 'status' => 'pending', 'created_at' => $now]);
    }
}
