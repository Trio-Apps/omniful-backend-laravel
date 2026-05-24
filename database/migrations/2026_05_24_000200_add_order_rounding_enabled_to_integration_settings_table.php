<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('integration_settings', 'order_rounding_enabled')) {
                $table->boolean('order_rounding_enabled')->default(false)->after('order_freight_expense_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            if (Schema::hasColumn('integration_settings', 'order_rounding_enabled')) {
                $table->dropColumn('order_rounding_enabled');
            }
        });
    }
};
