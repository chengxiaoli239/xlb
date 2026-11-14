<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_access_peers', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('node_id');
            $table->string('device_id');
            $table->string('device_name');
            $table->string('public_key')->unique();
            $table->string('status');
            $table->unsignedBigInteger('upload_bytes')->default(0);
            $table->unsignedBigInteger('download_bytes')->default(0);
            $table->string('last_observed_at')->nullable();
            $table->string('revoked_at')->nullable();
            $table->string('created_at');
            $table->string('updated_at');
            $table->index(['user_id', 'status']);
            $table->index(['node_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('node_id')->references('id')->on('nodes');
        });

        Schema::create('node_access_revocation_outbox', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('peer_id')->unique();
            $table->string('refund_event_id');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('next_attempt_at')->nullable();
            $table->string('processing_at')->nullable();
            $table->string('lease_expires_at')->nullable();
            $table->string('lock_token', 36)->nullable();
            $table->string('acknowledgement_id')->nullable();
            $table->unsignedBigInteger('final_upload_bytes')->nullable();
            $table->unsignedBigInteger('final_download_bytes')->nullable();
            $table->string('last_error_code')->nullable();
            $table->string('confirmed_at')->nullable();
            $table->string('created_at');
            $table->string('updated_at');
            $table->index(['status', 'next_attempt_at']);
            $table->index(['status', 'lease_expires_at']);
            $table->foreign('peer_id')->references('id')->on('node_access_peers');
            $table->foreign('refund_event_id')->references('id')->on('sandbox_refund_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_access_revocation_outbox');
        Schema::dropIfExists('node_access_peers');
    }
};
