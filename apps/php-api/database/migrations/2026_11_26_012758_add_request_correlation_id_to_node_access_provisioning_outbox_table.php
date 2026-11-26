<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_access_provisioning_outbox', function (Blueprint $table): void {
            $table->string('request_correlation_id', 36)->nullable();
            $table->index('request_correlation_id');
        });
    }

    public function down(): void
    {
        Schema::table('node_access_provisioning_outbox', function (Blueprint $table): void {
            $table->dropIndex(['request_correlation_id']);
            $table->dropColumn('request_correlation_id');
        });
    }
};
