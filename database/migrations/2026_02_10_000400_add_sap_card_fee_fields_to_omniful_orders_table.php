<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('omniful_orders', 'sap_card_fee_status')) {
                $table->string('sap_card_fee_status')->nullable()->after('sap_payment_error');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_card_fee_journal_entry')) {
                $table->string('sap_card_fee_journal_entry')->nullable()->after('sap_card_fee_status');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_card_fee_journal_num')) {
                $table->string('sap_card_fee_journal_num')->nullable()->after('sap_card_fee_journal_entry');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_card_fee_error')) {
                $table->text('sap_card_fee_error')->nullable()->after('sap_card_fee_journal_num');
            }
        });
    }

    public function down(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('omniful_orders', 'sap_card_fee_status')) {
                $columns[] = 'sap_card_fee_status';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_card_fee_journal_entry')) {
                $columns[] = 'sap_card_fee_journal_entry';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_card_fee_journal_num')) {
                $columns[] = 'sap_card_fee_journal_num';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_card_fee_error')) {
                $columns[] = 'sap_card_fee_error';
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

