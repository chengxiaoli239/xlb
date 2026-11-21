<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anonymous_accounts', function (Blueprint $table): void {
            $table->string('installation_id', 36)->primary();
            $table->string('user_id')->unique();
            $table->char('recovery_secret_hash', 64);
            $table->string('consent_at');
            $table->string('created_at');
            $table->string('updated_at');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anonymous_accounts');
    }
};
