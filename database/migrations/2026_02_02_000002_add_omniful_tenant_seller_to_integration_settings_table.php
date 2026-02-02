<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->string('omniful_tenant_code')->nullable()->after('omniful_webhook_secret');
            $table->string('omniful_seller_code')->nullable()->after('omniful_tenant_code');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn(['omniful_tenant_code', 'omniful_seller_code']);
        });
    }
};
