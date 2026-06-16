<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sap_items', function (Blueprint $table) {
            // Exact payload last pushed to Omniful + the response we got back,
            // captured per item so the SAP Items page can show them for debugging.
            $table->json('omniful_payload')->nullable()->after('omniful_synced_at');
            $table->longText('omniful_response')->nullable()->after('omniful_payload');
            $table->integer('omniful_response_code')->nullable()->after('omniful_response');
        });
    }

    public function down(): void
    {
        Schema::table('sap_items', function (Blueprint $table) {
            $table->dropColumn(['omniful_payload', 'omniful_response', 'omniful_response_code']);
        });
    }
};
