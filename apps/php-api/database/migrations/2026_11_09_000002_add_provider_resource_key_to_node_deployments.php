<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_deployments', function (Blueprint $table): void {
            $table->string('provider_resource_key')->nullable();
        });

        DB::table('node_deployments')->update([
            'provider_resource_key' => DB::raw('stack_name'),
        ]);

        Schema::table('node_deployments', function (Blueprint $table): void {
            $table->dropUnique('node_deployments_stack_name_unique');
            $table->unique(['provider', 'provider_resource_key'], 'node_deployments_provider_resource_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('node_deployments', function (Blueprint $table): void {
            $table->dropUnique('node_deployments_provider_resource_key_unique');
            $table->unique('stack_name');
            $table->dropColumn('provider_resource_key');
        });
    }
};
