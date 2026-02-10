<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_return_order_events', function (Blueprint $table) {
            if (!Schema::hasColumn('omniful_return_order_events', 'sap_cogs_reversal_status')) {
                $table->string('sap_cogs_reversal_status')->nullable()->after('sap_error');
            }
            if (!Schema::hasColumn('omniful_return_order_events', 'sap_cogs_reversal_journal_entry')) {
                $table->string('sap_cogs_reversal_journal_entry')->nullable()->after('sap_cogs_reversal_status');
            }
            if (!Schema::hasColumn('omniful_return_order_events', 'sap_cogs_reversal_journal_num')) {
                $table->string('sap_cogs_reversal_journal_num')->nullable()->after('sap_cogs_reversal_journal_entry');
            }
            if (!Schema::hasColumn('omniful_return_order_events', 'sap_cogs_reversal_error')) {
                $table->text('sap_cogs_reversal_error')->nullable()->after('sap_cogs_reversal_journal_num');
            }
        });
    }

    public function down(): void
    {
        Schema::table('omniful_return_order_events', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('omniful_return_order_events', 'sap_cogs_reversal_status')) {
                $columns[] = 'sap_cogs_reversal_status';
            }
            if (Schema::hasColumn('omniful_return_order_events', 'sap_cogs_reversal_journal_entry')) {
                $columns[] = 'sap_cogs_reversal_journal_entry';
            }
            if (Schema::hasColumn('omniful_return_order_events', 'sap_cogs_reversal_journal_num')) {
                $columns[] = 'sap_cogs_reversal_journal_num';
            }
            if (Schema::hasColumn('omniful_return_order_events', 'sap_cogs_reversal_error')) {
                $columns[] = 'sap_cogs_reversal_error';
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

