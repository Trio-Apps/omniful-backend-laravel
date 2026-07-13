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
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

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
        // Load the ACTIVE environment profile (matches where save() writes and
        // where the runtime reads), so the form reflects the live settings.
        $this->form->fill(array_merge(
            IntegrationSetting::active()?->toArray() ?? [],
            SapCostCenterSetting::query()->whereNull('warehouse_code')->first()?->toArray() ?? []
        ));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Integration Settings')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Sync')
                            ->icon('heroicon-o-arrows-right-left')
                            ->schema([
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
                                Section::make('Auto Sync (SAP → Omniful)')
                                    ->description('Automatically pull Items / Suppliers from SAP and push them to Omniful on a schedule, instead of doing it manually from their pages. Requires the server scheduler (php artisan schedule:run) to be running via cron.')
                                    ->schema([
                                        Toggle::make('auto_sync_enabled')
                                            ->label('Enable Auto Sync')
                                            ->default(false)
                                            ->helperText('Master switch. When off, nothing runs automatically and you keep using the manual Pull/Push buttons.'),
                                        TextInput::make('auto_sync_interval_minutes')
                                            ->label('Run every (minutes)')
                                            ->numeric()
                                            ->minValue(1)
                                            ->default(15)
                                            ->helperText('How often to run. Master data rarely changes — 15–30 min is plenty and keeps SAP load low.'),
                                        Toggle::make('auto_sync_items_enabled')
                                            ->label('Auto sync Items')
                                            ->default(true)
                                            ->helperText('Pull pending SAP items and push them to Omniful.'),
                                        Toggle::make('auto_sync_suppliers_enabled')
                                            ->label('Auto sync Suppliers')
                                            ->default(true)
                                            ->helperText('Pull pending SAP suppliers and push them to Omniful.'),
                                    ])
                                    ->columns(2),
                                Section::make('Inventory Quantity Push (SAP → Omniful)')
                                    ->description('Push on-hand Available quantities per integrated item to the already-synced Omniful hubs. Disabled by default; when off it runs only when triggered manually from the Inventory Qty Push page.')
                                    ->schema([
                                        Toggle::make('inventory_push_enabled')
                                            ->label('Enable scheduled push')
                                            ->default(false)
                                            ->helperText('Master switch for the automatic quantity push. Off = manual runs only.'),
                                        TextInput::make('inventory_push_cadence_minutes')
                                            ->label('Run every (minutes)')
                                            ->numeric()
                                            ->minValue(1)
                                            ->default(30)
                                            ->helperText('How often to push quantities to Omniful.'),
                                        Select::make('inventory_push_mode')
                                            ->label('Mode')
                                            ->options([
                                                'delta' => 'Delta (changed quantities only)',
                                                'full' => 'Full (every integrated item × hub)',
                                            ])
                                            ->default('delta')
                                            ->helperText('Delta pushes only what changed since the last run; Full re-pushes everything.'),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Sales Orders')
                            ->icon('heroicon-o-shopping-cart')
                            ->schema([
                                Section::make('Orders')
                                    ->description('Control which Omniful orders are turned into SAP documents.')
                                    ->schema([
                                        Toggle::make('order_numeric_id_only')
                                            ->label('Only sync numeric order IDs to SAP')
                                            ->default(true)
                                            ->helperText('When on, orders whose id is not fully numeric (e.g. STO_..., RS_234) are ignored — only numeric order ids are pushed to SAP.'),
                                        DatePicker::make('order_cutoff_date')
                                            ->label('SAP Integration Cutoff Date')
                                            ->native(false)
                                            ->displayFormat('Y-m-d')
                                            ->format('Y-m-d')
                                            ->closeOnDateSelection()
                                            ->helperText('Orders created in Omniful BEFORE this date are ignored on the spot — never sent to SAP and no error is recorded. Leave empty to process all orders (e.g. set 2026-06-18 for go-live).'),
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
                            ]),
                        Tab::make('Journals')
                            ->icon('heroicon-o-book-open')
                            ->schema([
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
                            ]),
                        Tab::make('Purchasing')
                            ->icon('heroicon-o-truck')
                            ->schema([
                                Section::make('Purchase Orders')
                                    ->description('Control how Omniful Purchase Order / GRPO webhooks are turned into SAP documents.')
                                    ->schema([
                                        Textarea::make('po_ignored_supplier_codes')
                                            ->label('Ignored supplier codes (PO/GRPO)')
                                            ->rows(2)
                                            ->placeholder('RU-415, M130')
                                            ->helperText('PO/GRPO webhooks whose supplier code is in this list are ignored — not created in SAP. Separate codes by comma, space or new line. Matched case-insensitively.'),
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
                            ]),
                        Tab::make('Cost Centers')
                            ->icon('heroicon-o-building-office-2')
                            ->schema([
                                Section::make('Warehouse Cost Centers')
                                    ->description('Warehouse-specific cost center mapping is managed from the dedicated Warehouse Cost Centers page.')
                                    ->schema([
                                        Toggle::make('apply_to_stock_transfer')
                                            ->label('Apply cost centers on Stock Transfer lines')
                                            ->default(false),
                                    ]),
                            ]),
                    ]),
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
                'auto_sync_enabled' => (bool) ($state['auto_sync_enabled'] ?? false),
                'auto_sync_items_enabled' => (bool) ($state['auto_sync_items_enabled'] ?? false),
                'auto_sync_suppliers_enabled' => (bool) ($state['auto_sync_suppliers_enabled'] ?? false),
                'auto_sync_interval_minutes' => max(1, (int) ($state['auto_sync_interval_minutes'] ?? 15)),
                'inventory_push_enabled' => (bool) ($state['inventory_push_enabled'] ?? false),
                'inventory_push_cadence_minutes' => max(1, (int) ($state['inventory_push_cadence_minutes'] ?? 30)),
                'inventory_push_mode' => in_array(($state['inventory_push_mode'] ?? 'delta'), ['delta', 'full'], true) ? $state['inventory_push_mode'] : 'delta',
                'po_ignored_supplier_codes' => $state['po_ignored_supplier_codes'] ?? null,
                'order_numeric_id_only' => (bool) ($state['order_numeric_id_only'] ?? false),
                'order_cutoff_date' => ($state['order_cutoff_date'] ?? null) ?: null,
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
        ];

        return $actions;
    }

}
