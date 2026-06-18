<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            // Supplier codes whose PO/GRPO webhooks are ignored (not created in
            // SAP). Comma/space separated list, managed from the Settings page.
            $table->text('po_ignored_supplier_codes')->nullable()->after('auto_sync_last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn('po_ignored_supplier_codes');
        });
    }
};
