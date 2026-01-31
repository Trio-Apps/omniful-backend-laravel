<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->string('sap_action')->nullable();
            $table->string('sap_status')->nullable();
            $table->string('sap_doc_entry')->nullable();
            $table->string('sap_doc_num')->nullable();
            $table->text('sap_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_sync_events');
    }
};
