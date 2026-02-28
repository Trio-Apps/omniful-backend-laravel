<?php

namespace App\Services\MasterData;

use App\Models\SapFinanceDocument;
use App\Services\SapServiceLayerClient;
use Illuminate\Support\Carbon;

class SapFinanceDocumentSyncService
{
    /**
     * @return array<string,int>
     */
    public function syncFromSap(SapServiceLayerClient $client): array
    {
        $summary = [
            'ar_invoices' => $this->syncDocumentType($client->fetchArInvoices(), 'ar_invoice', 'DocTotal'),
            'ar_credit_notes' => $this->syncDocumentType($client->fetchArCreditNotes(), 'ar_credit_note', 'DocTotal'),
            'ar_down_payments' => $this->syncDocumentType($client->fetchArDownPayments(), 'ar_down_payment', 'DocTotal'),
            'incoming_payments' => $this->syncDocumentType($client->fetchIncomingPaymentsDocuments(), 'incoming_payment', 'TransferSum'),
            'ap_invoices' => $this->syncDocumentType($client->fetchApInvoices(), 'ap_invoice', 'DocTotal'),
            'ap_credit_notes' => $this->syncDocumentType($client->fetchApCreditNotes(), 'ap_credit_note', 'DocTotal'),
            'ap_down_payments' => $this->syncDocumentType($client->fetchApDownPayments(), 'ap_down_payment', 'DocTotal'),
            'vendor_payments' => $this->syncDocumentType($client->fetchVendorPaymentsDocuments(), 'vendor_payment', 'TransferSum'),
        ];

        $summary['total'] = array_sum($summary);
        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function syncDocumentType(array $rows, string $documentType, string $amountKey): int
    {
        $count = 0;

        foreach ($rows as $index => $row) {
            $docEntry = $this->scalarString($row['DocEntry'] ?? null)
                ?? $this->scalarString($row['DocumentEntry'] ?? null)
                ?? ('row-' . ($index + 1));

            SapFinanceDocument::updateOrCreate(
                [
                    'document_type' => $documentType,
                    'doc_entry' => $docEntry,
                ],
                [
                    'doc_num' => $this->scalarString($row['DocNum'] ?? $row['DocumentNumber'] ?? null),
                    'card_code' => $this->scalarString($row['CardCode'] ?? null),
                    'doc_date' => $this->parseDate($row['DocDate'] ?? $row['PostingDate'] ?? null),
                    'amount' => $this->parseAmount($row[$amountKey] ?? null),
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
