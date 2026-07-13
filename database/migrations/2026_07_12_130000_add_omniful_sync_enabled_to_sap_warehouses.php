<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-managed switch for whether a SAP warehouse's config is pushed to
     * Omniful (hub sync). Defaults true so existing behaviour is unchanged; the
     * SAP Warehouses page lets you exclude specific warehouses.
     */
    public function up(): void
    {
        if (Schema::hasColumn('sap_warehouses', 'omniful_sync_enabled')) {
            return;
        }

        Schema::table('sap_warehouses', function (Blueprint $table) {
            $table->boolean('omniful_sync_enabled')->default(true)->after('code');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('sap_warehouses', 'omniful_sync_enabled')) {
            return;
        }

        Schema::table('sap_warehouses', function (Blueprint $table) {
            $table->dropColumn('omniful_sync_enabled');
        });
    }
};
