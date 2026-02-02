<?php

return [
    'sync_endpoints' => [
        'warehouses' => env('OMNIFUL_WAREHOUSES_ENDPOINT', '/sales-channel/public/v1/tenants/hubs'),
        'suppliers' => env('OMNIFUL_SUPPLIERS_ENDPOINT', '/sales-channel/public/v1/suppliers'),
        'items' => env('OMNIFUL_ITEMS_ENDPOINT', '/items'),
    ],
    'sync_methods' => [
        'warehouses' => env('OMNIFUL_WAREHOUSES_METHOD', 'post'),
        'suppliers' => env('OMNIFUL_SUPPLIERS_METHOD', 'post'),
        'items' => env('OMNIFUL_ITEMS_METHOD', 'put'),
    ],
    'sync_timeout' => (int) env('OMNIFUL_TIMEOUT', 20),
    'tenant_token_endpoint' => env('OMNIFUL_TENANT_TOKEN_ENDPOINT', '/sales-channel/public/v1/tenants/token'),
    'seller_token_endpoint' => env('OMNIFUL_SELLER_TOKEN_ENDPOINT', '/sales-channel/public/v1/token'),
    'hub_defaults' => [
        'type' => env('OMNIFUL_HUB_TYPE', 'warehouse'),
        'email' => env('OMNIFUL_HUB_EMAIL'),
        'email_domain' => env('OMNIFUL_HUB_EMAIL_DOMAIN', 'example.com'),
        'phone_number' => env('OMNIFUL_HUB_PHONE', '0000000000'),
        'country_code' => env('OMNIFUL_HUB_COUNTRY_CODE', 'SA'),
        'country_calling_code' => env('OMNIFUL_HUB_COUNTRY_CALLING_CODE', '+966'),
        'currency_code' => env('OMNIFUL_HUB_CURRENCY', 'SAR'),
        'currency_name' => env('OMNIFUL_HUB_CURRENCY_NAME'),
        'currency_symbol' => env('OMNIFUL_HUB_CURRENCY_SYMBOL'),
        'timezone' => env('OMNIFUL_HUB_TIMEZONE', 'Asia/Riyadh'),
        'address_line1' => env('OMNIFUL_HUB_ADDRESS_LINE1', 'N/A'),
        'address_line2' => env('OMNIFUL_HUB_ADDRESS_LINE2', ''),
        'city' => env('OMNIFUL_HUB_CITY', 'Riyadh'),
        'state' => env('OMNIFUL_HUB_STATE', ''),
        'country' => env('OMNIFUL_HUB_COUNTRY', 'SA'),
        'postal_code' => env('OMNIFUL_HUB_POSTAL_CODE', '00000'),
        'configuration' => (function () {
            $raw = env('OMNIFUL_HUB_CONFIGURATION', '');
            if ($raw === '') {
                return [
                    'inventory' => true,
                    'picking' => true,
                    'packing' => true,
                    'putaway' => true,
                    'cycle_count' => true,
                    'schedule_order' => true,
                ];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        })(),
    ],
];
