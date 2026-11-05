<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('family_id');
            $table->unsignedInteger('version');
            $table->string('name', 60);
            $table->string('period_label', 30);
            $table->unsignedInteger('duration_days');
            $table->unsignedInteger('price_cents');
            $table->double('traffic_gb');
            $table->unsignedInteger('max_devices');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['family_id', 'version']);
        });

        Schema::create('entitlements', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('plan_id');
            $table->string('status');
            $table->string('starts_at');
            $table->string('ends_at');
            $table->double('data_used_gb')->default(0);
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('plan_id')->references('id')->on('plans');
        });

        Schema::create('nodes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('region');
            $table->string('protocol');
            $table->string('endpoint_host');
            $table->unsignedInteger('endpoint_port');
            $table->string('status');
            $table->boolean('is_test')->default(true);
            $table->unsignedInteger('max_sessions')->default(0);
            $table->unsignedInteger('active_sessions')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('updated_at');
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('name');
            $table->string('token_hash')->unique();
            $table->string('status');
            $table->string('created_at');
            $table->string('expires_at');
            $table->string('last_used_at')->nullable();
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('plan_id');
            $table->unsignedInteger('total_cents');
            $table->string('status');
            $table->string('created_at');
            $table->index('created_at');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('plan_id')->references('id')->on('plans');
        });

        Schema::create('commission_entries', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_id');
            $table->string('partner_name');
            $table->unsignedInteger('amount_cents');
            $table->string('status');
            $table->string('created_at');
            $table->foreign('order_id')->references('id')->on('orders');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('actor_user_id');
            $table->string('action');
            $table->string('target_type');
            $table->string('target_id');
            $table->string('created_at');
            $table->index('created_at');
            $table->foreign('actor_user_id')->references('id')->on('users');
        });

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('category');
            $table->string('subject');
            $table->string('status');
            $table->string('created_at');
            $table->string('updated_at');
            $table->string('resolved_at')->nullable();
            $table->index(['user_id', 'updated_at']);
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('support_ticket_events', function (Blueprint $table): void {
            $table->id('sequence');
            $table->string('id')->unique();
            $table->string('ticket_id');
            $table->string('actor_user_id');
            $table->string('kind');
            $table->text('body')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('created_at');
            $table->index(['ticket_id', 'created_at']);
            $table->foreign('ticket_id')->references('id')->on('support_tickets');
            $table->foreign('actor_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        foreach (['support_ticket_events', 'support_tickets', 'audit_logs', 'commission_entries', 'orders', 'subscriptions', 'nodes', 'entitlements', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
