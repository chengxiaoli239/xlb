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
        Schema::create('node_health_probe_attempts', function (Blueprint $table): void {
            $table->string('node_id')->primary();
            $table->string('last_attempted_at');
            $table->index('last_attempted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_health_probe_attempts');
    }
};
