<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('health_status')->default('not_connected');
            $table->string('last_health_check_at')->nullable();
            $table->unsignedInteger('config_version')->default(1);
        });

        Schema::create('node_state_changes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('node_id');
            $table->string('actor_user_id');
            $table->string('from_status');
            $table->string('to_status');
            $table->string('created_at');
            $table->index(['node_id', 'created_at']);
            $table->foreign('node_id')->references('id')->on('nodes');
            $table->foreign('actor_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_state_changes');
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['health_status', 'last_health_check_at', 'config_version']);
        });
    }
};
