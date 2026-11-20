<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_access_peers', function (Blueprint $table): void {
            $table->unsignedBigInteger('quota_accounted_upload_bytes')->default(0);
            $table->unsignedBigInteger('quota_accounted_download_bytes')->default(0);
        });

        DB::statement('UPDATE node_access_peers SET quota_accounted_upload_bytes = upload_bytes, quota_accounted_download_bytes = download_bytes');

        Schema::create('entitlement_traffic_budgets', function (Blueprint $table): void {
            $table->string('entitlement_id')->primary();
            $table->unsignedBigInteger('quota_bytes');
            $table->unsignedBigInteger('used_bytes');
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->string('created_at');
            $table->string('updated_at');
            $table->foreign('entitlement_id')->references('id')->on('entitlements');
        });

        Schema::create('node_traffic_reservations', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('request_id', 36)->unique();
            $table->string('entitlement_id');
            $table->string('node_id');
            $table->string('peer_id');
            $table->unsignedInteger('peer_generation');
            $table->unsignedBigInteger('grant_bytes');
            $table->unsignedBigInteger('settled_upload_bytes')->default(0);
            $table->unsignedBigInteger('settled_download_bytes')->default(0);
            $table->string('status', 16);
            $table->string('lease_expires_at');
            $table->string('acknowledgement_id')->nullable();
            $table->string('final_at')->nullable();
            $table->string('created_at');
            $table->string('updated_at');
            $table->index(['entitlement_id', 'status']);
            $table->index(['peer_id', 'status']);
            $table->foreign('entitlement_id')->references('id')->on('entitlements');
            $table->foreign('node_id')->references('id')->on('nodes');
            $table->foreign('peer_id')->references('id')->on('node_access_peers');
        });

        Schema::table('node_access_peers', function (Blueprint $table): void {
            $table->string('profile_format', 40)->nullable();
            $table->string('profile_renderer_id', 80)->nullable();
            $table->string('profile_renderer_version', 40)->nullable();
            $table->string('profile_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_access_peers', function (Blueprint $table): void {
            $table->dropColumn([
                'quota_accounted_upload_bytes', 'quota_accounted_download_bytes', 'profile_format',
                'profile_renderer_id', 'profile_renderer_version', 'profile_sha256',
            ]);
        });
        Schema::dropIfExists('node_traffic_reservations');
        Schema::dropIfExists('entitlement_traffic_budgets');
    }
};
