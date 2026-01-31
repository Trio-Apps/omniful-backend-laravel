<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_inventory_events', function (Blueprint $table) {
            $table->string('sap_status')->nullable()->after('signature_valid');
            $table->string('sap_doc_entry')->nullable()->after('sap_status');
            $table->string('sap_doc_num')->nullable()->after('sap_doc_entry');
            $table->text('sap_error')->nullable()->after('sap_doc_num');
        });
    }

    public function down(): void
    {
        Schema::table('omniful_inventory_events', function (Blueprint $table) {
            $table->dropColumn(['sap_status', 'sap_doc_entry', 'sap_doc_num', 'sap_error']);
        });
    }
};
