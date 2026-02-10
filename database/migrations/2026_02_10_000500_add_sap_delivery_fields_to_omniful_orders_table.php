<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('omniful_orders', 'sap_delivery_status')) {
                $table->string('sap_delivery_status')->nullable()->after('sap_card_fee_error');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_delivery_doc_entry')) {
                $table->string('sap_delivery_doc_entry')->nullable()->after('sap_delivery_status');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_delivery_doc_num')) {
                $table->string('sap_delivery_doc_num')->nullable()->after('sap_delivery_doc_entry');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_delivery_error')) {
                $table->text('sap_delivery_error')->nullable()->after('sap_delivery_doc_num');
            }
        });
    }

    public function down(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('omniful_orders', 'sap_delivery_status')) {
                $columns[] = 'sap_delivery_status';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_delivery_doc_entry')) {
                $columns[] = 'sap_delivery_doc_entry';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_delivery_doc_num')) {
                $columns[] = 'sap_delivery_doc_num';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_delivery_error')) {
                $columns[] = 'sap_delivery_error';
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

