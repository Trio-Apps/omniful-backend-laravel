<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('omniful_orders', 'sap_credit_note_status')) {
                $table->string('sap_credit_note_status')->nullable()->after('sap_cogs_error');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_credit_note_doc_entry')) {
                $table->string('sap_credit_note_doc_entry')->nullable()->after('sap_credit_note_status');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_credit_note_doc_num')) {
                $table->string('sap_credit_note_doc_num')->nullable()->after('sap_credit_note_doc_entry');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_credit_note_error')) {
                $table->text('sap_credit_note_error')->nullable()->after('sap_credit_note_doc_num');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_cancel_cogs_status')) {
                $table->string('sap_cancel_cogs_status')->nullable()->after('sap_credit_note_error');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_cancel_cogs_journal_entry')) {
                $table->string('sap_cancel_cogs_journal_entry')->nullable()->after('sap_cancel_cogs_status');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_cancel_cogs_journal_num')) {
                $table->string('sap_cancel_cogs_journal_num')->nullable()->after('sap_cancel_cogs_journal_entry');
            }
            if (!Schema::hasColumn('omniful_orders', 'sap_cancel_cogs_error')) {
                $table->text('sap_cancel_cogs_error')->nullable()->after('sap_cancel_cogs_journal_num');
            }
        });
    }

    public function down(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('omniful_orders', 'sap_credit_note_status')) {
                $columns[] = 'sap_credit_note_status';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_credit_note_doc_entry')) {
                $columns[] = 'sap_credit_note_doc_entry';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_credit_note_doc_num')) {
                $columns[] = 'sap_credit_note_doc_num';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_credit_note_error')) {
                $columns[] = 'sap_credit_note_error';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_cancel_cogs_status')) {
                $columns[] = 'sap_cancel_cogs_status';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_cancel_cogs_journal_entry')) {
                $columns[] = 'sap_cancel_cogs_journal_entry';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_cancel_cogs_journal_num')) {
                $columns[] = 'sap_cancel_cogs_journal_num';
            }
            if (Schema::hasColumn('omniful_orders', 'sap_cancel_cogs_error')) {
                $columns[] = 'sap_cancel_cogs_error';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
