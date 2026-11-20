<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('member_device_id')->nullable()->unique();
            $table->unsignedInteger('credential_generation')->default(1);
            $table->string('rotated_at')->nullable();
            $table->foreign('member_device_id')->references('id')->on('member_devices');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['member_device_id']);
            $table->dropUnique(['member_device_id']);
            $table->dropColumn(['member_device_id', 'credential_generation', 'rotated_at']);
        });
    }
};
