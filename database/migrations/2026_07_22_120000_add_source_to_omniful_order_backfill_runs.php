<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order Backfill can now run from an uploaded LIST OF ORDER IDS (source_type =
 * 'id_list'), not just a created-date range ('date_range'). id_list runs pull
 * each id directly from Omniful by its numeric order_id, so the date columns are
 * unused for them and source_label carries the uploaded file name + id count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_order_backfill_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('omniful_order_backfill_runs', 'source_type')) {
                $table->string('source_type')->default('date_range')->after('id');
            }
            if (!Schema::hasColumn('omniful_order_backfill_runs', 'source_label')) {
                $table->string('source_label')->nullable()->after('date_to');
            }
        });
    }

    public function down(): void
    {
        Schema::table('omniful_order_backfill_runs', function (Blueprint $table) {
            foreach (['source_type', 'source_label'] as $col) {
                if (Schema::hasColumn('omniful_order_backfill_runs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
