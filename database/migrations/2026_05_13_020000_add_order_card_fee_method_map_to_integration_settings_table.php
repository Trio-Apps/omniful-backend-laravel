<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('integration_settings', 'order_card_fee_method_percent_map')) {
                $table->text('order_card_fee_method_percent_map')->nullable()->after('order_card_fee_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            if (Schema::hasColumn('integration_settings', 'order_card_fee_method_percent_map')) {
                $table->dropColumn('order_card_fee_method_percent_map');
            }
        });
    }
};
