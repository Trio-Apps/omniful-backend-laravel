<?php

namespace App\Console\Commands;

use App\Models\SapBankingDocument;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class CreateSapCheckForPayment extends Command
{
    protected $signature = 'sap:create-check-for-payment
        {bank_code : SAP bank code}
        {customer_account_code : SAP customer account code}
        {country_code : Country code}
        {amount : Check amount}
        {--vendor-code= : Optional vendor code}
        {--account-number= : Optional account number}
        {--branch= : Optional branch}
        {--details= : Optional check details}
        {--card-or-account=cfp_Account : SAP CardOrAccount enum value}
        {--doc-date= : Optional local tracking date (YYYY-MM-DD)}';

    protected $description = 'Create a direct SAP check for payment and store a local banking-document snapshot.';

    public function handle(SapServiceLayerClient $client): int
    {
        $bankCode = trim((string) $this->argument('bank_code'));
        $customerAccountCode = trim((string) $this->argument('customer_account_code'));
        $countryCode = trim((string) $this->argument('country_code'));
        $amount = (float) $this->argument('amount');
        $vendorCode = trim((string) $this->option('vendor-code'));
        $accountNumber = trim((string) $this->option('account-number'));
        $branch = trim((string) $this->option('branch'));
        $details = trim((string) $this->option('details'));
        $cardOrAccount = trim((string) $this->option('card-or-account'));
        $docDate = trim((string) $this->option('doc-date'));

        try {
            $result = $client->createCheckForPayment([
                'bank_code' => $bankCode,
                'customer_account_code' => $customerAccountCode,
                'country_code' => $countryCode,
                'amount' => $amount,
                'vendor_code' => $vendorCode,
                'account_number' => $accountNumber,
                'branch' => $branch,
                'details' => $details,
                'card_or_account' => $cardOrAccount,
            ]);

            if (($result['ignored'] ?? false) === true) {
                $this->warn((string) ($result['reason'] ?? 'Check for payment ignored'));

                return self::SUCCESS;
            }

            $docEntry = (string) ($result['CheckKey'] ?? $result['CheckAbsEntry'] ?? $result['AbsEntry'] ?? '');
            $docNum = (string) ($result['CheckNumber'] ?? $result['CheckNum'] ?? '');
            $effectiveDate = $docDate !== '' ? $docDate : now()->toDateString();

            if ($docEntry !== '') {
                SapBankingDocument::updateOrCreate(
                    [
                        'document_type' => 'check_for_payment',
                        'doc_entry' => $docEntry,
                    ],
                    [
                        'doc_num' => $docNum !== '' ? $docNum : null,
                        'reference_code' => $bankCode,
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
                    ['BankCode', $bankCode],
                    ['CustomerAccountCode', $customerAccountCode],
                    ['CountryCode', $countryCode],
                    ['Amount', (string) $amount],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
