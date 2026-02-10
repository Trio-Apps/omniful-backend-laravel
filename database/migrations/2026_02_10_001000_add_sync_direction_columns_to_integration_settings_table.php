<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->string('sync_direction_items')->default('sap_to_omniful')->after('omniful_seller_access_token_expires_at');
            $table->string('sync_direction_suppliers')->default('sap_to_omniful')->after('sync_direction_items');
            $table->string('sync_direction_warehouses')->default('sap_to_omniful')->after('sync_direction_suppliers');
            $table->string('sync_direction_inventory')->default('omniful_to_sap')->after('sync_direction_warehouses');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn([
                'sync_direction_items',
                'sync_direction_suppliers',
                'sync_direction_warehouses',
                'sync_direction_inventory',
            ]);
        });
    }
};
