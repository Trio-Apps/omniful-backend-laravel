<?php

namespace App\Services\MasterData;

use App\Models\SapAccountCategory;
use App\Models\SapBank;
use App\Models\SapBankAccount;
use App\Models\SapChartOfAccount;
use App\Models\SapCurrency;
use App\Models\SapCustomerFinance;
use App\Models\SapExchangeRate;
use App\Models\SapFinancialPeriod;
use App\Models\SapPaymentTerm;
use App\Models\SapBranch;
use App\Models\SapProfitCenter;
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
        $currencies = $this->syncCurrencies($client);
        $exchangeRates = $this->syncExchangeRates($client);
        $paymentTerms = $this->syncPaymentTerms($client);
        $profitCenters = $this->syncProfitCenters($client);
        $branches = $this->syncBranches($client);
        $customerFinance = $this->syncCustomerFinance($client);

        return [
            'chart_of_accounts' => $accounts,
            'account_categories' => $categories,
            'financial_periods' => $periods,
            'banks' => $banks,
            'bank_accounts' => $bankAccounts,
            'currencies' => $currencies,
            'exchange_rates' => $exchangeRates,
            'payment_terms' => $paymentTerms,
            'profit_centers' => $profitCenters,
            'branches' => $branches,
            'customer_finance' => $customerFinance,
            'total' => $accounts + $categories + $periods + $banks + $bankAccounts + $currencies + $exchangeRates + $paymentTerms + $profitCenters + $branches + $customerFinance,
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

    private function syncCurrencies(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchCurrenciesList() as $row) {
            $code = trim((string) ($row['Code'] ?? ''));
            if ($code === '') {
                continue;
            }

            SapCurrency::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $this->nullableString($row['Name'] ?? null),
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

    private function syncExchangeRates(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchExchangeRates() as $index => $row) {
            $currencyCode = $this->firstScalar($row, ['Currency', 'CurrencyCode', 'Code']);
            if ($currencyCode === null) {
                continue;
            }

            $rateDate = $this->nullableString($row['RateDate'] ?? null) ?? now()->toDateString();
            $rate = $row['Rate'] ?? null;
            if (!is_numeric($rate)) {
                continue;
            }

            SapExchangeRate::updateOrCreate(
                [
                    'currency_code' => $currencyCode,
                    'rate_date' => $rateDate,
                ],
                [
                    'rate' => (float) $rate,
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

    private function syncProfitCenters(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchProfitCentersCatalog() as $index => $row) {
            $code = $this->firstScalar($row, ['CenterCode', 'Code', 'ProfitCenterCode', 'OcrCode']);
            if ($code === null) {
                $code = 'row-' . ($index + 1);
            }

            SapProfitCenter::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $this->firstScalar($row, ['CenterName', 'Name', 'ProfitCenterName', 'OcrName']),
                    'dimension' => $this->parseDimension($row['InWhichDimension'] ?? $row['DimCode'] ?? null),
                    'is_active' => $this->parseBoolean($row['Active'] ?? null, true),
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

    private function syncBranches(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchBranchesCatalog() as $index => $row) {
            $code = $this->firstScalar($row, ['Code', 'BPLID']);
            if ($code === null) {
                $code = 'row-' . ($index + 1);
            }

            SapBranch::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $this->firstScalar($row, ['Name', 'BPLName']),
                    'is_disabled' => $this->parseBoolean($row['Disabled'] ?? null, false),
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

    private function syncCustomerFinance(SapServiceLayerClient $client): int
    {
        $count = 0;
        foreach ($client->fetchCustomerFinanceSnapshots() as $index => $row) {
            $customerCode = $this->firstScalar($row, ['CardCode']);
            if ($customerCode === null) {
                $customerCode = 'row-' . ($index + 1);
            }

            SapCustomerFinance::updateOrCreate(
                ['customer_code' => $customerCode],
                [
                    'customer_name' => $this->firstScalar($row, ['CardName']),
                    'currency_code' => $this->firstScalar($row, ['Currency', 'DocCurrency']),
                    'balance' => is_numeric($row['Balance'] ?? null) ? (float) $row['Balance'] : null,
                    'current_balance' => is_numeric($row['CurrentAccountBalance'] ?? null) ? (float) $row['CurrentAccountBalance'] : null,
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

    private function parseDimension(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $dimension = (int) $value;
            return $dimension > 0 ? $dimension : null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\d+/', $normalized, $matches) === 1) {
            $dimension = (int) $matches[0];
            return $dimension > 0 ? $dimension : null;
        }

        return null;
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
