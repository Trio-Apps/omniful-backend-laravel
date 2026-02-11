<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_cost_center_settings', function (Blueprint $table) {
            $table->id();
            $table->string('costing_code')->nullable();
            $table->string('costing_code2')->nullable();
            $table->string('costing_code3')->nullable();
            $table->string('costing_code4')->nullable();
            $table->string('costing_code5')->nullable();
            $table->string('project_code')->nullable();
            $table->boolean('apply_to_stock_transfer')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_cost_center_settings');
    }
};

