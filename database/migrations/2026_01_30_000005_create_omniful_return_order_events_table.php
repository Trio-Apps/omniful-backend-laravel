<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('omniful_return_order_events', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->index();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omniful_return_order_events');
    }
};
