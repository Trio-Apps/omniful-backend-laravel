<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sap_cost_center_settings', function (Blueprint $table) {
            $table->string('warehouse_code', 100)->nullable()->after('id');
            $table->unique('warehouse_code', 'sap_cost_center_settings_warehouse_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sap_cost_center_settings', function (Blueprint $table) {
            $table->dropUnique('sap_cost_center_settings_warehouse_code_unique');
            $table->dropColumn('warehouse_code');
        });
    }
};
