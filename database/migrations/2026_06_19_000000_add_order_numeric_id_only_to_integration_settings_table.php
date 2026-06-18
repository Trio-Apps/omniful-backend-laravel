<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            // When true, only orders with a fully numeric order id are pushed to
            // SAP; non-numeric ids (STO_..., RS_234, ...) are ignored.
            $table->boolean('order_numeric_id_only')->default(true)->after('po_ignored_supplier_codes');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn('order_numeric_id_only');
        });
    }
};
