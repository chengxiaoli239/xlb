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
        Schema::table('node_deployments', function (Blueprint $table): void {
            $table->string('provider_profile', 96)->default('aws-us-west-2-lab-v1');
            $table->unsignedInteger('provider_profile_version')->default(1);
            $table->string('provider_account_id', 32)->nullable();
        });

        $accountId = (string) config('node_automation.profiles.aws-us-west-2-lab-v1.account_id', '');
        DB::table('node_deployments')->whereNull('provider_account_id')->update(['provider_account_id' => $accountId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('node_deployments', function (Blueprint $table): void {
            $table->dropColumn(['provider_profile', 'provider_profile_version', 'provider_account_id']);
        });
    }
};
