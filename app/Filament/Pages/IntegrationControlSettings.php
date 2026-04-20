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
            SapCostCenterSetting::first()?->toArray() ?? []
        ));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sync Direction')
                    ->description('Choose the source of truth per domain to prevent sync collisions')
                    ->schema([
                        Select::make('sync_direction_items')
                            ->label('Items')
                            ->options(app(IntegrationDirectionService::class)->options())
                            ->default(IntegrationDirectionService::SAP_TO_OMNIFUL)
                            ->required(),
                        Select::make('sync_direction_suppliers')
                            ->label('Suppliers')
                            ->options(app(IntegrationDirectionService::class)->options())
                            ->default(IntegrationDirectionService::SAP_TO_OMNIFUL)
                            ->required(),
                        Select::make('sync_direction_warehouses')
                            ->label('Warehouses / Hubs')
                            ->options(app(IntegrationDirectionService::class)->options())
                            ->default(IntegrationDirectionService::SAP_TO_OMNIFUL)
                            ->required(),
                        Select::make('sync_direction_inventory')
                            ->label('Inventory')
                            ->options(app(IntegrationDirectionService::class)->options())
                            ->default(IntegrationDirectionService::OMNIFUL_TO_SAP)
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('SAP Cost Centers')
                    ->description('Default costing and project values sent to SAP on transactions')
                    ->schema([
                        Select::make('costing_code')
                            ->label('Costing Code (Dimension 1)')
                            ->options(fn () => $this->getDistributionRuleOptions(1))
                            ->searchable()
                            ->preload(),
                        Select::make('costing_code2')
                            ->label('Costing Code 2 (Dimension 2)')
                            ->options(fn () => $this->getDistributionRuleOptions(2))
                            ->searchable()
                            ->preload(),
                        Select::make('costing_code3')
                            ->label('Costing Code 3 (Dimension 3)')
                            ->options(fn () => $this->getDistributionRuleOptions(3))
                            ->searchable()
                            ->preload(),
                        Select::make('costing_code4')
                            ->label('Costing Code 4 (Dimension 4)')
                            ->options(fn () => $this->getDistributionRuleOptions(4))
                            ->searchable()
                            ->preload(),
                        Select::make('costing_code5')
                            ->label('Costing Code 5 (Dimension 5)')
                            ->options(fn () => $this->getDistributionRuleOptions(5))
                            ->searchable()
                            ->preload(),
                        Select::make('project_code')
                            ->label('Project Code')
                            ->options(fn () => $this->getProjectOptions())
                            ->searchable()
                            ->preload(),
                        Toggle::make('apply_to_stock_transfer')
                            ->label('Apply cost centers on Stock Transfer lines')
                            ->default(false),
                    ])
                    ->columns(2),
                Section::make('Order Flow Fallbacks')
                    ->description('Fallback values for Omniful to SAP sales flows when payload mapping is incomplete')
                    ->schema([
                        TextInput::make('order_fallback_customer_code')
                            ->label('Fallback Customer Code')
                            ->placeholder('Example: C00046'),
                        TextInput::make('order_fallback_warehouse_code')
                            ->label('Fallback Warehouse Code')
                            ->placeholder('Example: CEN11'),
                        Textarea::make('order_fallback_customer_code_by_source')
                            ->label('Customer Code by Source')
                            ->rows(4)
                            ->placeholder("One per line\nsalla:C00046\nsource2:C00047")
                            ->helperText('Format: source_key:customer_code, one pair per line or comma-separated.'),
                    ])
                    ->columns(2),
                Section::make('Order Payments')
                    ->description('Configure incoming payment defaults using SAP bank accounts pulled from the finance master catalog')
                    ->schema([
                        Toggle::make('order_payment_enabled')
                            ->label('Enable Incoming Payments')
                            ->default(false)
                            ->helperText('Disable temporarily to stop creating SAP incoming payments from Omniful sales orders.'),
                        Select::make('order_payment_transfer_account')
                            ->label('Incoming Payment Transfer Account')
                            ->options(fn () => $this->getBankAccountOptions())
                            ->searchable()
                            ->preload()
                            ->helperText('Used for prepaid incoming payments created from Omniful sales orders.'),
                        Select::make('order_payment_invoice_type_candidates')
                            ->label('Incoming Payment Invoice Type Candidates')
                            ->options($this->getIncomingPaymentInvoiceTypeOptions())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Ordered SAP receipt invoice types to try when creating incoming payments.'),
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
                            ->label('Freight Expense Code')
                            ->placeholder('1')
                            ->helperText('Used when shipping or delivery fee exists in Omniful payload.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        IntegrationSetting::updateOrCreate(
            ['id' => 1],
            [
                'sync_direction_items' => $state['sync_direction_items'] ?? null,
                'sync_direction_suppliers' => $state['sync_direction_suppliers'] ?? null,
                'sync_direction_warehouses' => $state['sync_direction_warehouses'] ?? null,
                'sync_direction_inventory' => $state['sync_direction_inventory'] ?? null,
                'order_fallback_customer_code' => $state['order_fallback_customer_code'] ?? null,
                'order_fallback_customer_code_by_source' => $state['order_fallback_customer_code_by_source'] ?? null,
                'order_fallback_warehouse_code' => $state['order_fallback_warehouse_code'] ?? null,
                'order_payment_enabled' => (bool) ($state['order_payment_enabled'] ?? false),
                'order_payment_transfer_account' => $state['order_payment_transfer_account'] ?? null,
                'order_payment_invoice_type_candidates' => array_values(array_map(
                    'intval',
                    array_filter((array) ($state['order_payment_invoice_type_candidates'] ?? []), fn ($value) => is_numeric($value))
                )),
                'order_payment_method_map' => $state['order_payment_method_map'] ?? null,
                'order_tax_code_ksa_taxable' => $state['order_tax_code_ksa_taxable'] ?? null,
                'order_tax_code_ksa_zero' => $state['order_tax_code_ksa_zero'] ?? null,
                'order_tax_code_foreign' => $state['order_tax_code_foreign'] ?? null,
                'order_freight_expense_code' => $state['order_freight_expense_code'] ?? null,
            ]
        );

        SapCostCenterSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'costing_code' => $state['costing_code'] ?? null,
                'costing_code2' => $state['costing_code2'] ?? null,
                'costing_code3' => $state['costing_code3'] ?? null,
                'costing_code4' => $state['costing_code4'] ?? null,
                'costing_code5' => $state['costing_code5'] ?? null,
                'project_code' => $state['project_code'] ?? null,
                'apply_to_stock_transfer' => (bool) ($state['apply_to_stock_transfer'] ?? false),
            ]
        );

        $this->form->fill(array_merge(
            IntegrationSetting::first()?->toArray() ?? [],
            SapCostCenterSetting::first()?->toArray() ?? []
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
