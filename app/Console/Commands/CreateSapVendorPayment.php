<?php

namespace App\Console\Commands;

use App\Models\SapFinanceDocument;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class CreateSapVendorPayment extends Command
{
    protected $signature = 'sap:create-vendor-payment
        {card_code : SAP vendor code}
        {invoice_doc_entry : SAP A/P document DocEntry}
        {amount : Amount to apply}
        {--transfer-account= : SAP transfer account}
        {--invoice-type=18 : SAP invoice type code}
        {--transfer-date= : Optional transfer date (YYYY-MM-DD)}
        {--remarks= : Optional SAP remarks}';

    protected $description = 'Create a direct SAP vendor payment and store a local finance-document snapshot.';

    public function handle(SapServiceLayerClient $client): int
    {
        $cardCode = trim((string) $this->argument('card_code'));
        $invoiceDocEntry = (int) $this->argument('invoice_doc_entry');
        $amount = (float) $this->argument('amount');
        $transferAccount = trim((string) $this->option('transfer-account'));
        $invoiceType = (int) $this->option('invoice-type');
        $transferDate = trim((string) $this->option('transfer-date'));
        $remarks = trim((string) $this->option('remarks'));

        try {
            $result = $client->createVendorPayment([
                'card_code' => $cardCode,
                'invoice_doc_entry' => $invoiceDocEntry,
                'sum_applied' => $amount,
                'transfer_account' => $transferAccount,
                'invoice_type' => $invoiceType,
                'transfer_date' => $transferDate !== '' ? $transferDate : null,
                'remarks' => $remarks,
            ]);

            if (($result['ignored'] ?? false) === true) {
                $this->warn((string) ($result['reason'] ?? 'Vendor payment ignored'));

                return self::SUCCESS;
            }

            $docEntry = (string) ($result['DocEntry'] ?? $result['DocumentEntry'] ?? '');
            $docNum = (string) ($result['DocNum'] ?? $result['DocumentNumber'] ?? '');
            $effectiveDate = $transferDate !== '' ? $transferDate : now()->toDateString();

            if ($docEntry !== '') {
                SapFinanceDocument::updateOrCreate(
                    [
                        'document_type' => 'vendor_payment',
                        'doc_entry' => $docEntry,
                    ],
                    [
                        'doc_num' => $docNum !== '' ? $docNum : null,
                        'card_code' => $cardCode,
                        'doc_date' => $effectiveDate,
                        'amount' => $amount,
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
                    ['CardCode', $cardCode],
                    ['Invoice DocEntry', (string) $invoiceDocEntry],
                    ['Amount', (string) $amount],
                    ['InvoiceType', (string) $invoiceType],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
