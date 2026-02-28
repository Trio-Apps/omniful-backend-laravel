<?php

namespace App\Console\Commands;

use App\Models\SapInventoryDocument;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class CreateSapProductionOrder extends Command
{
    protected $signature = 'sap:create-production-order
        {item_code : SAP item code / Omniful SKU code}
        {quantity : Planned quantity}
        {--warehouse= : Optional SAP warehouse code}
        {--due-date= : Optional due date (YYYY-MM-DD)}
        {--remarks= : Optional SAP remarks}';

    protected $description = 'Create a SAP production order directly and store a local production-order snapshot.';

    public function handle(SapServiceLayerClient $client): int
    {
        $itemCode = trim((string) $this->argument('item_code'));
        $quantity = (float) $this->argument('quantity');
        $warehouse = trim((string) $this->option('warehouse'));
        $dueDate = trim((string) $this->option('due-date'));
        $remarks = trim((string) $this->option('remarks'));

        try {
            $result = $client->createProductionOrder(
                $itemCode,
                $quantity,
                $warehouse !== '' ? $warehouse : null,
                $dueDate !== '' ? $dueDate : null,
                $remarks
            );

            $docEntry = (string) ($result['AbsoluteEntry'] ?? $result['DocEntry'] ?? $result['DocumentEntry'] ?? '');
            $docNum = (string) ($result['DocumentNumber'] ?? $result['DocNum'] ?? '');
            $postingDate = $dueDate !== '' ? $dueDate : now()->toDateString();

            if ($docEntry !== '') {
                SapInventoryDocument::updateOrCreate(
                    [
                        'document_type' => 'production_order',
                        'doc_entry' => $docEntry,
                    ],
                    [
                        'doc_num' => $docNum !== '' ? $docNum : null,
                        'reference_code' => $itemCode,
                        'doc_date' => $postingDate,
                        'payload' => $result,
                        'synced_at' => now(),
                        'status' => 'created',
                        'error' => null,
                    ]
                );
            }

            $this->table(
                ['Field', 'Value'],
                [
                    ['DocEntry', $docEntry !== '' ? $docEntry : '-'],
                    ['DocNum', $docNum !== '' ? $docNum : '-'],
                    ['ItemCode', $itemCode],
                    ['Quantity', (string) $quantity],
                    ['Warehouse', $warehouse !== '' ? $warehouse : '-'],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
