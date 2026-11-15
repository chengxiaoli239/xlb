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
        Schema::create('member_devices', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('installation_id', 36);
            $table->string('name', 80);
            $table->string('platform', 20);
            $table->string('status')->default('registered');
            $table->string('revoked_at')->nullable();
            $table->string('created_at');
            $table->string('updated_at');
            $table->unique(['user_id', 'installation_id']);
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_devices');
    }
};
