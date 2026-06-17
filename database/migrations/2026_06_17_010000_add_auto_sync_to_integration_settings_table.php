<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            // Scheduled SAP -> Omniful master-data sync (pull from SAP + push to
            // Omniful) for items and suppliers, controlled from the dashboard.
            $table->boolean('auto_sync_enabled')->default(false)->after('return_cogs_reversal_enabled');
            $table->boolean('auto_sync_items_enabled')->default(true)->after('auto_sync_enabled');
            $table->boolean('auto_sync_suppliers_enabled')->default(true)->after('auto_sync_items_enabled');
            $table->unsignedInteger('auto_sync_interval_minutes')->default(15)->after('auto_sync_suppliers_enabled');
            $table->timestamp('auto_sync_last_run_at')->nullable()->after('auto_sync_interval_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn([
                'auto_sync_enabled',
                'auto_sync_items_enabled',
                'auto_sync_suppliers_enabled',
                'auto_sync_interval_minutes',
                'auto_sync_last_run_at',
            ]);
        });
    }
};
