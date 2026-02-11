<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100);
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('dimension')->nullable();
            $table->string('source', 20)->default('distribution_rule');
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'dimension', 'code'], 'sap_cost_centers_unique');
            $table->index(['source', 'dimension', 'is_active'], 'sap_cost_centers_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_cost_centers');
    }
};

