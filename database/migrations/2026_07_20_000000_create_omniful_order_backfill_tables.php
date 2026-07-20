<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order Backfill: pull orders from Omniful by created-date range and enqueue any
 * that are missing from our DB. One `run` row per operator request, plus one
 * `day` row per calendar date in the range for the per-day monitoring UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('omniful_order_backfill_runs')) {
            Schema::create('omniful_order_backfill_runs', function (Blueprint $table) {
                $table->id();
                $table->date('date_from');
                $table->date('date_to');
                // queued | running | cancel_requested | cancelled | completed | failed
                $table->string('status')->default('queued')->index();
                // Omniful cursor (meta.end_cursor) for resume/continuation.
                $table->text('cursor')->nullable();
                $table->unsignedBigInteger('scanned')->default(0);   // orders seen in the range
                $table->unsignedBigInteger('existing')->default(0);  // already in our DB
                $table->unsignedBigInteger('missing')->default(0);   // not in our DB
                $table->unsignedBigInteger('enqueued')->default(0);  // dispatched to the order queue
                $table->unsignedBigInteger('pages')->default(0);     // list pages fetched
                $table->unsignedInteger('rate_limit_hits')->default(0);
                $table->text('last_error')->nullable();
                $table->string('last_activity')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('omniful_order_backfill_days')) {
            Schema::create('omniful_order_backfill_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('run_id')->constrained('omniful_order_backfill_runs')->cascadeOnDelete();
                $table->date('day');
                $table->unsignedBigInteger('total')->default(0);
                $table->unsignedBigInteger('existing')->default(0);
                $table->unsignedBigInteger('missing')->default(0);
                $table->unsignedBigInteger('enqueued')->default(0);
                $table->timestamps();
                $table->unique(['run_id', 'day']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('omniful_order_backfill_days');
        Schema::dropIfExists('omniful_order_backfill_runs');
    }
};
