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
        Schema::create('sandbox_payment_events', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('event_id')->unique();
            $table->string('transaction_id')->unique();
            $table->string('order_id')->unique();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status');
            $table->string('review_reason')->nullable();
            $table->string('created_at');
            $table->foreign('order_id')->references('id')->on('orders');
        });

        Schema::create('entitlement_grants', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_id')->unique();
            $table->string('entitlement_id')->unique();
            $table->string('user_id');
            $table->string('status');
            $table->string('starts_at');
            $table->string('ends_at');
            $table->string('created_at');
            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('entitlement_id')->references('id')->on('entitlements');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entitlement_grants');
        Schema::dropIfExists('sandbox_payment_events');
    }
};
