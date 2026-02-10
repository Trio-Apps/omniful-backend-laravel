<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('omniful_orders', 'sap_payment_status')) {
                $table->string('sap_payment_status')->nullable()->after('sap_error');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_payment_doc_entry')) {
                $table->string('sap_payment_doc_entry')->nullable()->after('sap_payment_status');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_payment_doc_num')) {
                $table->string('sap_payment_doc_num')->nullable()->after('sap_payment_doc_entry');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_payment_error')) {
                $table->text('sap_payment_error')->nullable()->after('sap_payment_doc_num');
            }
        });
    }

    public function down(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('omniful_orders', 'sap_payment_status')) {
                $columns[] = 'sap_payment_status';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_payment_doc_entry')) {
                $columns[] = 'sap_payment_doc_entry';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_payment_doc_num')) {
                $columns[] = 'sap_payment_doc_num';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_payment_error')) {
                $columns[] = 'sap_payment_error';
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

