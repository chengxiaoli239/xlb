<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('idempotency_key', 36)->nullable();
            $table->json('plan_snapshot')->nullable();
            $table->string('expires_at')->nullable();
            $table->string('updated_at')->nullable();
            $table->boolean('is_test')->default(true);
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['status', 'expires_at']);
        });
        Schema::create('order_events', function (Blueprint $table): void {
            $table->id('sequence');
            $table->string('id')->unique();
            $table->string('order_id');
            $table->string('actor_user_id')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('created_at');
            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('actor_user_id')->references('id')->on('users');
            $table->index(['order_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn(['idempotency_key', 'plan_snapshot', 'expires_at', 'updated_at', 'is_test']);
        });
    }
};
