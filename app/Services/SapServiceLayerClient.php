<?php
namespace App\Services;
use App\Models\IntegrationSetting;
use App\Services\Sap\Concerns\HandlesSapHttp;
use App\Services\Sap\Concerns\HandlesSapInventoryDocs;
use App\Services\Sap\Concerns\HandlesSapMasterDataFetch;
use App\Services\Sap\Concerns\HandlesSapPurchaseAndProducts;
use App\Services\Sap\Concerns\HandlesSapSeries;
class SapServiceLayerClient
{
    use HandlesSapHttp;
    use HandlesSapInventoryDocs;
    use HandlesSapMasterDataFetch;
    use HandlesSapPurchaseAndProducts;
    use HandlesSapSeries;
    private string $baseUrl;
    private string $companyDb;
    private string $username;
    private string $password;
    private bool $verifySsl;
    public function __construct()
    {
        $settings = IntegrationSetting::first();

        $baseUrl = rtrim((string) ($settings?->sap_service_layer_url ?? ''), '/');
        if (str_ends_with(strtolower($baseUrl), '/login')) {
            $baseUrl = substr($baseUrl, 0, -6);
        }
        $this->baseUrl = $baseUrl;
        $this->companyDb = (string) ($settings?->sap_company_db ?? '');
        $this->username = (string) ($settings?->sap_username ?? '');
        $this->password = (string) ($settings?->sap_password ?? '');
        $this->verifySsl = (bool) ($settings?->sap_ssl_verify ?? true);
    }

}
