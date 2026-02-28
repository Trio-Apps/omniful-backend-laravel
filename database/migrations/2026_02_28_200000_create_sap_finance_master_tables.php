<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('father_account_key')->nullable();
            $table->string('group_mask')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_account_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_financial_periods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_banks', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code')->unique();
            $table->string('bank_code')->nullable()->index();
            $table->string('account_number')->nullable();
            $table->string('branch')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->integer('additional_days')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_payment_terms');
        Schema::dropIfExists('sap_bank_accounts');
        Schema::dropIfExists('sap_banks');
        Schema::dropIfExists('sap_financial_periods');
        Schema::dropIfExists('sap_account_categories');
        Schema::dropIfExists('sap_chart_of_accounts');
    }
};
