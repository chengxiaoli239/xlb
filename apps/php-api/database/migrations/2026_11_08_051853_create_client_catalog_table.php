<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_catalog', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('platform', 20);
            $table->string('name', 80);
            $table->string('version', 40)->nullable();
            $table->text('description')->nullable();
            $table->string('download_url', 2048)->nullable();
            $table->string('qr_code_url', 2048)->nullable();
            $table->string('tutorial_url', 2048)->nullable();
            $table->string('compatibility_status', 20)->default('pending');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['platform', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_catalog');
    }
};
