<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->text('order_payment_method_map')->nullable()->after('order_payment_invoice_type_candidates');
            $table->string('order_tax_code_ksa_taxable')->nullable()->after('order_payment_method_map');
            $table->string('order_tax_code_ksa_zero')->nullable()->after('order_tax_code_ksa_taxable');
            $table->string('order_tax_code_foreign')->nullable()->after('order_tax_code_ksa_zero');
            $table->string('order_freight_expense_code')->nullable()->after('order_tax_code_foreign');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn([
                'order_payment_method_map',
                'order_tax_code_ksa_taxable',
                'order_tax_code_ksa_zero',
                'order_tax_code_foreign',
                'order_freight_expense_code',
            ]);
        });
    }
};
