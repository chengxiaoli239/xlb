<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClientCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $clients = [
            ['id' => 'client-android-clash-mi', 'platform' => 'android', 'name' => 'Clash Mi', 'description' => '适用于 Android 的 Clash 兼容客户端。'],
            ['id' => 'client-android-flclash', 'platform' => 'android', 'name' => 'FlClash', 'description' => '可导入远程订阅的多平台代理客户端。'],
            ['id' => 'client-android-surfboard', 'platform' => 'android', 'name' => 'Surfboard', 'description' => '支持代理规则与订阅更新。'],
            ['id' => 'client-ios-shadowrocket', 'platform' => 'ios', 'name' => 'Shadowrocket', 'description' => 'iPhone 与 iPad 常见代理客户端。'],
            ['id' => 'client-ios-stash', 'platform' => 'ios', 'name' => 'Stash', 'description' => '支持规则配置与远程订阅。'],
            ['id' => 'client-windows-clash-verge', 'platform' => 'windows', 'name' => 'Clash Verge Rev', 'description' => '桌面端客户端，可管理 Mihomo 配置。'],
            ['id' => 'client-macos-clash-verge', 'platform' => 'macos', 'name' => 'Clash Verge Rev', 'description' => '桌面端客户端，可管理 Mihomo 配置。'],
            ['id' => 'client-macos-surge', 'platform' => 'macos', 'name' => 'Surge', 'description' => '支持远程配置与规则管理。'],
            ['id' => 'client-linux-mihomo', 'platform' => 'linux', 'name' => 'Mihomo', 'description' => '可在 Linux 主机或兼容路由系统中运行。'],
            ['id' => 'client-linux-openwrt', 'platform' => 'linux', 'name' => 'OpenWrt', 'description' => '具体插件和固件版本需要按设备验证。'],
            ['id' => 'client-browser-chrome', 'platform' => 'browser', 'name' => 'Chrome 扩展', 'description' => '安装来源、浏览器权限和订阅导入流程待确认。'],
            ['id' => 'client-browser-edge', 'platform' => 'browser', 'name' => 'Edge 扩展', 'description' => '安装来源、浏览器权限和订阅导入流程待确认。'],
        ];

        foreach ($clients as $index => $client) {
            DB::table('client_catalog')->insertOrIgnore([
                ...$client,
                'version' => null,
                'download_url' => null,
                'qr_code_url' => null,
                'tutorial_url' => null,
                'compatibility_status' => 'pending',
                'sort_order' => $index + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
