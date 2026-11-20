<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_devices', function (Blueprint $table): void {
            $table->unsignedInteger('binding_generation')->default(1);
        });

        Schema::table('node_access_peers', function (Blueprint $table): void {
            $table->unsignedInteger('device_binding_generation')->default(1);
            $table->dropUnique(['public_key']);
            $table->index('public_key');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('node_access_peer_id')->nullable()->unique();
            $table->unsignedInteger('node_access_peer_generation')->nullable();
            $table->unsignedInteger('member_device_generation')->nullable();
            $table->foreign('node_access_peer_id')->references('id')->on('node_access_peers');
        });
    }

    public function down(): void
    {
        $hasRepeatedDeviceProofKeys = DB::table('node_access_peers')->select('public_key')->groupBy('public_key')
            ->havingRaw('COUNT(*) > 1')->exists();
        if ($hasRepeatedDeviceProofKeys) {
            throw new RuntimeException('Cannot restore unique peer public keys while a device has multiple peer generations.');
        }

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['node_access_peer_id']);
            $table->dropUnique(['node_access_peer_id']);
            $table->dropColumn(['node_access_peer_id', 'node_access_peer_generation', 'member_device_generation']);
        });

        Schema::table('node_access_peers', function (Blueprint $table): void {
            $table->dropIndex(['public_key']);
            $table->unique('public_key');
            $table->dropColumn('device_binding_generation');
        });

        Schema::table('member_devices', function (Blueprint $table): void {
            $table->dropColumn('binding_generation');
        });
    }
};
