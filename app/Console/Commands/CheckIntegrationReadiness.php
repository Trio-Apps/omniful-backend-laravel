<?php

namespace App\Console\Commands;

use App\Models\IntegrationSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class CheckIntegrationReadiness extends Command
{
    protected $signature = 'integration:check-readiness {--export=docs/integration-readiness-check.md}';

    protected $description = 'Check operational readiness for BRS close-out and production validation.';

    public function handle(): int
    {
        $checks = collect($this->checks());

        $this->table(
            ['Check', 'Status', 'Note'],
            $checks->map(fn (array $row) => [
                $row['title'],
                strtoupper($row['status']),
                $row['note'],
            ])->all()
        );

        $summary = $checks->countBy('status');
        $this->line(
            'ready=' . (int) ($summary['ready'] ?? 0)
            . ' | warning=' . (int) ($summary['warning'] ?? 0)
            . ' | missing=' . (int) ($summary['missing'] ?? 0)
            . ' | info=' . (int) ($summary['info'] ?? 0)
        );

        $export = trim((string) $this->option('export'));
        if ($export !== '') {
            $absolute = base_path($export);
            $dir = dirname($absolute);
            if (!is_dir($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            File::put($absolute, $this->toMarkdown($checks));
            $this->info('Exported readiness report to ' . $export);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,array{title:string,status:string,note:string}>
     */
    private function checks(): array
    {
        $settings = IntegrationSetting::query()->first();
        $queueConnection = trim((string) config('queue.default'));

        $orderPaymentEnabled = (bool) config('omniful.order_payment.enabled');
        $transferAccount = trim((string) config('omniful.order_payment.transfer_account'));

        $cardFeeEnabled = (bool) config('omniful.order_payment.card_fee_journal_enabled');
        $cardFeeExpense = trim((string) config('omniful.order_payment.card_fee_expense_account'));
        $cardFeeOffset = trim((string) config('omniful.order_payment.card_fee_offset_account'));
        $cardFeePercent = (float) config('omniful.order_payment.card_fee_percent');

        $cogsEnabled = (bool) config('omniful.order_accounting.cogs_journal_enabled');
        $returnCogsEnabled = (bool) config('omniful.order_accounting.return_cogs_reversal_enabled');
        $cogsExpense = trim((string) config('omniful.order_accounting.cogs_expense_account'));
        $inventoryOffset = trim((string) config('omniful.order_accounting.inventory_offset_account'));

        $checks = [];

        $checks[] = $this->checkRow(
            'Integration settings row',
            $settings !== null,
            'Integration settings record is available',
            'No integration settings row found'
        );

        $checks[] = $this->checkRow(
            'SAP connection fields',
            $settings !== null
                && $this->filled($settings->sap_service_layer_url ?? null)
                && $this->filled($settings->sap_company_db ?? null)
                && $this->filled($settings->sap_username ?? null)
                && $this->filled($settings->sap_password ?? null),
            'SAP URL, company DB, username, and password are present',
            'One or more SAP connection fields are missing'
        );

        $checks[] = $this->checkRow(
            'Omniful connection fields',
            $settings !== null
                && $this->filled($settings->omniful_api_url ?? null)
                && (
                    $this->filled($settings->omniful_access_token ?? null)
                    || $this->filled($settings->omniful_seller_access_token ?? null)
                ),
            'Omniful API URL and at least one access token are present',
            'Omniful URL or access tokens are missing'
        );

        $checks[] = $this->checkRow(
            'Webhook secret configuration',
            $settings !== null
                && (
                    $this->filled($settings->omniful_webhook_secret ?? null)
                    || $this->filled($settings->omniful_seller_webhook_secret ?? null)
                ),
            'At least one webhook secret is configured',
            'No Omniful webhook secret is configured'
        );

        $checks[] = $this->checkRow(
            'Queue connection',
            $queueConnection !== '' && $queueConnection !== 'sync',
            'Queue connection is `' . ($queueConnection !== '' ? $queueConnection : '-') . '`',
            'Queue connection is empty or still using `sync`'
        );

        $checks[] = $this->checkRow(
            '2026 integration tables',
            $this->requiredTablesPresent(),
            'Core 2026 integration tables are present',
            'One or more required integration tables are missing'
        );

        $checks[] = $this->configRow(
            'Incoming payment transfer account',
            !$orderPaymentEnabled || $transferAccount !== '',
            $orderPaymentEnabled
                ? 'Order payment is enabled and transfer account is configured'
                : 'Order payment feature is disabled',
            $orderPaymentEnabled
                ? 'Order payment is enabled but transfer account is missing'
                : 'Order payment feature is disabled'
        );

        $checks[] = $this->configRow(
            'Card Fees JE configuration',
            !$cardFeeEnabled || ($cardFeeExpense !== '' && $cardFeeOffset !== '' && $cardFeePercent > 0),
            $cardFeeEnabled
                ? 'Card fee JE is enabled with accounts and percentage configured'
                : 'Card fee JE is disabled',
            $cardFeeEnabled
                ? 'Card fee JE is enabled but accounts or percentage are incomplete'
                : 'Card fee JE is disabled'
        );

        $checks[] = $this->configRow(
            'COGS JE configuration',
            !$cogsEnabled || ($cogsExpense !== '' && $inventoryOffset !== ''),
            $cogsEnabled
                ? 'COGS JE is enabled with required accounts configured'
                : 'COGS JE is disabled',
            $cogsEnabled
                ? 'COGS JE is enabled but required accounts are missing'
                : 'COGS JE is disabled'
        );

        $checks[] = $this->configRow(
            'Return COGS reversal configuration',
            !$returnCogsEnabled || ($cogsExpense !== '' && $inventoryOffset !== ''),
            $returnCogsEnabled
                ? 'Return COGS reversal is enabled with required accounts configured'
                : 'Return COGS reversal is disabled',
            $returnCogsEnabled
                ? 'Return COGS reversal is enabled but required accounts are missing'
                : 'Return COGS reversal is disabled'
        );

        $checks[] = [
            'title' => 'Cron / queue worker',
            'status' => 'info',
            'note' => 'Cron and queue workers cannot be verified from code alone; confirm them on the target server',
        ];

        return $checks;
    }

    private function filled(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }

    private function requiredTablesPresent(): bool
    {
        $tables = [
            'integration_settings',
            'omniful_order_events',
            'omniful_return_order_events',
            'omniful_purchase_order_events',
            'omniful_inventory_events',
            'omniful_product_events',
            'omniful_inwarding_events',
            'jobs',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{title:string,status:string,note:string}
     */
    private function checkRow(string $title, bool $ready, string $readyNote, string $missingNote): array
    {
        return [
            'title' => $title,
            'status' => $ready ? 'ready' : 'missing',
            'note' => $ready ? $readyNote : $missingNote,
        ];
    }

    /**
     * @return array{title:string,status:string,note:string}
     */
    private function configRow(string $title, bool $ready, string $readyNote, string $warningNote): array
    {
        return [
            'title' => $title,
            'status' => $ready ? 'ready' : 'warning',
            'note' => $ready ? $readyNote : $warningNote,
        ];
    }

    /**
     * @param Collection<int,array{title:string,status:string,note:string}> $checks
     */
    private function toMarkdown(Collection $checks): string
    {
        $lines = [];
        $lines[] = '# Integration Readiness Check';
        $lines[] = '';
        $lines[] = 'Updated: ' . now()->toDateTimeString();
        $lines[] = '';
        $lines[] = '| Check | Status | Note |';
        $lines[] = '| --- | --- | --- |';

        foreach ($checks as $row) {
            $lines[] = '| '
                . $this->escapeCell($row['title']) . ' | '
                . strtoupper($this->escapeCell($row['status'])) . ' | '
                . $this->escapeCell($row['note']) . ' |';
        }

        $lines[] = '';
        $summary = $checks->countBy('status');
        $lines[] = 'Summary:'
            . ' ready=' . (int) ($summary['ready'] ?? 0)
            . ', warning=' . (int) ($summary['warning'] ?? 0)
            . ', missing=' . (int) ($summary['missing'] ?? 0)
            . ', info=' . (int) ($summary['info'] ?? 0);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function escapeCell(string $value): string
    {
        return str_replace('|', '\|', trim($value));
    }
}
