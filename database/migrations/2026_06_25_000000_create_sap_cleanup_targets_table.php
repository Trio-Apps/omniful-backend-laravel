<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_cleanup_targets', function (Blueprint $table) {
            $table->id();
            // One row per AR Reserve Invoice candidate. Persisted worklist for the
            // SAP Item Cleanup page: scan adds rows, then Check / Cancel / Resend.
            $table->unsignedBigInteger('doc_entry')->unique();
            $table->unsignedBigInteger('doc_num')->nullable();
            $table->string('order_external_id')->nullable()->index();
            $table->string('card_code')->nullable();
            $table->decimal('doc_total', 18, 2)->nullable();
            // SAP doc snapshot: bost_Open / bost_Close / cancelled / already_reversed
            $table->string('sap_doc_status')->nullable();
            $table->json('lines')->nullable();
            // Related SAP docs snapshot: payments / deliveries / cogs journals
            $table->json('related')->nullable();
            // Local worklist state: new -> reversed -> resent / failed / skipped
            $table->string('cleanup_state')->default('new')->index();
            $table->string('last_action')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('source_mode')->nullable();
            $table->string('source_value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_cleanup_targets');
    }
};
