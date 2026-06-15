<?php

namespace App\Services;

use App\Models\IntegrationSetting;

class IntegrationDirectionService
{
    public const SAP_TO_OMNIFUL = 'sap_to_omniful';
    public const OMNIFUL_TO_SAP = 'omniful_to_sap';

    /**
     * @var array<string,string>
     */
    private array $defaults = [
        'items' => self::SAP_TO_OMNIFUL,
        'suppliers' => self::SAP_TO_OMNIFUL,
        // Warehouses now sync Omniful -> SAP (the only active master-data sync).
        'warehouses' => self::OMNIFUL_TO_SAP,
        'inventory' => self::OMNIFUL_TO_SAP,
    ];

    /**
     * @var array<string,string>
     */
    private array $enabledConfigKeys = [
        'items' => 'omniful.master_data_sync.items_enabled',
        'suppliers' => 'omniful.master_data_sync.suppliers_enabled',
        'warehouses' => 'omniful.master_data_sync.warehouses_enabled',
    ];

    /**
     * Domains locked to a single direction regardless of any stored setting.
     * Items and suppliers always flow SAP -> Omniful (one direction only).
     *
     * @var array<string,string>
     */
    private array $forcedDirections = [
        'items' => self::SAP_TO_OMNIFUL,
        'suppliers' => self::SAP_TO_OMNIFUL,
    ];

    public function for(string $domain): string
    {
        $domain = strtolower(trim($domain));

        // Forced (one-direction-only) domains ignore any stored value.
        if (isset($this->forcedDirections[$domain])) {
            return $this->forcedDirections[$domain];
        }

        $column = 'sync_direction_' . $domain;
        $fallback = $this->defaults[$domain] ?? self::SAP_TO_OMNIFUL;

        $settings = IntegrationSetting::first();
        $value = strtolower(trim((string) data_get($settings, $column, $fallback)));

        if (!in_array($value, [self::SAP_TO_OMNIFUL, self::OMNIFUL_TO_SAP], true)) {
            return $fallback;
        }

        return $value;
    }

    public function isSapToOmniful(string $domain): bool
    {
        return $this->for($domain) === self::SAP_TO_OMNIFUL;
    }

    public function isOmnifulToSap(string $domain): bool
    {
        return $this->for($domain) === self::OMNIFUL_TO_SAP;
    }

    /**
     * Whether the master-data sync for this domain is active. Items and
     * suppliers are stopped by default; only warehouses sync. Domains without
     * an explicit flag (e.g. inventory, which is webhook-driven) are treated
     * as enabled so this gate never blocks unrelated flows.
     */
    public function isDomainEnabled(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        $configKey = $this->enabledConfigKeys[$domain] ?? null;

        if ($configKey === null) {
            return true;
        }

        return (bool) config($configKey, false);
    }

    /**
     * @return array<string,string>
     */
    public function options(): array
    {
        return [
            self::SAP_TO_OMNIFUL => 'SAP -> Omniful',
            self::OMNIFUL_TO_SAP => 'Omniful -> SAP',
        ];
    }
}
