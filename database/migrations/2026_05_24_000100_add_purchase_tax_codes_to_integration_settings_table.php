<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('integration_settings', 'purchase_tax_code_ksa_taxable')) {
                $table->string('purchase_tax_code_ksa_taxable')->nullable()->after('order_freight_expense_code');
            }
            if (!Schema::hasColumn('integration_settings', 'purchase_tax_code_ksa_zero')) {
                $table->string('purchase_tax_code_ksa_zero')->nullable()->after('purchase_tax_code_ksa_taxable');
            }
            if (!Schema::hasColumn('integration_settings', 'purchase_tax_code_foreign')) {
                $table->string('purchase_tax_code_foreign')->nullable()->after('purchase_tax_code_ksa_zero');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            foreach (['purchase_tax_code_ksa_taxable', 'purchase_tax_code_ksa_zero', 'purchase_tax_code_foreign'] as $column) {
                if (Schema::hasColumn('integration_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
