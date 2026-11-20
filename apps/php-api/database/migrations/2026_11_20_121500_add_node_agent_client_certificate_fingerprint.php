<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('agent_client_cert_sha256', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropUnique(['agent_client_cert_sha256']);
            $table->dropColumn('agent_client_cert_sha256');
        });
    }
};
