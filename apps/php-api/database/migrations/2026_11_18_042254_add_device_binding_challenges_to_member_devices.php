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
        Schema::table('member_devices', function (Blueprint $table): void {
            $table->text('public_key')->nullable();
            $table->string('public_key_fingerprint', 64)->nullable();
            $table->string('bound_at')->nullable();
        });

        Schema::create('device_binding_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id');
            $table->string('installation_id', 36);
            $table->string('name', 80);
            $table->string('platform', 20);
            $table->text('public_key');
            $table->string('public_key_fingerprint', 64);
            $table->string('challenge', 100);
            $table->string('expires_at');
            $table->string('used_at')->nullable();
            $table->string('created_at');
            $table->index(['user_id', 'installation_id']);
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_binding_challenges');
        Schema::table('member_devices', function (Blueprint $table): void {
            $table->dropColumn(['public_key', 'public_key_fingerprint', 'bound_at']);
        });
    }
};
