<?php

namespace App\Console\Commands;

use App\Models\SapFinanceDocument;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class CreateSapApDocument extends Command
{
    protected $signature = 'sap:create-ap-document
        {type : invoice|credit-note|down-payment}
        {card_code : SAP vendor code}
        {item_code : SAP item code / Omniful SKU code}
        {quantity : Document quantity}
        {--price=0 : Unit price}
        {--warehouse= : Optional warehouse code}
        {--currency= : Optional currency code}
        {--doc-date= : Optional document date (YYYY-MM-DD)}
        {--remarks= : Optional SAP comments}';

    protected $description = 'Create a direct SAP A/P document and store a local finance-document snapshot.';

    public function handle(SapServiceLayerClient $client): int
    {
        $type = (string) $this->argument('type');
        $cardCode = trim((string) $this->argument('card_code'));
        $itemCode = trim((string) $this->argument('item_code'));
        $quantity = (float) $this->argument('quantity');
        $price = (float) $this->option('price');
        $warehouse = trim((string) $this->option('warehouse'));
        $currency = trim((string) $this->option('currency'));
        $docDate = trim((string) $this->option('doc-date'));
        $remarks = trim((string) $this->option('remarks'));

        try {
            $result = $client->createAccountsPayableDocument($type, [
                'card_code' => $cardCode,
                'currency' => $currency !== '' ? $currency : null,
                'doc_date' => $docDate !== '' ? $docDate : null,
                'remarks' => $remarks,
                'items' => [[
                    'item_code' => $itemCode,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'warehouse_code' => $warehouse !== '' ? $warehouse : null,
                ]],
            ]);

            if (($result['ignored'] ?? false) === true) {
                $this->warn((string) ($result['reason'] ?? 'A/P document ignored'));

                return self::SUCCESS;
            }

            $documentType = (string) ($result['document_type'] ?? $this->normalizeDocumentType($type));
            $docEntry = (string) ($result['DocEntry'] ?? $result['DocumentEntry'] ?? '');
            $docNum = (string) ($result['DocNum'] ?? $result['DocumentNumber'] ?? '');
            $effectiveDate = $docDate !== '' ? $docDate : now()->toDateString();

            if ($docEntry !== '') {
                SapFinanceDocument::updateOrCreate(
                    [
                        'document_type' => $documentType,
                        'doc_entry' => $docEntry,
                    ],
                    [
                        'doc_num' => $docNum !== '' ? $docNum : null,
                        'card_code' => $cardCode,
                        'doc_date' => $effectiveDate,
                        'amount' => round($quantity * $price, 4),
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
                    ['Type', $documentType],
                    ['DocEntry', $docEntry !== '' ? $docEntry : '-'],
                    ['DocNum', $docNum !== '' ? $docNum : '-'],
                    ['CardCode', $cardCode],
                    ['ItemCode', $itemCode],
                    ['Quantity', (string) $quantity],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function normalizeDocumentType(string $type): string
    {
        $normalized = strtolower(trim($type));

        return match ($normalized) {
            'invoice' => 'purchase_invoice',
            'credit-note', 'credit_note' => 'purchase_credit_note',
            'down-payment', 'down_payment' => 'purchase_down_payment',
            default => $normalized,
        };
    }
}
