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

        $sapResult = $this->testSapConnection($state);
        $omnifulTenantResult = $this->testOmnifulTenantConnection($state);
        $omnifulSellerResult = $this->testOmnifulSellerConnection($state);

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

    private function testOmnifulTenantConnection(array $state): array
    {
        $baseUrl = trim((string) ($state['omniful_api_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'Missing API base URL'];
        }

        $baseUrl = rtrim($baseUrl, '/');
        return $this->refreshOmnifulTokens(
            $baseUrl,
            trim((string) ($state['omniful_api_key'] ?? '')),
            trim((string) ($state['omniful_api_secret'] ?? '')),
            trim((string) ($state['omniful_refresh_token'] ?? '')),
            trim((string) ($state['omniful_access_token'] ?? '')),
            [
                'access' => 'omniful_access_token',
                'refresh' => 'omniful_refresh_token',
                'expires_in' => 'omniful_token_expires_in',
                'expires_at' => 'omniful_access_token_expires_at',
            ],
            'Omniful tenant',
            (string) config('omniful.tenant_token_endpoint', '/sales-channel/public/v1/tenants/token')
        );
    }

    private function testOmnifulSellerConnection(array $state): array
    {
        $baseUrl = trim((string) ($state['omniful_api_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'Missing API base URL'];
        }

        $baseUrl = rtrim($baseUrl, '/');

        return $this->refreshOmnifulTokens(
            $baseUrl,
            trim((string) ($state['omniful_seller_api_key'] ?? '')),
            trim((string) ($state['omniful_seller_api_secret'] ?? '')),
            trim((string) ($state['omniful_seller_refresh_token'] ?? '')),
            trim((string) ($state['omniful_seller_access_token'] ?? '')),
            [
                'access' => 'omniful_seller_access_token',
                'refresh' => 'omniful_seller_refresh_token',
                'expires_in' => 'omniful_seller_token_expires_in',
                'expires_at' => 'omniful_seller_access_token_expires_at',
            ],
            'Omniful seller',
            (string) config('omniful.seller_token_endpoint', '/sales-channel/public/v1/token')
        );
    }

    private function refreshOmnifulTokens(
        string $baseUrl,
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        string $accessToken,
        array $columns,
        string $label,
        string $tokenEndpoint
    ): array {
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return ['ok' => false, 'message' => "Missing {$label} client_id/secret/refresh_token"];
        }

        $tokenUrl = $baseUrl . '/' . ltrim($tokenEndpoint, '/');

        $client = Http::timeout(20)->acceptJson();
        if ($accessToken !== '') {
            $client = $client->withToken($accessToken);
        }

        try {
            $response = $client->post($tokenUrl, [
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Request failed: ' . $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'message' => 'Token refresh failed (HTTP ' . $response->status() . '): ' . $response->body()];
        }

        $payload = $response->json() ?? [];
        $data = $payload['data'] ?? [];
        $newAccess = $data['access_token'] ?? null;
        $newRefresh = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (!$newAccess) {
            return ['ok' => false, 'message' => 'Token refresh failed: missing access_token'];
        }

        $expiresAt = null;
        if (is_numeric($expiresIn)) {
            $seconds = (int) $expiresIn;
            if ($seconds >= 100000000000000) {
                $seconds = (int) floor($seconds / 1000000000);
            } elseif ($seconds >= 100000000000) {
                $seconds = (int) floor($seconds / 1000000);
            } elseif ($seconds >= 1000000000) {
                $seconds = (int) floor($seconds / 1000);
            }
            $expiresAt = now()->addSeconds($seconds);
        }

        IntegrationSetting::updateOrCreate(['id' => 1], [
            $columns['access'] => (string) $newAccess,
            $columns['refresh'] => $newRefresh ? (string) $newRefresh : $refreshToken,
            $columns['expires_in'] => is_numeric($expiresIn) ? (int) $expiresIn : null,
            $columns['expires_at'] => $expiresAt,
        ]);

        return ['ok' => true, 'message' => 'Token refreshed'];
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
