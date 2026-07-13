<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-managed controls for the SAP -> Omniful inventory quantity push,
     * so it is configured from the Integration Settings page (like auto-sync)
     * instead of the .env. Disabled by default.
     */
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('integration_settings', 'inventory_push_enabled')) {
                $table->boolean('inventory_push_enabled')->default(false);
            }
            if (!Schema::hasColumn('integration_settings', 'inventory_push_cadence_minutes')) {
                $table->unsignedInteger('inventory_push_cadence_minutes')->default(30);
            }
            if (!Schema::hasColumn('integration_settings', 'inventory_push_mode')) {
                $table->string('inventory_push_mode')->default('delta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            foreach (['inventory_push_enabled', 'inventory_push_cadence_minutes', 'inventory_push_mode'] as $column) {
                if (Schema::hasColumn('integration_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
