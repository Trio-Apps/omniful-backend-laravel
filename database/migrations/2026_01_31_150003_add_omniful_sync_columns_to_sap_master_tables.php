<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sap_warehouses', function (Blueprint $table) {
            $table->string('omniful_status')->nullable();
            $table->text('omniful_error')->nullable();
            $table->timestamp('omniful_synced_at')->nullable();
        });

        Schema::table('sap_suppliers', function (Blueprint $table) {
            $table->string('omniful_status')->nullable();
            $table->text('omniful_error')->nullable();
            $table->timestamp('omniful_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sap_warehouses', function (Blueprint $table) {
            $table->dropColumn(['omniful_status', 'omniful_error', 'omniful_synced_at']);
        });

        Schema::table('sap_suppliers', function (Blueprint $table) {
            $table->dropColumn(['omniful_status', 'omniful_error', 'omniful_synced_at']);
        });
    }
};
