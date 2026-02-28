<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_inwarding_events', function (Blueprint $table) {
            if (!Schema::hasColumn('omniful_inwarding_events', 'sap_status')) {
                $table->string('sap_status')->nullable()->after('signature_valid');
            }
            if (!Schema::hasColumn('omniful_inwarding_events', 'sap_doc_entry')) {
                $table->string('sap_doc_entry')->nullable()->after('sap_status');
            }
            if (!Schema::hasColumn('omniful_inwarding_events', 'sap_doc_num')) {
                $table->string('sap_doc_num')->nullable()->after('sap_doc_entry');
            }
            if (!Schema::hasColumn('omniful_inwarding_events', 'sap_error')) {
                $table->text('sap_error')->nullable()->after('sap_doc_num');
            }
        });
    }

    public function down(): void
    {
        Schema::table('omniful_inwarding_events', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('omniful_inwarding_events', 'sap_status')) {
                $columns[] = 'sap_status';
            }
            if (Schema::hasColumn('omniful_inwarding_events', 'sap_doc_entry')) {
                $columns[] = 'sap_doc_entry';
            }
            if (Schema::hasColumn('omniful_inwarding_events', 'sap_doc_num')) {
                $columns[] = 'sap_doc_num';
            }
            if (Schema::hasColumn('omniful_inwarding_events', 'sap_error')) {
                $columns[] = 'sap_error';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
