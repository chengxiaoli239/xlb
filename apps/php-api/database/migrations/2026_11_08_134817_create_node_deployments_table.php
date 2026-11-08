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
        Schema::create('node_deployments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('request_id')->unique();
            $table->string('node_id')->unique();
            $table->string('actor_user_id');
            $table->string('stack_name')->unique();
            $table->string('region');
            $table->string('instance_type');
            $table->string('phase');
            $table->text('resources')->default('{}');
            $table->string('expires_at');
            $table->string('release_requested_at')->nullable();
            $table->string('release_not_before')->nullable();
            $table->string('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('next_attempt_at')->nullable();
            $table->string('verified_at')->nullable();
            $table->string('released_at')->nullable();
            $table->string('created_at');
            $table->string('updated_at');
            $table->foreign('node_id')->references('id')->on('nodes');
            $table->foreign('actor_user_id')->references('id')->on('users');
            $table->index(['phase', 'next_attempt_at']);
        });
        Schema::create('node_deployment_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('deployment_id');
            $table->string('phase');
            $table->string('message');
            $table->string('created_at');
            $table->foreign('deployment_id')->references('id')->on('node_deployments');
            $table->index(['deployment_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_deployment_events');
        Schema::dropIfExists('node_deployments');
    }
};
