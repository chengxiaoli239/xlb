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
            $table->string('client_version', 40)->nullable();
            $table->string('last_seen_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('member_devices', function (Blueprint $table): void {
            $table->dropColumn(['client_version', 'last_seen_at']);
        });
    }
};
