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
            $table->string('provider', 32)->default('aws');
            $table->string('deployment_key')->nullable()->unique();
        });

        DB::table('node_deployments')->select('id')->orderBy('id')->get()->each(function (object $deployment): void {
            DB::table('node_deployments')->where('id', $deployment->id)->update([
                'deployment_key' => 'xlb-node-'.str_replace('-', '', $deployment->id),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('node_deployments', function (Blueprint $table): void {
            $table->dropUnique(['deployment_key']);
            $table->dropColumn(['provider', 'deployment_key']);
        });
    }
};
