<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track orders the backfill deliberately SKIPS (no-op statuses like on_hold /
 * picked / packed) so total = existing + missing + skipped and those orders no
 * longer inflate "missing" or get pulled/enqueued for nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('omniful_order_backfill_runs') && !Schema::hasColumn('omniful_order_backfill_runs', 'skipped')) {
            Schema::table('omniful_order_backfill_runs', function (Blueprint $table) {
                $table->unsignedBigInteger('skipped')->default(0)->after('missing');
            });
        }

        if (Schema::hasTable('omniful_order_backfill_days') && !Schema::hasColumn('omniful_order_backfill_days', 'skipped')) {
            Schema::table('omniful_order_backfill_days', function (Blueprint $table) {
                $table->unsignedBigInteger('skipped')->default(0)->after('missing');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('omniful_order_backfill_runs', 'skipped')) {
            Schema::table('omniful_order_backfill_runs', fn (Blueprint $table) => $table->dropColumn('skipped'));
        }
        if (Schema::hasColumn('omniful_order_backfill_days', 'skipped')) {
            Schema::table('omniful_order_backfill_days', fn (Blueprint $table) => $table->dropColumn('skipped'));
        }
    }
};
