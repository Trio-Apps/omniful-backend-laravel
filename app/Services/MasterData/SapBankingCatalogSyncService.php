<?php

namespace App\Services\MasterData;

use App\Models\SapBankingDocument;
use App\Services\SapServiceLayerClient;
use Illuminate\Support\Carbon;

class SapBankingCatalogSyncService
{
    /**
     * @return array<string,int>
     */
    public function syncFromSap(SapServiceLayerClient $client): array
    {
        $summary = [
            'deposits' => $this->syncDocumentType(
                $client->fetchDepositsDocuments(),
                'deposit'
            ),
            'checks_for_payment' => $this->syncDocumentType(
                $client->fetchChecksForPaymentDocuments(),
                'check_for_payment'
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
                ?? $row['DepositNum']
                ?? $row['CheckKey']
                ?? $row['CheckAbsEntry']
                ?? $row['AbsEntry']
                ?? null
            ) ?? ('row-' . ($index + 1));

            SapBankingDocument::updateOrCreate(
                [
                    'document_type' => $documentType,
                    'doc_entry' => $docEntry,
                ],
                [
                    'doc_num' => $this->scalarString(
                        $row['DocNum']
                        ?? $row['DepositNum']
                        ?? $row['CheckNumber']
                        ?? $row['CheckNum']
                        ?? null
                    ),
                    'reference_code' => $this->resolveReferenceCode($row, $documentType),
                    'doc_date' => $this->parseDate(
                        $row['DocDate']
                        ?? $row['DepositDate']
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
        if ($documentType === 'deposit') {
            return $this->scalarString($row['DepositAccount'] ?? $row['BankAccount'] ?? null);
        }

        return $this->scalarString($row['VoucherNum'] ?? $row['BankCode'] ?? null);
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
