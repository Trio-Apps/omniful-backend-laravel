<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_return_order_events', function (Blueprint $table) {
            if (!Schema::hasColumn('omniful_return_order_events', 'sap_response')) {
                $table->json('sap_response')->nullable()->after('sap_error');
            }

            if (!Schema::hasColumn('omniful_return_order_events', 'sap_cogs_reversal_response')) {
                $table->json('sap_cogs_reversal_response')->nullable()->after('sap_cogs_reversal_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('omniful_return_order_events', function (Blueprint $table) {
            if (Schema::hasColumn('omniful_return_order_events', 'sap_response')) {
                $table->dropColumn('sap_response');
            }

            if (Schema::hasColumn('omniful_return_order_events', 'sap_cogs_reversal_response')) {
                $table->dropColumn('sap_cogs_reversal_response');
            }
        });
    }
};
