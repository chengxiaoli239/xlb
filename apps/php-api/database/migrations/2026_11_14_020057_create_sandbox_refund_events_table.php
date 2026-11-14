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
        Schema::create('sandbox_refund_events', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_id')->unique();
            $table->string('payment_event_id')->unique();
            $table->string('idempotency_key', 36)->unique();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status');
            $table->text('reason');
            $table->string('actor_user_id');
            $table->string('created_at');
            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('payment_event_id')->references('id')->on('sandbox_payment_events');
            $table->foreign('actor_user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sandbox_refund_events');
    }
};
