<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'omniful_order_events',
            'omniful_return_order_events',
            'omniful_purchase_order_events',
            'omniful_inwarding_events',
            'omniful_inventory_events',
            'omniful_product_events',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                if (!Schema::hasColumn($table->getTable(), 'payload_hash')) {
                    $table->string('payload_hash', 64)->nullable()->unique()->after('payload');
                }
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'omniful_order_events',
            'omniful_return_order_events',
            'omniful_purchase_order_events',
            'omniful_inwarding_events',
            'omniful_inventory_events',
            'omniful_product_events',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                if (Schema::hasColumn($table->getTable(), 'payload_hash')) {
                    $table->dropUnique([$table->getTable() . '_payload_hash_unique']);
                    $table->dropColumn('payload_hash');
                }
            });
        }
    }
};
