<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('integration_settings', 'order_card_fee_journal_enabled')) {
                $table->boolean('order_card_fee_journal_enabled')->default(true)->after('order_freight_expense_code');
            }

            if (!Schema::hasColumn('integration_settings', 'order_card_fee_expense_account')) {
                $table->string('order_card_fee_expense_account')->nullable()->after('order_card_fee_journal_enabled');
            }

            if (!Schema::hasColumn('integration_settings', 'order_card_fee_offset_account')) {
                $table->string('order_card_fee_offset_account')->nullable()->after('order_card_fee_expense_account');
            }

            if (!Schema::hasColumn('integration_settings', 'order_card_fee_percent')) {
                $table->decimal('order_card_fee_percent', 8, 4)->nullable()->after('order_card_fee_offset_account');
            }

            if (!Schema::hasColumn('integration_settings', 'order_cogs_journal_enabled')) {
                $table->boolean('order_cogs_journal_enabled')->default(true)->after('order_card_fee_percent');
            }

            if (!Schema::hasColumn('integration_settings', 'order_cogs_expense_account')) {
                $table->string('order_cogs_expense_account')->nullable()->after('order_cogs_journal_enabled');
            }

            if (!Schema::hasColumn('integration_settings', 'order_cogs_inventory_offset_account')) {
                $table->string('order_cogs_inventory_offset_account')->nullable()->after('order_cogs_expense_account');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $columns = [
                'order_card_fee_journal_enabled',
                'order_card_fee_expense_account',
                'order_card_fee_offset_account',
                'order_card_fee_percent',
                'order_cogs_journal_enabled',
                'order_cogs_expense_account',
                'order_cogs_inventory_offset_account',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('integration_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
