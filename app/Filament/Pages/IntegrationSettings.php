<?php

namespace App\Filament\Pages;

use App\Models\IntegrationSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Http;

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
                Section::make('Omniful')
                    ->description('Omniful API and webhook settings')
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
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        IntegrationSetting::updateOrCreate(['id' => 1], $state);

        Notification::make()
            ->title('Connections saved')
            ->success()
            ->send();
    }

    public function testConnection(): void
    {
        $state = $this->form->getState();

        $sapResult = $this->testSapConnection($state);
        $omnifulResult = $this->testOmnifulConnection($state);

        $lines = [
            'SAP: ' . $sapResult['message'],
            'Omniful: ' . $omnifulResult['message'],
        ];

        $ok = $sapResult['ok'] && $omnifulResult['ok'];

        Notification::make()
            ->title('Connection test')
            ->body(implode("\n", $lines))
            ->{$ok ? 'success' : 'danger'}()
            ->send();
    }

    private function testSapConnection(array $state): array
    {
        $baseUrl = trim((string) ($state['sap_service_layer_url'] ?? ''));
        $companyDb = trim((string) ($state['sap_company_db'] ?? ''));
        $username = trim((string) ($state['sap_username'] ?? ''));
        $password = (string) ($state['sap_password'] ?? '');

        if ($baseUrl === '' || $companyDb === '' || $username === '' || $password === '') {
            return ['ok' => false, 'message' => 'Missing SAP credentials'];
        }

        $baseUrl = rtrim($baseUrl, '/');
        $loginUrl = $baseUrl . '/Login';

        $client = Http::timeout(15)->acceptJson();
        if (array_key_exists('sap_ssl_verify', $state) && $state['sap_ssl_verify'] === false) {
            $client = $client->withoutVerifying();
        }

        try {
            $response = $client->post($loginUrl, [
                'CompanyDB' => $companyDb,
                'UserName' => $username,
                'Password' => $password,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Request failed: ' . $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'message' => 'Login failed (HTTP ' . $response->status() . ')'];
        }

        $payload = $response->json();
        $sessionId = $payload['SessionId'] ?? null;
        $routeId = $payload['RouteId'] ?? null;

        if ($sessionId) {
            $cookie = 'B1SESSION=' . $sessionId;
            if ($routeId) {
                $cookie .= '; ROUTEID=' . $routeId;
            }

            try {
                $client->withHeaders(['Cookie' => $cookie])->post($baseUrl . '/Logout');
            } catch (\Throwable) {
                // Ignore logout errors; login success is enough for connectivity.
            }
        }

        return ['ok' => true, 'message' => 'Connected'];
    }

    private function testOmnifulConnection(array $state): array
    {
        $baseUrl = trim((string) ($state['omniful_api_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'Missing API base URL'];
        }

        $baseUrl = rtrim($baseUrl, '/');
        $token = trim((string) ($state['omniful_access_token'] ?? ''));

        $client = Http::timeout(15)->acceptJson();
        if ($token !== '') {
            $client = $client->withToken($token);
        }

        try {
            $response = $client->get($baseUrl);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Request failed: ' . $e->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Connected'];
        }

        return ['ok' => false, 'message' => 'Request failed (HTTP ' . $response->status() . ')'];
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
