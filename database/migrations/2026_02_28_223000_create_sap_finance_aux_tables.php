<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code');
            $table->date('rate_date');
            $table->decimal('rate', 18, 8);
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['currency_code', 'rate_date']);
        });

        Schema::create('sap_profit_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->integer('dimension')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_branches', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->boolean('is_disabled')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_customer_finance', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code')->unique();
            $table->string('customer_name')->nullable();
            $table->string('currency_code')->nullable();
            $table->decimal('balance', 18, 4)->nullable();
            $table->decimal('current_balance', 18, 4)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_customer_finance');
        Schema::dropIfExists('sap_branches');
        Schema::dropIfExists('sap_profit_centers');
        Schema::dropIfExists('sap_exchange_rates');
        Schema::dropIfExists('sap_currencies');
    }
};
