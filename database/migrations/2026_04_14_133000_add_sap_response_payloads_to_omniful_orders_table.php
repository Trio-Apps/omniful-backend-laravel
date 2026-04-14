<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            $table->json('sap_order_response')->nullable()->after('sap_error');
            $table->json('sap_payment_response')->nullable()->after('sap_payment_error');
            $table->json('sap_card_fee_response')->nullable()->after('sap_card_fee_error');
            $table->json('sap_delivery_response')->nullable()->after('sap_delivery_error');
            $table->json('sap_cogs_response')->nullable()->after('sap_cogs_error');
            $table->json('sap_credit_note_response')->nullable()->after('sap_credit_note_error');
            $table->json('sap_cancel_cogs_response')->nullable()->after('sap_cancel_cogs_error');
        });
    }

    public function down(): void
    {
        Schema::table('omniful_orders', function (Blueprint $table) {
            $table->dropColumn([
                'sap_order_response',
                'sap_payment_response',
                'sap_card_fee_response',
                'sap_delivery_response',
                'sap_cogs_response',
                'sap_credit_note_response',
                'sap_cancel_cogs_response',
            ]);
        });
    }
};
