<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            $table->index('sap_status', 'omniful_orders_sap_status_index');
            $table->index('created_at', 'omniful_orders_created_at_index');
            $table->index('last_event_at', 'omniful_orders_last_event_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            $table->dropIndex('omniful_orders_sap_status_index');
            $table->dropIndex('omniful_orders_created_at_index');
            $table->dropIndex('omniful_orders_last_event_at_index');
        });
    }
};
