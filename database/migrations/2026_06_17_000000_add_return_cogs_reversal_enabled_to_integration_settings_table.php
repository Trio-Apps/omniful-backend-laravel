<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            // UI toggle controlling the COGS reversal on returns and order
            // cancellations (replaces the OMNIFUL_RETURN_COGS_REVERSAL_ENABLED
            // env flag, which now only acts as a fallback when this is null).
            $table->boolean('return_cogs_reversal_enabled')->default(true)->after('order_cogs_inventory_offset_account');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn('return_cogs_reversal_enabled');
        });
    }
};
