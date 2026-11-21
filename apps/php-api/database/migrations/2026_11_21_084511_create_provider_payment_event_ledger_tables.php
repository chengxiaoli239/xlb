<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_payment_orders', function (Blueprint $table): void {
            $table->string('merchant_order_id')->primary();
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('expires_at');
            $table->string('status')->default('pending');
            $table->boolean('entitlement_granted')->default(false);
            $table->unsignedInteger('compensation_count')->default(0);
            $table->string('updated_at');
            $table->foreign('merchant_order_id')->references('id')->on('orders');
        });

        Schema::create('provider_payment_events', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('provider', 64);
            $table->string('event_id', 128);
            $table->string('merchant_order_id', 128);
            $table->string('channel_transaction_id', 128);
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('event_type', 32);
            $table->string('occurred_at');
            $table->string('decision', 32);
            $table->string('received_at');
            $table->unique(['provider', 'event_id']);
            $table->index(['merchant_order_id', 'occurred_at']);
        });

        Schema::create('provider_payment_fulfillments', function (Blueprint $table): void {
            $table->string('merchant_order_id')->primary();
            $table->string('accepted_event_id');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code')->nullable();
            $table->string('available_at');
            $table->string('fulfilled_at')->nullable();
            $table->foreign('merchant_order_id')->references('merchant_order_id')->on('provider_payment_orders');
            $table->foreign('accepted_event_id')->references('id')->on('provider_payment_events');
        });

        Schema::create('provider_payment_compensations', function (Blueprint $table): void {
            $table->string('merchant_order_id')->primary();
            $table->string('refund_event_id')->unique();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code')->nullable();
            $table->string('available_at');
            $table->string('completed_at')->nullable();
            $table->foreign('merchant_order_id')->references('merchant_order_id')->on('provider_payment_orders');
            $table->foreign('refund_event_id')->references('id')->on('provider_payment_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_payment_compensations');
        Schema::dropIfExists('provider_payment_fulfillments');
        Schema::dropIfExists('provider_payment_events');
        Schema::dropIfExists('provider_payment_orders');
    }
};
