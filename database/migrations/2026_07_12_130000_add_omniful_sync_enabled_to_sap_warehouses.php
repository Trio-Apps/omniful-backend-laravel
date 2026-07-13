<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-managed switch (SAP Warehouses page "Push Quantities" toggle) for
     * whether inventory quantities are pushed to Omniful for a warehouse.
     * Defaults true; turn a warehouse off to exclude it from the quantity push.
     * Does not affect the warehouse master-data sync.
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
