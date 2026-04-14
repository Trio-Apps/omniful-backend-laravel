<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->string('order_fallback_customer_code')->nullable()->after('sync_direction_inventory');
            $table->text('order_fallback_customer_code_by_source')->nullable()->after('order_fallback_customer_code');
            $table->string('order_fallback_warehouse_code')->nullable()->after('order_fallback_customer_code_by_source');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn([
                'order_fallback_customer_code',
                'order_fallback_customer_code_by_source',
                'order_fallback_warehouse_code',
            ]);
        });
    }
};
