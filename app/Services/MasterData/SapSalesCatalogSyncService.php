<?php

namespace App\Services\MasterData;

use App\Models\SapItemGroup;
use App\Models\SapSalesDocument;
use App\Services\SapServiceLayerClient;
use Illuminate\Support\Carbon;

class SapSalesCatalogSyncService
{
    /**
     * @return array<string,int>
     */
    public function syncFromSap(SapServiceLayerClient $client): array
    {
        $summary = [
            'item_groups' => $this->syncItemGroups($client->fetchItemGroups()),
            'quotations' => $this->syncDocumentType($client->fetchQuotations(), 'quotation'),
            'returns' => $this->syncDocumentType($client->fetchReturnsDocuments(), 'return'),
        ];

        $summary['total'] = array_sum($summary);
        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function syncItemGroups(array $rows): int
    {
        $count = 0;

        foreach ($rows as $index => $row) {
            $code = $this->scalarString($row['Number'] ?? null) ?? ('row-' . ($index + 1));

            SapItemGroup::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $this->scalarString($row['GroupName'] ?? null),
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
     * @param array<int,array<string,mixed>> $rows
     */
    private function syncDocumentType(array $rows, string $documentType): int
    {
        $count = 0;

        foreach ($rows as $index => $row) {
            $docEntry = $this->scalarString($row['DocEntry'] ?? null)
                ?? $this->scalarString($row['DocumentEntry'] ?? null)
                ?? ('row-' . ($index + 1));

            SapSalesDocument::updateOrCreate(
                [
                    'document_type' => $documentType,
                    'doc_entry' => $docEntry,
                ],
                [
                    'doc_num' => $this->scalarString($row['DocNum'] ?? $row['DocumentNumber'] ?? null),
                    'card_code' => $this->scalarString($row['CardCode'] ?? null),
                    'doc_date' => $this->parseDate($row['DocDate'] ?? null),
                    'amount' => $this->parseAmount($row['DocTotal'] ?? null),
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

    private function scalarString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    private function parseAmount(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
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
