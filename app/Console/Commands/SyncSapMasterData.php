<?php

namespace App\Console\Commands;

use App\Models\SapSupplier;
use App\Models\SapWarehouse;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class SyncSapMasterData extends Command
{
    protected $signature = 'sap:sync-master {--warehouses} {--suppliers}';

    protected $description = 'Sync SAP master data (warehouses and suppliers) into local tables';

    public function handle(SapServiceLayerClient $client): int
    {
        $syncWarehouses = (bool) $this->option('warehouses');
        $syncSuppliers = (bool) $this->option('suppliers');
        if (!$syncWarehouses && !$syncSuppliers) {
            $syncWarehouses = true;
            $syncSuppliers = true;
        }

        if ($syncWarehouses) {
            $this->info('Syncing SAP warehouses...');
            $warehouses = $client->fetchWarehouses();
            foreach ($warehouses as $row) {
                $code = $row['WarehouseCode'] ?? null;
                if (!$code) {
                    continue;
                }
                SapWarehouse::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $row['WarehouseName'] ?? null,
                        'payload' => $row,
                        'synced_at' => now(),
                        'status' => 'synced',
                        'error' => null,
                    ]
                );
            }
        }

        if ($syncSuppliers) {
            $this->info('Syncing SAP suppliers...');
            $suppliers = $client->fetchSuppliers();
            foreach ($suppliers as $row) {
                $code = $row['CardCode'] ?? null;
                if (!$code) {
                    continue;
                }
                SapSupplier::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $row['CardName'] ?? null,
                        'email' => $row['EmailAddress'] ?? null,
                        'phone' => $row['Phone1'] ?? null,
                        'payload' => $row,
                        'synced_at' => now(),
                        'status' => 'synced',
                        'error' => null,
                    ]
                );
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
