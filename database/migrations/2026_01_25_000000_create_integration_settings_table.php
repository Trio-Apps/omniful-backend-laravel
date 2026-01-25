<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('sap_service_layer_url')->nullable();
            $table->string('sap_company_db')->nullable();
            $table->string('sap_username')->nullable();
            $table->text('sap_password')->nullable();
            $table->boolean('sap_ssl_verify')->default(true);
            $table->string('omniful_api_url')->nullable();
            $table->text('omniful_api_key')->nullable();
            $table->text('omniful_api_secret')->nullable();
            $table->text('omniful_webhook_secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_settings');
    }
};
