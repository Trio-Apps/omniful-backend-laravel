<?php

namespace App\Filament\Pages;

use App\Models\IntegrationSetting;
use App\Models\SapCostCenter;
use App\Models\SapCostCenterSetting;
use App\Services\Connections\IntegrationConnectionTester;
use App\Services\IntegrationDirectionService;
use App\Services\MasterData\SapCostCenterSyncService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IntegrationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Connections';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 999;

    protected string $view = 'filament.pages.integration-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = IntegrationSetting::first();
        $costCenterSettings = SapCostCenterSetting::first();

        $this->form->fill(array_merge(
            $settings?->toArray() ?? [],
            $costCenterSettings?->toArray() ?? []
        ));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('SAP Business One')
                    ->description('Service Layer connection details')
                    ->schema([
                        TextInput::make('sap_service_layer_url')
                            ->label('Service Layer URL')
                            ->placeholder('https://sap.example.com:50000/b1s/v1')
                            ->required()
                            ->url()
                            ->columnSpanFull(),
                        TextInput::make('sap_company_db')
                            ->label('Company DB')
                            ->required(),
                        TextInput::make('sap_username')
                            ->label('Username')
                            ->required(),
                        TextInput::make('sap_password')
                            ->label('Password')
                            ->password()
                            ->revealable(),
                        Toggle::make('sap_ssl_verify')
                            ->label('Verify SSL certificate')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Omniful (Tenant)')
                    ->description('Tenant API and webhook settings')
                    ->schema([
                        TextInput::make('omniful_api_url')
                            ->label('API Base URL')
                            ->placeholder('https://api.omniful.com')
                            ->required()
                            ->url()
                            ->columnSpanFull(),
                        TextInput::make('omniful_api_key')
                            ->label('Client Id')
                            ->password()
                            ->revealable(),
                        TextInput::make('omniful_api_secret')
                            ->label('Client Secret')
                            ->password()
                            ->revealable(),
                        TextInput::make('omniful_webhook_secret')
                            ->label('Webhook Secret Key')
                            ->password()
                            ->revealable()
                            ->columnSpanFull(),
                        TextInput::make('omniful_refresh_token')
                            ->label('Refresh Token')
                            ->password()
                            ->revealable(),
                        TextInput::make('omniful_access_token')
                            ->label('Access Token')
                            ->password()
                            ->revealable()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Omniful (Seller)')
                    ->description('Seller API and webhook settings')
                    ->schema([
                        TextInput::make('omniful_seller_api_key')
                            ->label('Client Id')
                            ->password()
                            ->revealable(),
                        TextInput::make('omniful_seller_api_secret')
                            ->label('Client Secret')
                            ->password()
                            ->revealable(),
                        TextInput::make('omniful_seller_webhook_secret')
                            ->label('Webhook Secret Key')
                            ->password()
                            ->revealable()
                            ->columnSpanFull(),
                        TextInput::make('omniful_seller_refresh_token')
                            ->label('Refresh Token')
                            ->password()
                            ->revealable(),
                        TextInput::make('omniful_seller_access_token')
                            ->label('Access Token')
                            ->password()
                            ->revealable()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
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
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        IntegrationSetting::updateOrCreate(['id' => 1], $state);
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
            ->title('Connections saved')
            ->success()
            ->send();
    }

    public function testConnection(): void
    {
        $state = $this->getTestState();
        $tester = app(IntegrationConnectionTester::class);

        $sapResult = $tester->testSapConnection($state);
        $omnifulTenantResult = $tester->testOmnifulTenantConnection($state);
        $omnifulSellerResult = $tester->testOmnifulSellerConnection($state);

        $lines = [
            'SAP: ' . $sapResult['message'],
            'Omniful (Tenant): ' . $omnifulTenantResult['message'],
            'Omniful (Seller): ' . $omnifulSellerResult['message'],
        ];

        $ok = $sapResult['ok'] && $omnifulTenantResult['ok'] && $omnifulSellerResult['ok'];

        Notification::make()
            ->title('Connection test')
            ->body(implode("\n", $lines))
            ->{$ok ? 'success' : 'danger'}()
            ->send();

        // Refresh form state so rotated refresh tokens are reused on next test click.
        $this->form->fill(IntegrationSetting::first()?->toArray() ?? []);
    }

    private function getTestState(): array
    {
        $state = $this->form->getState();
        $stored = IntegrationSetting::first()?->toArray() ?? [];

        foreach ($state as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                unset($state[$key]);
            }
        }

        return array_merge($stored, $state);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->action('save')
                ->color('primary')
                ->extraAttributes([
                    'style' => 'background-color: #226d64; color: #ffffff;',
                ])
                ->keyBindings(['mod+s']),
            Action::make('testConnection')
                ->label('Test Connection')
                ->action('testConnection')
                ->color('gray'),
            Action::make('syncSapCostCenters')
                ->label('Sync SAP Cost Centers')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action('syncSapCostCenters'),
        ];
    }

    public function syncSapCostCenters(SapServiceLayerClient $client): void
    {
        try {
            $result = app(SapCostCenterSyncService::class)->syncFromSap($client);

            Notification::make()
                ->title('SAP cost centers synced')
                ->body(
                    'Distribution Rules: ' . (int) ($result['distribution_rules'] ?? 0)
                    . ' | Projects: ' . (int) ($result['projects'] ?? 0)
                )
                ->success()
                ->send();

            $this->form->fill(array_merge(
                IntegrationSetting::first()?->toArray() ?? [],
                SapCostCenterSetting::first()?->toArray() ?? []
            ));
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
        return SapCostCenter::query()
            ->where('source', 'distribution_rule')
            ->where('dimension', $dimension)
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
}
