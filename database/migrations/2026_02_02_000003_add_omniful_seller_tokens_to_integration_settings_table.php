<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->text('omniful_seller_api_key')->nullable()->after('omniful_access_token');
            $table->text('omniful_seller_api_secret')->nullable()->after('omniful_seller_api_key');
            $table->text('omniful_seller_webhook_secret')->nullable()->after('omniful_seller_api_secret');
            $table->text('omniful_seller_refresh_token')->nullable()->after('omniful_seller_webhook_secret');
            $table->text('omniful_seller_access_token')->nullable()->after('omniful_seller_refresh_token');
            $table->bigInteger('omniful_seller_token_expires_in')->nullable()->after('omniful_token_expires_in');
            $table->timestamp('omniful_seller_access_token_expires_at')->nullable()->after('omniful_access_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn([
                'omniful_seller_api_key',
                'omniful_seller_api_secret',
                'omniful_seller_webhook_secret',
                'omniful_seller_refresh_token',
                'omniful_seller_access_token',
                'omniful_seller_token_expires_in',
                'omniful_seller_access_token_expires_at',
            ]);
        });
    }
};
