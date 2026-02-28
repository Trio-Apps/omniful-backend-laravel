<?php

namespace App\Services\MasterData;

use App\Models\SapAccountCategory;
use App\Models\SapBank;
use App\Models\SapBankAccount;
use App\Models\SapChartOfAccount;
use App\Models\SapFinancialPeriod;
use App\Models\SapPaymentTerm;
use App\Services\SapServiceLayerClient;

class SapFinanceMasterDataSyncService
{
    /**
     * @return array<string,int>
     */
    public function syncFromSap(SapServiceLayerClient $client): array
    {
        $accounts = $this->syncChartOfAccounts($client);
        $categories = $this->syncAccountCategories($client);
        $periods = $this->syncFinancialPeriods($client);
        $banks = $this->syncBanks($client);
        $bankAccounts = $this->syncBankAccounts($client);
        $paymentTerms = $this->syncPaymentTerms($client);

        return [
            'chart_of_accounts' => $accounts,
            'account_categories' => $categories,
            'financial_periods' => $periods,
            'banks' => $banks,
            'bank_accounts' => $bankAccounts,
            'payment_terms' => $paymentTerms,
            'total' => $accounts + $categories + $periods + $banks + $bankAccounts + $paymentTerms,
        ];
    }

    private function syncChartOfAccounts(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchChartOfAccounts() as $row) {
            $code = trim((string) ($row['Code'] ?? ''));
            if ($code === '') {
                continue;
            }

            SapChartOfAccount::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $this->nullableString($row['Name'] ?? null),
                    'father_account_key' => $this->nullableString($row['FatherAccountKey'] ?? null),
                    'group_mask' => $this->nullableString($row['GroupMask'] ?? null),
                    'is_active' => $this->parseBoolean($row['ActiveAccount'] ?? null, true),
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

    private function syncAccountCategories(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchAccountCategories() as $index => $row) {
            $code = $this->firstScalar($row, ['Category', 'CategoryCode', 'GroupCode', 'Code', 'Value']);
            if ($code === null) {
                $code = 'row-' . ($index + 1);
            }

            SapAccountCategory::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $this->firstScalar($row, ['CategoryName', 'Name', 'Description', 'Value']),
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

    private function syncFinancialPeriods(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchFinancialPeriods() as $index => $row) {
            $code = $this->firstScalar($row, ['AbsoluteEntry', 'PeriodCode', 'Code', 'PeriodCategory', 'Category']);
            if ($code === null) {
                $code = 'row-' . ($index + 1);
            }

            SapFinancialPeriod::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $this->firstScalar($row, ['PeriodName', 'Name', 'SubPeriodName']),
                    'category' => $this->firstScalar($row, ['PeriodCategory', 'Category']),
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

    private function syncBanks(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchBanks() as $row) {
            $code = trim((string) ($row['BankCode'] ?? ''));
            if ($code === '') {
                continue;
            }

            SapBank::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $this->nullableString($row['BankName'] ?? null),
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

    private function syncBankAccounts(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchHouseBankAccounts() as $index => $row) {
            $accountCode = $this->firstScalar($row, ['AccountCode', 'AbsEntry', 'AccountNo']);
            if ($accountCode === null) {
                $accountCode = 'row-' . ($index + 1);
            }

            SapBankAccount::updateOrCreate(
                ['account_code' => $accountCode],
                [
                    'bank_code' => $this->firstScalar($row, ['BankCode']),
                    'account_number' => $this->firstScalar($row, ['AccountNo']),
                    'branch' => $this->firstScalar($row, ['Branch']),
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

    private function syncPaymentTerms(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchPaymentTermsTypes() as $index => $row) {
            $code = $this->firstScalar($row, ['GroupNumber', 'Code']);
            if ($code === null) {
                $code = 'row-' . ($index + 1);
            }

            SapPaymentTerm::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $this->firstScalar($row, ['PaymentTermsGroupName', 'Name']),
                    'additional_days' => $this->nullableInt($row['NumberOfAdditionalDays'] ?? null),
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

    private function parseBoolean(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        if (in_array($normalized, ['tyes', 'yes', 'y', 'true', '1'], true)) {
            return true;
        }

        if (in_array($normalized, ['tno', 'no', 'n', 'false', '0'], true)) {
            return false;
        }

        return $default;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function firstScalar(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
