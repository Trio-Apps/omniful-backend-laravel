<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->boolean('order_payment_enabled')
                ->default(false)
                ->after('order_fallback_warehouse_code');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn('order_payment_enabled');
        });
    }
};
