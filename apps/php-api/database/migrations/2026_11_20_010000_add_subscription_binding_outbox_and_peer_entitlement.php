<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_access_peers', function (Blueprint $table): void {
            $table->string('entitlement_id')->nullable()->after('device_id');
            $table->index(['entitlement_id', 'status']);
            $table->foreign('entitlement_id')->references('id')->on('entitlements');
        });

        Schema::create('node_access_subscription_binding_outbox', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('subscription_id');
            $table->string('peer_id');
            $table->unsignedInteger('generation');
            $table->string('status', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('next_attempt_at')->nullable();
            $table->string('processing_at')->nullable();
            $table->string('lease_expires_at')->nullable();
            $table->string('lock_token', 36)->nullable();
            $table->string('acknowledgement_id')->nullable();
            $table->string('last_error_code')->nullable();
            $table->string('confirmed_at')->nullable();
            $table->string('created_at');
            $table->string('updated_at');
            $table->unique(['subscription_id', 'generation'], 'node_sub_binding_sub_gen_unique');
            $table->index(['status', 'next_attempt_at'], 'node_sub_binding_status_next_index');
            $table->index(['status', 'lease_expires_at'], 'node_sub_binding_status_lease_index');
            $table->foreign('subscription_id')->references('id')->on('subscriptions');
            $table->foreign('peer_id')->references('id')->on('node_access_peers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_access_subscription_binding_outbox');
        Schema::table('node_access_peers', function (Blueprint $table): void {
            $table->dropForeign(['entitlement_id']);
            $table->dropIndex(['entitlement_id', 'status']);
            $table->dropColumn('entitlement_id');
        });
    }
};
