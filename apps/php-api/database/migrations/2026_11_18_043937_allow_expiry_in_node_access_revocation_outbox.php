<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('node_access_revocation_outbox', function (Blueprint $table): void {
            $table->string('refund_event_id')->nullable()->change();
            $table->string('reason', 40)->default('refund');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('node_access_revocation_outbox')->whereNull('refund_event_id')->exists()) {
            throw new LogicException('Cannot roll back while non-refund revocations exist.');
        }
        Schema::table('node_access_revocation_outbox', function (Blueprint $table): void {
            $table->dropColumn('reason');
            $table->string('refund_event_id')->nullable(false)->change();
        });
    }
};
