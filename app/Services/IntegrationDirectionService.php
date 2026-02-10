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
        'warehouses' => self::SAP_TO_OMNIFUL,
        'inventory' => self::OMNIFUL_TO_SAP,
    ];

    public function for(string $domain): string
    {
        $domain = strtolower(trim($domain));
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
