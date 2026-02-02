<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('omniful_token_expires_in')->nullable();
            $table->timestamp('omniful_access_token_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn(['omniful_token_expires_in', 'omniful_access_token_expires_at']);
        });
    }
};
