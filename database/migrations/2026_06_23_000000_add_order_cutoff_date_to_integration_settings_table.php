<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            // Orders whose Omniful creation date is BEFORE this date are ignored
            // outright (no SAP work, no error). Null = no cutoff (process all).
            $table->date('order_cutoff_date')->nullable()->after('order_numeric_id_only');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn('order_cutoff_date');
        });
    }
};
