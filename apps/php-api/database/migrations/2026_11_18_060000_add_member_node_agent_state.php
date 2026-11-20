<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('agent_endpoint')->nullable();
        });

        Schema::table('member_devices', function (Blueprint $table): void {
            $table->string('remove_requested_at')->nullable();
        });

        Schema::table('node_access_peers', function (Blueprint $table): void {
            $table->unsignedInteger('generation')->default(1);
            $table->string('device_key_fingerprint', 64);
            $table->string('expires_at')->nullable();
            $table->text('credential_ciphertext')->nullable();
            $table->text('profile_ciphertext')->nullable();
            $table->string('provision_request_id', 36)->nullable()->unique();
            $table->string('provision_acknowledgement_id')->nullable();
            $table->string('provision_confirmation_status', 24)->nullable();
            $table->index(['device_id', 'status']);
        });

        Schema::table('node_access_revocation_outbox', function (Blueprint $table): void {
            $table->unsignedInteger('generation')->default(2);
        });

        Schema::create('node_access_provisioning_outbox', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('peer_id')->unique();
            $table->string('status', 24);
            $table->unsignedInteger('generation');
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
            $table->index(['status', 'next_attempt_at']);
            $table->index(['status', 'lease_expires_at']);
            $table->foreign('peer_id')->references('id')->on('node_access_peers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_access_provisioning_outbox');
        Schema::table('node_access_revocation_outbox', function (Blueprint $table): void {
            $table->dropColumn('generation');
        });
        Schema::table('node_access_peers', function (Blueprint $table): void {
            $table->dropIndex(['device_id', 'status']);
            $table->dropUnique(['provision_request_id']);
            $table->dropColumn([
                'generation', 'device_key_fingerprint', 'expires_at', 'credential_ciphertext', 'profile_ciphertext', 'provision_request_id',
                'provision_acknowledgement_id', 'provision_confirmation_status',
            ]);
        });
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn('agent_endpoint');
        });
        Schema::table('member_devices', function (Blueprint $table): void {
            $table->dropColumn('remove_requested_at');
        });
    }
};
