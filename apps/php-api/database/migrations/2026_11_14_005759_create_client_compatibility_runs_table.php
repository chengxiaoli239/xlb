<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_compatibility_runs', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('client_id');
            $table->string('client_version', 40);
            $table->string('core_name', 80);
            $table->string('core_version', 40);
            $table->string('os_version', 100);
            $table->string('profile_format', 20);
            $table->string('network_type', 20);
            $table->boolean('udp_supported');
            $table->json('checks');
            $table->string('result', 20);
            $table->boolean('matches_current_version');
            $table->string('evidence_reference', 80);
            $table->text('notes')->nullable();
            $table->string('tester_user_id');
            $table->timestamp('tested_at');
            $table->timestamps(6);
            $table->foreign('client_id')->references('id')->on('client_catalog')->cascadeOnDelete();
            $table->foreign('tester_user_id')->references('id')->on('users');
            $table->index(['client_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_compatibility_runs');
    }
};
