<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('omniful_orders', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('omniful_status')->nullable();
            $table->string('sap_status')->nullable();
            $table->string('last_event_type')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->json('last_payload')->nullable();
            $table->json('last_headers')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omniful_orders');
    }
};
