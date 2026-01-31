<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_product_events', function (Blueprint $table) {
            $table->string('sap_status')->nullable()->after('signature_valid');
            $table->string('sap_item_code')->nullable()->after('sap_status');
            $table->text('sap_error')->nullable()->after('sap_item_code');
        });
    }

    public function down(): void
    {
        Schema::table('omniful_product_events', function (Blueprint $table) {
            $table->dropColumn(['sap_status', 'sap_item_code', 'sap_error']);
        });
    }
};
