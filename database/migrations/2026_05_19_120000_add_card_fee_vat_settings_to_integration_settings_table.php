<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('integration_settings', 'order_card_fee_vat_percent')) {
                $table->decimal('order_card_fee_vat_percent', 8, 4)->nullable()->after('order_card_fee_percent');
            }

            if (!Schema::hasColumn('integration_settings', 'order_card_fee_vat_recoverable_account')) {
                $table->string('order_card_fee_vat_recoverable_account')->nullable()->after('order_card_fee_vat_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            foreach (['order_card_fee_vat_percent', 'order_card_fee_vat_recoverable_account'] as $column) {
                if (Schema::hasColumn('integration_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
