<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    public const ENVIRONMENTS = ['production', 'staging'];

    protected $fillable = [
        'environment',
        'is_active',
        'sap_service_layer_url',
        'sap_company_db',
        'sap_username',
        'sap_password',
        'sap_ssl_verify',
        'omniful_api_url',
        'omniful_api_key',
        'omniful_api_secret',
        'omniful_webhook_secret',
        'omniful_tenant_code',
        'omniful_seller_code',
        'omniful_access_token',
        'omniful_refresh_token',
        'omniful_token_expires_in',
        'omniful_access_token_expires_at',
        'omniful_seller_api_key',
        'omniful_seller_api_secret',
        'omniful_seller_webhook_secret',
        'omniful_seller_access_token',
        'omniful_seller_refresh_token',
        'omniful_seller_token_expires_in',
        'omniful_seller_access_token_expires_at',
        'sync_direction_items',
        'sync_direction_suppliers',
        'sync_direction_warehouses',
        'sync_direction_inventory',
        'order_fallback_customer_code',
        'order_fallback_customer_code_by_source',
        'order_fallback_warehouse_code',
        'order_payment_enabled',
        'order_payment_transfer_account',
        'order_payment_invoice_type_candidates',
        'order_payment_method_map',
        'order_tax_code_ksa_taxable',
        'order_tax_code_ksa_zero',
        'order_tax_code_foreign',
        'order_freight_expense_code',
        'order_freight_expense_code_foreign',
        'order_rounding_enabled',
        'purchase_tax_code_ksa_taxable',
        'purchase_tax_code_ksa_zero',
        'purchase_tax_code_foreign',
        'order_card_fee_journal_enabled',
        'order_card_fee_expense_account',
        'order_card_fee_offset_account',
        'order_card_fee_percent',
        'order_card_fee_vat_percent',
        'order_card_fee_vat_recoverable_account',
        'order_card_fee_method_percent_map',
        'order_cogs_journal_enabled',
        'order_cogs_expense_account',
        'order_cogs_inventory_offset_account',
        'return_cogs_reversal_enabled',
        'auto_sync_enabled',
        'auto_sync_items_enabled',
        'auto_sync_suppliers_enabled',
        'auto_sync_interval_minutes',
        'auto_sync_last_run_at',
        'po_ignored_supplier_codes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sap_password' => 'encrypted',
        'sap_ssl_verify' => 'boolean',
        'omniful_api_key' => 'encrypted',
        'omniful_api_secret' => 'encrypted',
        'omniful_webhook_secret' => 'encrypted',
        'omniful_access_token' => 'encrypted',
        'omniful_refresh_token' => 'encrypted',
        'omniful_token_expires_in' => 'integer',
        'omniful_access_token_expires_at' => 'datetime',
        'omniful_seller_api_key' => 'encrypted',
        'omniful_seller_api_secret' => 'encrypted',
        'omniful_seller_webhook_secret' => 'encrypted',
        'omniful_seller_access_token' => 'encrypted',
        'omniful_seller_refresh_token' => 'encrypted',
        'omniful_seller_token_expires_in' => 'integer',
        'omniful_seller_access_token_expires_at' => 'datetime',
        'sync_direction_items' => 'string',
        'sync_direction_suppliers' => 'string',
        'sync_direction_warehouses' => 'string',
        'sync_direction_inventory' => 'string',
        'order_fallback_customer_code' => 'string',
        'order_fallback_customer_code_by_source' => 'string',
        'order_fallback_warehouse_code' => 'string',
        'order_payment_enabled' => 'boolean',
        'order_payment_transfer_account' => 'string',
        'order_payment_invoice_type_candidates' => 'array',
        'order_payment_method_map' => 'string',
        'order_tax_code_ksa_taxable' => 'string',
        'order_tax_code_ksa_zero' => 'string',
        'order_tax_code_foreign' => 'string',
        'order_freight_expense_code' => 'string',
        'order_freight_expense_code_foreign' => 'string',
        'order_card_fee_journal_enabled' => 'boolean',
        'order_card_fee_expense_account' => 'string',
        'order_card_fee_offset_account' => 'string',
        'order_card_fee_percent' => 'decimal:4',
        'order_card_fee_method_percent_map' => 'string',
        'order_cogs_journal_enabled' => 'boolean',
        'order_cogs_expense_account' => 'string',
        'order_cogs_inventory_offset_account' => 'string',
        'return_cogs_reversal_enabled' => 'boolean',
        'auto_sync_enabled' => 'boolean',
        'auto_sync_items_enabled' => 'boolean',
        'auto_sync_suppliers_enabled' => 'boolean',
        'auto_sync_interval_minutes' => 'integer',
        'auto_sync_last_run_at' => 'datetime',
        'po_ignored_supplier_codes' => 'string',
    ];

    /**
     * Normalised (upper-cased) list of supplier codes whose PO/GRPO webhooks
     * must be ignored. Uses the active profile's setting, falling back to the
     * env/config value.
     *
     * @return array<int,string>
     */
    public static function ignoredPurchaseOrderSupplierCodes(): array
    {
        $raw = (string) (self::active()?->po_ignored_supplier_codes ?? '');
        if (trim($raw) === '') {
            $raw = (string) config('omniful.purchase_order.ignored_supplier_codes', '');
        }

        $parts = preg_split('/[\s,;]+/', $raw) ?: [];

        return array_values(array_unique(array_filter(
            array_map(static fn ($c) => strtoupper(trim((string) $c)), $parts),
            static fn ($c) => $c !== ''
        )));
    }

    /**
     * Whether a PO/GRPO from the given supplier code should be ignored
     * (not created in SAP). Matched case-insensitively.
     */
    public static function isPurchaseOrderSupplierIgnored(?string $supplierCode): bool
    {
        $supplierCode = strtoupper(trim((string) $supplierCode));
        if ($supplierCode === '') {
            return false;
        }

        return in_array($supplierCode, self::ignoredPurchaseOrderSupplierCodes(), true);
    }

    /**
     * Make the ACTIVE environment profile the default record everywhere.
     *
     * Connection settings are stored one row per environment (production /
     * staging). A global ordering scope puts the active row first, so every
     * existing `IntegrationSetting::first()` / `query()->first()` read across
     * the SAP client, Omniful client, webhook secret checks, sync flows, etc.
     * automatically resolves to the selected environment without having to
     * touch each call site. `find($id)` and explicit `where()` queries are
     * unaffected (ordering is ignored when a key is targeted).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('activeEnvironmentFirst', function (Builder $query) {
            $query->orderByDesc('is_active')->orderBy('id');
        });
    }

    /**
     * The currently active environment profile (falls back to the first row).
     */
    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->first()
            ?? static::query()->first();
    }

    /**
     * Switch the active environment. Marks the chosen profile active (and all
     * others inactive) and returns it. Connection caches that key off the old
     * credentials (e.g. the SAP session cookie) naturally miss because the new
     * profile's credentials differ; callers should still flush transient caches.
     */
    public static function activateEnvironment(string $environment): ?self
    {
        $environment = strtolower(trim($environment));
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            return null;
        }

        $target = static::withoutGlobalScopes()->where('environment', $environment)->first();
        if ($target === null) {
            $target = static::create(['environment' => $environment, 'is_active' => false]);
        }

        static::withoutGlobalScopes()->where('id', '!=', $target->id)->update(['is_active' => false]);
        $target->forceFill(['is_active' => true])->save();

        return $target->refresh();
    }
}
