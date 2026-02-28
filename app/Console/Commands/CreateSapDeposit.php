<?php

namespace App\Console\Commands;

use App\Models\SapBankingDocument;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class CreateSapDeposit extends Command
{
    protected $signature = 'sap:create-deposit
        {abs_id : Existing SAP credit line AbsId}
        {deposit_account : SAP deposit account}
        {voucher_account : SAP voucher account}
        {--deposit-type=dtCredit : SAP deposit type}
        {--doc-date= : Optional local tracking date (YYYY-MM-DD)}';

    protected $description = 'Create a direct SAP deposit and store a local banking-document snapshot.';

    public function handle(SapServiceLayerClient $client): int
    {
        $absId = (int) $this->argument('abs_id');
        $depositAccount = trim((string) $this->argument('deposit_account'));
        $voucherAccount = trim((string) $this->argument('voucher_account'));
        $depositType = trim((string) $this->option('deposit-type'));
        $docDate = trim((string) $this->option('doc-date'));

        try {
            $result = $client->createDeposit([
                'abs_id' => $absId,
                'deposit_account' => $depositAccount,
                'voucher_account' => $voucherAccount,
                'deposit_type' => $depositType,
            ]);

            if (($result['ignored'] ?? false) === true) {
                $this->warn((string) ($result['reason'] ?? 'Deposit ignored'));

                return self::SUCCESS;
            }

            $docEntry = (string) ($result['DocEntry'] ?? $result['DepositNum'] ?? '');
            $docNum = (string) ($result['DocNum'] ?? $result['DepositNum'] ?? '');
            $effectiveDate = $docDate !== '' ? $docDate : now()->toDateString();

            if ($docEntry !== '') {
                SapBankingDocument::updateOrCreate(
                    [
                        'document_type' => 'deposit',
                        'doc_entry' => $docEntry,
                    ],
                    [
                        'doc_num' => $docNum !== '' ? $docNum : null,
                        'reference_code' => $depositAccount,
                        'doc_date' => $effectiveDate,
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
                    ['AbsId', (string) $absId],
                    ['DepositAccount', $depositAccount],
                    ['VoucherAccount', $voucherAccount],
                    ['DepositType', $depositType],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
