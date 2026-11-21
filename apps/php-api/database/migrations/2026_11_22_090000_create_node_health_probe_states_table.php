<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_health_probe_states', function (Blueprint $table): void {
            $table->string('node_id')->primary();
            $table->string('status', 16);
            $table->unsignedInteger('failure_streak')->default(0);
            $table->unsignedInteger('success_streak')->default(0);
            $table->string('last_probe_at')->nullable();
            $table->index('last_probe_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_health_probe_states');
    }
};
