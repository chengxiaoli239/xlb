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
        Schema::table('client_compatibility_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('recorded_order')->default(0);
        });

        $clientIds = DB::table('client_compatibility_runs')->distinct()->pluck('client_id');
        foreach ($clientIds as $clientId) {
            $runIds = DB::table('client_compatibility_runs')->where('client_id', $clientId)
                ->orderBy('created_at')->orderBy('id')->pluck('id');
            foreach ($runIds as $index => $runId) {
                DB::table('client_compatibility_runs')->where('client_id', $clientId)->where('id', $runId)
                    ->update(['recorded_order' => $index + 1]);
            }
        }

        Schema::table('client_compatibility_runs', function (Blueprint $table): void {
            $table->unique(['client_id', 'recorded_order'], 'client_compatibility_runs_client_order_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_compatibility_runs', function (Blueprint $table): void {
            $table->dropUnique('client_compatibility_runs_client_order_unique');
            $table->dropColumn('recorded_order');
        });
    }
};
