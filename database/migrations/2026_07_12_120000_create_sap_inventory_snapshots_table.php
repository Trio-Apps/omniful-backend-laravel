<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local snapshot of the last quantity we pushed to Omniful, keyed by SAP
     * warehouse + item. Delta runs compare the freshly-read SAP Available
     * quantity against this row and push ONLY the changed ones; a Full run
     * ignores it and pushes everything. Powers the SAP -> Omniful Inventory
     * Quantity Push feature.
     */
    public function up(): void
    {
        Schema::create('sap_inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('warehouse_code');           // SAP WarehouseCode
            $table->string('item_code');                // SAP ItemCode == Omniful sku_code
            $table->string('hub_code')->nullable();     // resolved Omniful hub_code at push time
            $table->bigInteger('quantity')->default(0); // last quantity pushed to Omniful (Available)
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_code', 'item_code']);
            $table->index('item_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_inventory_snapshots');
    }
};
