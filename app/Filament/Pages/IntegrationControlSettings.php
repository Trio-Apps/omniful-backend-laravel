<?php

namespace App\Filament\Pages;

use App\Models\IntegrationSetting;
use App\Models\SapSyncEvent;
use App\Models\SapBankAccount;
use App\Models\SapCostCenter;
use App\Models\SapCostCenterSetting;
use App\Jobs\RunSapCostCenterBackgroundSync;
use App\Services\IntegrationDirectionService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

class IntegrationControlSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Integration Settings';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1000;

    protected string $view = 'filament.pages.integration-control-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(array_merge(
            IntegrationSetting::first()?->toArray() ?? [],
            SapCostCenterSetting::query()->whereNull('warehouse_code')->first()?->toArray() ?? []
        ));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sync Direction')
                    ->description('Only the Warehouse master-data sync is active (Omniful → SAP). Item and Supplier syncs are turned off; Inventory continues via its own real-time webhook flow.')
                    ->schema([
                        Select::make('sync_direction_warehouses')
                            ->label('Warehouses / Hubs')
                            ->options(app(IntegrationDirectionService::class)->options())
                            ->default(IntegrationDirectionService::OMNIFUL_TO_SAP)
                            ->helperText('Warehouses/hubs are pushed from Omniful into SAP. Existing warehouses in SAP are updated (already-created are not duplicated).')
                            ->required(),
                    ]),
                Section::make('Warehouse Cost Centers')
                    ->description('Warehouse-specific cost center mapping is managed from the dedicated Warehouse Cost Centers page.')
                    ->schema([
                        Toggle::make('apply_to_stock_transfer')
                            ->label('Apply cost centers on Stock Transfer lines')
                            ->default(false),
                    ]),
                Section::make('Order Payments')
                    ->description('Configure incoming payment defaults using SAP bank accounts pulled from the finance master catalog')
                    ->schema([
                        Toggle::make('order_payment_enabled')
                            ->label('Enable Incoming Payments')
                            ->default(false)
                            ->helperText('Disable temporarily to stop creating SAP incoming payments from Omniful sales orders.'),
                        Textarea::make('order_payment_method_map')
                            ->label('Payment Method Mapping')
                            ->rows(5)
                            ->placeholder("tamara:CC\ntabby:CC\nvisa:CC\nmaster:CC")
                            ->helperText('Format: omniful_method:sap_transfer_account, one pair per line or comma-separated.'),
                    ]),
                Section::make('Order Tax & Freight')
                    ->description('Static sales document mapping defaults used when Omniful payloads are processed into SAP')
                    ->schema([
                        TextInput::make('order_tax_code_ksa_taxable')
                            ->label('KSA Taxable Tax Code')
                            ->placeholder('SOV'),
                        TextInput::make('order_tax_code_ksa_zero')
                            ->label('KSA Zero-Tax Code')
                            ->placeholder('EOV'),
                        TextInput::make('order_tax_code_foreign')
                            ->label('Foreign Tax Code')
                            ->placeholder('EOV'),
                        TextInput::make('order_freight_expense_code')
                            ->label('Freight Expense Code (Domestic)')
                            ->placeholder('1')
                            ->helperText('SAP freight ExpenseCode for DOMESTIC (KSA) customers. Used when shipping or delivery fee exists in Omniful payload.'),
                        TextInput::make('order_freight_expense_code_foreign')
                            ->label('Freight Expense Code (Foreign)')
                            ->placeholder('2')
                            ->helperText('SAP freight ExpenseCode for FOREIGN customers. Falls back to the domestic code when empty.'),
                        Toggle::make('order_rounding_enabled')
                            ->label('Enable SAP Document Rounding')
                            ->default(false)
                            ->helperText('Sends Rounding=tYES so SAP rounds the invoice total to a clean 2-dp figure (eliminates the sub-cent freight VAT tail / Payment-on-Account). REQUIRES the SAP Rounding G/L Account to be configured first (Administration → Setup → Financials → G/L Account Determination → Sales → General → Rounding Account). Leave OFF until that account is set, otherwise SAP rejects the invoice.'),
                    ]),
                Section::make('Purchase Order Tax')
                    ->description('Input VAT codes for Purchase Orders / GRPO. Leave blank to use each SAP Item\'s default purchase tax group. Purchase documents need INPUT VAT codes (e.g. SIV/EIV), not the sales OUTPUT codes above.')
                    ->schema([
                        TextInput::make('purchase_tax_code_ksa_taxable')
                            ->label('KSA Taxable Purchase Tax Code')
                            ->placeholder('e.g. SIV (leave blank for item default)'),
                        TextInput::make('purchase_tax_code_ksa_zero')
                            ->label('KSA Zero Purchase Tax Code')
                            ->placeholder('e.g. EIV (leave blank for item default)'),
                        TextInput::make('purchase_tax_code_foreign')
                            ->label('Foreign Purchase Tax Code')
                            ->placeholder('e.g. EIV (leave blank for item default)'),
                    ]),
                Section::make('Order Journal Entries')
                    ->description('Configure SAP journal entries created after payment and delivery.')
                    ->schema([
                        Toggle::make('order_card_fee_journal_enabled')
                            ->label('Enable Card Fee Journal')
                            ->default(true)
                            ->helperText('Creates a commission/card-fee journal after incoming payment is created.'),
                        TextInput::make('order_card_fee_expense_account')
                            ->label('Card Fee Expense Account')
                            ->default(config('omniful.order_payment.card_fee_expense_account'))
                            ->placeholder('2102001'),
                        TextInput::make('order_card_fee_offset_account')
                            ->label('Card Fee Offset Account')
                            ->placeholder('Credit account'),
                        TextInput::make('order_card_fee_percent')
                            ->label('Card Fee Percent')
                            ->numeric()
                            ->placeholder('Example: 2.5')
                            ->helperText('Fallback only when no payment-method fee percent is configured.'),
                        TextInput::make('order_card_fee_vat_percent')
                            ->label('Card Fee VAT Percent')
                            ->numeric()
                            ->placeholder('15')
                            ->helperText('VAT charged by the payment gateway on the card fee (ZATCA standard 15%).'),
                        TextInput::make('order_card_fee_vat_recoverable_account')
                            ->label('Card Fee Input VAT Account')
                            ->placeholder('Input VAT recoverable G/L account')
                            ->helperText('When set, the card-fee journal splits gross into expense + input VAT (3 lines). Leave blank for the legacy 2-line journal.'),
                        Textarea::make('order_card_fee_method_percent_map')
                            ->label('Card Fee by Payment Method')
                            ->rows(8)
                            ->default("tamara:4\ntabby:4\ntapkeynet:1.5\ntapmada:0.9\ntapcreditcard:2\ntapapplepay:1.5\ntabbyaddon:1\ntamaraaddon:1.5\nzidpaymada:0.75\nzidpayvisa:1.75")
                            ->placeholder("tamara:4%+1.5\ntabby:4\ntapkeynet:1.5|0.25\nzidpayvisa:1.75")
                            ->helperText('Format: method:percent or method:percent%+fixed_amount. Examples: tamara:4, tamara:4%+1.5, tapkeynet:1.5|0.25. This drives Enable Card Fee Journal only.'),
                        Toggle::make('order_cogs_journal_enabled')
                            ->label('Enable COGS Journal')
                            ->default(true)
                            ->helperText('Creates COGS journal after SAP Delivery is created.'),
                        TextInput::make('order_cogs_expense_account')
                            ->label('COGS Expense Account')
                            ->placeholder('Debit account'),
                        TextInput::make('order_cogs_inventory_offset_account')
                            ->label('Inventory Offset Account')
                            ->placeholder('Credit account'),
                        Toggle::make('return_cogs_reversal_enabled')
                            ->label('Enable COGS Reversal on Returns / Cancellations')
                            ->default(true)
                            ->helperText('Reverses the COGS journal when a return or order-cancel credit memo is created. Uses the same COGS accounts above.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        // Items / Suppliers / Inventory direction selectors are no longer
        // shown on the form (only Warehouses is). Preserve their existing
        // stored values instead of overwriting them with null. Order config
        // is saved onto the ACTIVE environment profile.
        $existing = IntegrationSetting::active();

        $payload =
            [
                'sync_direction_items' => $state['sync_direction_items']
                    ?? $existing?->sync_direction_items,
                'sync_direction_suppliers' => $state['sync_direction_suppliers']
                    ?? $existing?->sync_direction_suppliers,
                'sync_direction_warehouses' => $state['sync_direction_warehouses']
                    ?? $existing?->sync_direction_warehouses,
                'sync_direction_inventory' => $state['sync_direction_inventory']
                    ?? $existing?->sync_direction_inventory,
                'order_payment_enabled' => (bool) ($state['order_payment_enabled'] ?? false),
                'order_payment_method_map' => $state['order_payment_method_map'] ?? null,
                'order_tax_code_ksa_taxable' => $state['order_tax_code_ksa_taxable'] ?? null,
                'order_tax_code_ksa_zero' => $state['order_tax_code_ksa_zero'] ?? null,
                'order_tax_code_foreign' => $state['order_tax_code_foreign'] ?? null,
                'order_freight_expense_code' => $state['order_freight_expense_code'] ?? null,
                'order_freight_expense_code_foreign' => $state['order_freight_expense_code_foreign'] ?? null,
                'order_rounding_enabled' => (bool) ($state['order_rounding_enabled'] ?? false),
                'purchase_tax_code_ksa_taxable' => $state['purchase_tax_code_ksa_taxable'] ?? null,
                'purchase_tax_code_ksa_zero' => $state['purchase_tax_code_ksa_zero'] ?? null,
                'purchase_tax_code_foreign' => $state['purchase_tax_code_foreign'] ?? null,
                'order_card_fee_journal_enabled' => (bool) ($state['order_card_fee_journal_enabled'] ?? false),
                'order_card_fee_expense_account' => $state['order_card_fee_expense_account'] ?? null,
                'order_card_fee_offset_account' => $state['order_card_fee_offset_account'] ?? null,
                'order_card_fee_percent' => $state['order_card_fee_percent'] ?? null,
                'order_card_fee_vat_percent' => $state['order_card_fee_vat_percent'] ?? null,
                'order_card_fee_vat_recoverable_account' => $state['order_card_fee_vat_recoverable_account'] ?? null,
                'order_card_fee_method_percent_map' => $state['order_card_fee_method_percent_map'] ?? null,
                'order_cogs_journal_enabled' => (bool) ($state['order_cogs_journal_enabled'] ?? false),
                'order_cogs_expense_account' => $state['order_cogs_expense_account'] ?? null,
                'order_cogs_inventory_offset_account' => $state['order_cogs_inventory_offset_account'] ?? null,
                'return_cogs_reversal_enabled' => (bool) ($state['return_cogs_reversal_enabled'] ?? false),
            ];

        if ($existing !== null) {
            $existing->update($payload);
        } else {
            IntegrationSetting::create(array_merge(
                ['environment' => 'production', 'is_active' => true],
                $payload
            ));
        }

        SapCostCenterSetting::query()->updateOrCreate(
            ['warehouse_code' => null],
            [
                'apply_to_stock_transfer' => (bool) ($state['apply_to_stock_transfer'] ?? false),
            ]
        );

        $this->form->fill(array_merge(
            IntegrationSetting::first()?->toArray() ?? [],
            SapCostCenterSetting::query()->whereNull('warehouse_code')->first()?->toArray() ?? []
        ));

        Notification::make()
            ->title('Integration settings saved')
            ->success()
            ->send();
    }

    public function syncSapCostCenters(SapServiceLayerClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        try {
            $event = SapSyncEvent::query()->updateOrCreate(
                ['event_key' => 'sap.cost_centers.sync'],
                [
                    'source_type' => 'sap_catalog',
                    'source_id' => null,
                    'sap_action' => 'sync_cost_centers',
                    'sap_status' => 'pending',
                    'sap_error' => null,
                    'payload' => [
                        'queued_at' => now()->toDateTimeString(),
                    ],
                ]
            );

            Notification::make()
                ->title('SAP cost center sync queued')
                ->body('The sync is running in background.')
                ->success()
                ->send();

            RunSapCostCenterBackgroundSync::dispatch($event->id);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('SAP cost center sync failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function getDistributionRuleOptions(int $dimension): array
    {
        $exact = SapCostCenter::query()
            ->whereIn('source', ['distribution_rule', 'profit_center'])
            ->where('dimension', $dimension)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (SapCostCenter $row) => [
                $row->code => $row->code . ' - ' . ($row->name ?: $row->code),
            ])
            ->all();

        if ($exact !== []) {
            return $exact;
        }

        // Some SAP setups don't expose dimension field consistently via Service Layer.
        // In that case, show uncategorized active rules instead of empty selects.
        return SapCostCenter::query()
            ->whereIn('source', ['distribution_rule', 'profit_center'])
            ->whereNull('dimension')
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (SapCostCenter $row) => [
                $row->code => $row->code . ' - ' . ($row->name ?: $row->code),
            ])
            ->all();
    }

    private function getProjectOptions(): array
    {
        return SapCostCenter::query()
            ->where('source', 'project')
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (SapCostCenter $row) => [
                $row->code => $row->code . ' - ' . ($row->name ?: $row->code),
            ])
            ->all();
    }

    private function getBankAccountOptions(): array
    {
        return SapBankAccount::query()
            ->orderBy('bank_code')
            ->orderBy('account_code')
            ->get()
            ->mapWithKeys(fn (SapBankAccount $row) => [
                $this->resolveBankTransferAccountValue($row) => trim(implode(' | ', array_filter([
                    $this->resolveBankTransferAccountValue($row),
                    $row->bank_code,
                    $row->account_number,
                    data_get($row->payload, 'AccountName'),
                ], fn ($value) => is_string($value) && trim($value) !== ''))),
            ])
            ->all();
    }

    private function resolveBankTransferAccountValue(SapBankAccount $row): string
    {
        $glAccount = trim((string) data_get($row->payload, 'GLAccount', ''));

        return $glAccount !== '' ? $glAccount : $row->account_code;
    }

    private function getIncomingPaymentInvoiceTypeOptions(): array
    {
        return [
            '13' => 'it_Invoice (13)',
            '14' => 'it_CredItnote (14)',
            '15' => 'it_TaxInvoice (15)',
            '16' => 'it_Return (16)',
            '18' => 'it_PurchaseInvoice (18)',
            '19' => 'it_PurchaseCreditNote (19)',
            '20' => 'it_PurchaseDeliveryNote (20)',
            '21' => 'it_PurchaseReturn (21)',
            '24' => 'it_Receipt (24)',
            '25' => 'it_Deposit (25)',
            '30' => 'it_JournalEntry (30)',
            '46' => 'it_PaymentAdvice (46)',
            '57' => 'it_ChequesForPayment (57)',
            '58' => 'it_StockReconciliations (58)',
            '59' => 'it_GeneralReceiptToStock (59)',
            '60' => 'it_GeneralReleaseFromStock (60)',
            '67' => 'it_TransferBetweenWarehouses (67)',
            '68' => 'it_WorkInstructions (68)',
            '76' => 'it_DeferredDeposit (76)',
            '132' => 'it_CorrectionInvoice (132)',
            '163' => 'it_APCorrectionInvoice (163)',
            '165' => 'it_ARCorrectionInvoice (165)',
            '203' => 'it_DownPayment (203)',
            '204' => 'it_PurchaseDownPayment (204)',
        ];
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('save')
                ->label('Save')
                ->action('save')
                ->color('primary')
                ->extraAttributes([
                    'style' => 'background-color: #226d64; color: #ffffff;',
                ])
                ->keyBindings(['mod+s']),
            Action::make('syncSapCostCenters')
                ->label('Sync SAP Cost Centers')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action('syncSapCostCenters'),
            Action::make('resetAllData')
                ->label('Reset All Data')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Reset All Operational Data')
                ->modalDescription(
                    'Deletes ALL Omniful orders & every event type (order / return / purchase order / '
                    . 'inwarding / inventory / product / stock-transfer), the SAP sync-event log, and the '
                    . 'local master-data mirrors (items / suppliers / warehouses / cost centers), then clears '
                    . 'queued & failed jobs and restarts workers. PRESERVED: SAP & Omniful connection keys, '
                    . 'cost-center mapping settings, SAP reference catalogs (currencies, accounts, documents…), '
                    . 'and user logins. This cannot be undone.'
                )
                ->modalSubmitActionLabel('Delete Everything')
                ->form([
                    TextInput::make('confirm_text')
                        ->label('Type RESET to confirm')
                        ->required()
                        ->rules(['in:RESET']),
                ])
                ->action(fn () => $this->resetAllData()),
        ];

        return $actions;
    }

    /**
     * Tables wiped by "Reset All Data". Operational event/order data + the
     * SAP sync-event log + local master-data mirrors (all re-syncable).
     *
     * NEVER includes: integration_settings (keys/credentials/config),
     * sap_cost_center_settings (mapping config), users/sessions, the queue
     * batches table, or the SAP reference catalogs (treated as stable
     * reference data that does not need deleting).
     *
     * @var array<int,string>
     */
    private array $resettableTables = [
        // Omniful operational events + orders
        'omniful_order_events',
        'omniful_orders',
        'omniful_return_order_events',
        'omniful_purchase_order_events',
        'omniful_inwarding_events',
        'omniful_inventory_events',
        'omniful_product_events',
        'omniful_stock_transfer_events',
        // SAP sync tracking
        'sap_sync_events',
        // Local master-data mirrors (re-syncable from SAP/Omniful)
        'sap_items',
        'sap_suppliers',
        'sap_warehouses',
        'sap_cost_centers',
    ];

    public function resetAllData(): void
    {
        try {
            // Ask active workers to stop gracefully before cleanup.
            Artisan::call('queue:restart');

            $cleared = $this->wipeResettableTables();

            // Clear pending + failed queue jobs across all queues.
            if (DB::getSchemaBuilder()->hasTable('jobs')) {
                DB::table('jobs')->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                DB::table('failed_jobs')->delete();
            }

            // Trigger workers restart after cleanup (supervisor-managed).
            Artisan::call('queue:restart');

            Notification::make()
                ->title('All operational data reset')
                ->body(
                    $cleared . ' table(s) cleared and the queue was flushed. '
                    . 'Connection keys, cost-center settings, SAP reference catalogs and user logins were preserved. '
                    . 'Workers received a restart signal.'
                )
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Reset failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function wipeResettableTables(): int
    {
        $schema = DB::getSchemaBuilder();
        $driver = DB::getDriverName();
        $isMysql = in_array($driver, ['mysql', 'mariadb'], true);

        if ($isMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        $cleared = 0;
        try {
            foreach ($this->resettableTables as $table) {
                if (!$schema->hasTable($table)) {
                    continue;
                }

                if ($isMysql) {
                    DB::table($table)->truncate();
                } else {
                    DB::table($table)->delete();
                }
                $cleared++;
            }
        } finally {
            if ($isMysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return $cleared;
    }
}
