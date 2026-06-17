<?php
namespace App\Services;
use App\Models\IntegrationSetting;
use App\Services\Omniful\Concerns\HandlesOmnifulAuth;
use App\Services\Omniful\Concerns\HandlesOmnifulUpsert;
class OmnifulApiClient
{
    use HandlesOmnifulAuth;
    use HandlesOmnifulUpsert;
    private string $baseUrl;
    private int $timeout;
    private ?string $lastRefreshError = null;
    private array $tenantAuth = [];
    private array $sellerAuth = [];
    private array $activeAuth = [];
    private string $settingsEnvironment = 'unknown';
    private ?int $settingsId = null;
    public function __construct()
    {
        // Use the ACTIVE environment profile (staging vs production), matching
        // where the Connections/Integration Settings UI saves credentials.
        // Using first() could load another environment's tokens (e.g. a staging
        // seller token against the production API), causing 401s.
        $settings = IntegrationSetting::active();

        $this->settingsEnvironment = (string) ($settings?->environment ?? 'unknown');
        $this->settingsId = $settings?->id;
        $this->baseUrl = rtrim((string) ($settings?->omniful_api_url ?? ''), '/');
        $this->timeout = (int) (config('omniful.sync_timeout', 20));
        $this->tenantAuth = [
            'label' => 'tenant',
            'api_key' => trim((string) ($settings?->omniful_api_key ?? '')),
            'api_secret' => trim((string) ($settings?->omniful_api_secret ?? '')),
            'access_token' => trim((string) ($settings?->omniful_access_token ?? '')),
            'refresh_token' => trim((string) ($settings?->omniful_refresh_token ?? '')),
            'expires_at' => $settings?->omniful_access_token_expires_at,
            'token_endpoint' => (string) config('omniful.tenant_token_endpoint', '/sales-channel/public/v1/tenants/token'),
            'columns' => [
                'access' => 'omniful_access_token',
                'refresh' => 'omniful_refresh_token',
                'expires_in' => 'omniful_token_expires_in',
                'expires_at' => 'omniful_access_token_expires_at',
            ],
        ];
        $this->sellerAuth = [
            'label' => 'seller',
            'api_key' => trim((string) ($settings?->omniful_seller_api_key ?? '')),
            'api_secret' => trim((string) ($settings?->omniful_seller_api_secret ?? '')),
            'access_token' => trim((string) ($settings?->omniful_seller_access_token ?? '')),
            'refresh_token' => trim((string) ($settings?->omniful_seller_refresh_token ?? '')),
            'expires_at' => $settings?->omniful_seller_access_token_expires_at,
            'token_endpoint' => (string) config('omniful.seller_token_endpoint', '/sales-channel/public/v1/token'),
            'columns' => [
                'access' => 'omniful_seller_access_token',
                'refresh' => 'omniful_seller_refresh_token',
                'expires_in' => 'omniful_seller_token_expires_in',
                'expires_at' => 'omniful_seller_access_token_expires_at',
            ],
        ];
        $this->activeAuth = $this->tenantAuth;
    }

    /**
     * @param array<string,mixed> $payload
     */
}
