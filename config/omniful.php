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
    'integration_control' => [
        'item_udf_field' => env('SAP_ITEM_INTEGRATION_UDF_FIELD', 'U_OmnifulSync'),
        'item_allowed_values' => array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v)),
            explode(',', (string) env('SAP_ITEM_INTEGRATION_ALLOWED_VALUES', 'Y,YES,TRUE,1,ENABLED'))
        ))),
        'warehouse_udf_field' => env('SAP_WAREHOUSE_INTEGRATION_UDF_FIELD', 'U_OmnifulSync'),
        'warehouse_allowed_values' => array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v)),
            explode(',', (string) env('SAP_WAREHOUSE_INTEGRATION_ALLOWED_VALUES', 'Y,YES,TRUE,1,ENABLED'))
        ))),
    ],
    'sync_timeout' => (int) env('OMNIFUL_TIMEOUT', 20),
    'tenant_token_endpoint' => env('OMNIFUL_TENANT_TOKEN_ENDPOINT', '/sales-channel/public/v1/tenants/token'),
    'seller_token_endpoint' => env('OMNIFUL_SELLER_TOKEN_ENDPOINT', '/sales-channel/public/v1/token'),
    'webhook_signature_header' => env('OMNIFUL_WEBHOOK_SIGNATURE_HEADER', 'X-Omniful-Signature'),
    'webhook_signature_algo' => env('OMNIFUL_WEBHOOK_SIGNATURE_ALGO', 'sha256'),
    'webhook_token_header' => env('OMNIFUL_WEBHOOK_TOKEN_HEADER', 'X-Omniful-Token'),
    'webhook_static_header' => env('OMNIFUL_WEBHOOK_STATIC_HEADER', 'X-Omniful-Auth'),
    'webhook_static_token' => env('OMNIFUL_WEBHOOK_STATIC_TOKEN'),
    'status_mapping' => [
        'purchase_order' => [
            'strict' => true,
            'rules' => [
                [
                    'name' => 'created',
                    'event_contains' => ['create'],
                    'statuses' => ['created', 'pending', 'open', 'processing'],
                    'sap_status' => 'logged',
                ],
                [
                    'name' => 'updated',
                    'event_contains' => ['update'],
                    'statuses' => [],
                    'sap_status' => 'updated',
                ],
                [
                    'name' => 'received',
                    'event_contains' => ['receive'],
                    'statuses' => ['received'],
                    'sap_status' => 'received_logged',
                ],
                [
                    'name' => 'cancelled',
                    'event_contains' => ['cancel'],
                    'statuses' => ['cancelled', 'canceled'],
                    'sap_status' => 'cancel_logged',
                ],
            ],
            'default_sap_status' => 'logged',
        ],
        'inventory' => [
            'strict' => true,
            'routes' => [
                'inventory.update.event|receiving|purchase_order' => 'grpo',
                'inventory.update.event|manual_edit|hub_inventory' => 'manual_inventory_adjustment',
            ],
        ],
        'return_order' => [
            'strict' => true,
            'allowed_statuses' => ['created', 'pending', 'approved', 'received', 'completed', 'cancelled', 'canceled'],
            'allowed_event_contains' => ['return'],
        ],
        'order' => [
            'strict' => true,
            'invoice_event_contains' => ['create', 'new'],
            'invoice_statuses' => ['created', 'new', 'pending', 'confirmed'],
            'delivery_event_contains' => ['ship', 'deliver'],
            'delivery_statuses' => ['shipped', 'delivered', 'completed'],
            'prepaid_indicators' => ['prepaid', 'online', 'card', 'credit_card', 'paid'],
            'cod_indicators' => ['cod', 'cash_on_delivery', 'cash on delivery'],
        ],
    ],
    'order_payment' => [
        'enabled' => (bool) env('OMNIFUL_ORDER_PAYMENT_ENABLED', true),
        'transfer_account' => env('OMNIFUL_INCOMING_PAYMENT_TRANSFER_ACCOUNT', ''),
        'invoice_type_candidates' => array_values(array_filter(array_map(
            fn ($v) => is_numeric(trim((string) $v)) ? (int) trim((string) $v) : null,
            explode(',', (string) env('OMNIFUL_INCOMING_PAYMENT_INVOICE_TYPES', '17,13'))
        ))),
        'card_fee_journal_enabled' => (bool) env('OMNIFUL_CARD_FEE_JOURNAL_ENABLED', false),
        'card_fee_expense_account' => env('OMNIFUL_CARD_FEE_EXPENSE_ACCOUNT', ''),
        'card_fee_offset_account' => env('OMNIFUL_CARD_FEE_OFFSET_ACCOUNT', ''),
        'card_fee_percent' => (float) env('OMNIFUL_CARD_FEE_PERCENT', 0),
    ],
    'order_accounting' => [
        'cogs_journal_enabled' => (bool) env('OMNIFUL_COGS_JOURNAL_ENABLED', false),
        'cogs_expense_account' => env('OMNIFUL_COGS_EXPENSE_ACCOUNT', ''),
        'inventory_offset_account' => env('OMNIFUL_COGS_INVENTORY_OFFSET_ACCOUNT', ''),
        'return_cogs_reversal_enabled' => (bool) env('OMNIFUL_RETURN_COGS_REVERSAL_ENABLED', false),
    ],
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
