<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('expires_at')->nullable()->change();
        });

        DB::table('subscriptions')->where('status', 'active')->update(['expires_at' => null]);
    }

    public function down(): void
    {
        DB::table('subscriptions')->whereNull('expires_at')->update(['expires_at' => '9999-12-31T23:59:59+00:00']);

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('expires_at')->nullable(false)->change();
        });
    }
};
