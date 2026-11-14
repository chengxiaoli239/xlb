<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_route_versions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('scope', 32);
            $table->unsignedInteger('version');
            $table->string('status', 24);
            $table->unsignedTinyInteger('canary_percent')->default(0);
            $table->string('previous_version_id')->nullable();
            $table->string('note')->nullable();
            $table->string('actor_user_id');
            $table->string('canary_started_at')->nullable();
            $table->string('published_at')->nullable();
            $table->string('rollback_at')->nullable();
            $table->string('drain_until')->nullable();
            $table->string('retired_at')->nullable();
            $table->string('created_at');
            $table->string('updated_at');
            $table->unique(['scope', 'version']);
            $table->index(['scope', 'status']);
            $table->foreign('actor_user_id')->references('id')->on('users');
        });

        Schema::create('subscription_route_nodes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('route_version_id');
            $table->string('node_id');
            $table->unsignedInteger('weight')->default(100);
            $table->string('created_at');
            $table->unique(['route_version_id', 'node_id']);
            $table->foreign('route_version_id')->references('id')->on('subscription_route_versions');
            $table->foreign('node_id')->references('id')->on('nodes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_route_nodes');
        Schema::dropIfExists('subscription_route_versions');
    }
};
