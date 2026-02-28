<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_inventory_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_type');
            $table->string('doc_entry');
            $table->string('doc_num')->nullable();
            $table->string('reference_code')->nullable();
            $table->date('doc_date')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['document_type', 'doc_entry']);
            $table->index(['document_type', 'doc_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_inventory_documents');
    }
};
