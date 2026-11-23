<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('member_bridge_endpoint', 2048)->nullable();
            $table->longText('member_bridge_ca_ciphertext')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['member_bridge_endpoint', 'member_bridge_ca_ciphertext']);
        });
    }
};
