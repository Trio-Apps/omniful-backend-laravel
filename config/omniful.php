<?php

return [
    'sync_endpoints' => [
        'warehouses' => env('OMNIFUL_WAREHOUSES_ENDPOINT', '/warehouses'),
        'suppliers' => env('OMNIFUL_SUPPLIERS_ENDPOINT', '/sales-channel/public/v1/suppliers'),
        'items' => env('OMNIFUL_ITEMS_ENDPOINT', '/items'),
    ],
    'sync_methods' => [
        'warehouses' => env('OMNIFUL_WAREHOUSES_METHOD', 'put'),
        'suppliers' => env('OMNIFUL_SUPPLIERS_METHOD', 'post'),
        'items' => env('OMNIFUL_ITEMS_METHOD', 'put'),
    ],
    'sync_timeout' => (int) env('OMNIFUL_TIMEOUT', 20),
    'token_endpoint' => env('OMNIFUL_TOKEN_ENDPOINT', '/sales-channel/public/v1/token'),
];
