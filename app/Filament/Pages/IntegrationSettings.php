<?php

namespace App\Filament\Pages;

use App\Models\IntegrationSetting;
use App\Services\Connections\IntegrationConnectionTester;
use App\Services\IntegrationDirectionService;
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

        $this->form->fill($settings?->toArray() ?? []);
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
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        IntegrationSetting::updateOrCreate(['id' => 1], $state);
        $this->form->fill(IntegrationSetting::first()?->toArray() ?? []);

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
        ];
    }
}
