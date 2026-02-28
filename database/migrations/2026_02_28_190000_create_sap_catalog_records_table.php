<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_catalog_records', function (Blueprint $table) {
            $table->id();
            $table->string('resource');
            $table->string('module');
            $table->string('sap_path');
            $table->string('external_key');
            $table->string('name')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['resource', 'external_key']);
            $table->index(['module', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_catalog_records');
    }
};
