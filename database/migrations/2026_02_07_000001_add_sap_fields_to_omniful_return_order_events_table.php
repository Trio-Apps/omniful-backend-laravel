<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_return_order_events', function (Blueprint $table) {
            $table->string('sap_status')->nullable();
            $table->string('sap_doc_entry')->nullable();
            $table->string('sap_doc_num')->nullable();
            $table->text('sap_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('omniful_return_order_events', function (Blueprint $table) {
            $table->dropColumn(['sap_status', 'sap_doc_entry', 'sap_doc_num', 'sap_error']);
        });
    }
};
