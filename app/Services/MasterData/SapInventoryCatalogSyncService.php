<?php

namespace App\Services\MasterData;

use App\Models\SapInventoryDocument;
use App\Services\SapServiceLayerClient;
use Illuminate\Support\Carbon;

class SapInventoryCatalogSyncService
{
    /**
     * @return array<string,int>
     */
    public function syncFromSap(SapServiceLayerClient $client): array
    {
        $summary = [
            'inventory_transfer_requests' => $this->syncDocumentType(
                $client->fetchInventoryTransferRequests(),
                'inventory_transfer_request'
            ),
            'inventory_counting' => $this->syncDocumentType(
                $client->fetchInventoryCountings(),
                'inventory_counting'
            ),
            'inventory_posting' => $this->syncDocumentType(
                $client->fetchInventoryPostings(),
                'inventory_posting'
            ),
            'production_orders' => $this->syncDocumentType(
                $client->fetchProductionOrdersCatalog(),
                'production_order'
            ),
        ];

        $summary['total'] = array_sum($summary);
        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function syncDocumentType(array $rows, string $documentType): int
    {
        $count = 0;

        foreach ($rows as $index => $row) {
            $docEntry = $this->scalarString(
                $row['DocEntry']
                ?? $row['DocumentEntry']
                ?? $row['AbsoluteEntry']
                ?? null
            ) ?? ('row-' . ($index + 1));

            SapInventoryDocument::updateOrCreate(
                [
                    'document_type' => $documentType,
                    'doc_entry' => $docEntry,
                ],
                [
                    'doc_num' => $this->scalarString($row['DocNum'] ?? $row['DocumentNumber'] ?? null),
                    'reference_code' => $this->resolveReferenceCode($row, $documentType),
                    'doc_date' => $this->parseDate(
                        $row['DocDate']
                        ?? $row['CountDate']
                        ?? $row['PostingDate']
                        ?? $row['DueDate']
                        ?? null
                    ),
                    'payload' => $row,
                    'synced_at' => now(),
                    'status' => 'synced',
                    'error' => null,
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveReferenceCode(array $row, string $documentType): ?string
    {
        if ($documentType === 'inventory_transfer_request') {
            $from = $this->scalarString($row['FromWarehouse'] ?? null);
            $to = $this->scalarString($row['ToWarehouse'] ?? null);
            if ($from !== null || $to !== null) {
                return trim(($from ?? '-') . ' -> ' . ($to ?? '-'));
            }
        }

        if ($documentType === 'production_order') {
            return $this->scalarString($row['ItemNo'] ?? null);
        }

        return $this->scalarString($row['Remarks'] ?? null);
    }

    private function scalarString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    private function parseDate(mixed $value): ?string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
