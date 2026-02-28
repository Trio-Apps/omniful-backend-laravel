<?php

return [
    'sync_endpoints' => [
        'warehouses' => env('OMNIFUL_WAREHOUSES_ENDPOINT', '/sales-channel/public/v1/tenants/hubs'),
        'suppliers' => env('OMNIFUL_SUPPLIERS_ENDPOINT', '/sales-channel/public/v1/suppliers'),
        'items' => env('OMNIFUL_ITEMS_ENDPOINT', '/sales-channel/public/v1/skus'),
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
        'supplier_udf_field' => env('SAP_SUPPLIER_INTEGRATION_UDF_FIELD', 'U_OmnifulSync'),
        'supplier_allowed_values' => array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v)),
            explode(',', (string) env('SAP_SUPPLIER_INTEGRATION_ALLOWED_VALUES', 'Y,YES,TRUE,1,ENABLED'))
        ))),
    ],
    'sync_timeout' => (int) env('OMNIFUL_TIMEOUT', 20),
    'push_batch' => [
        'suppliers' => (int) env('OMNIFUL_PUSH_BATCH_SUPPLIERS', 50),
    ],
    'sap_item_defaults' => [
        'item_type' => env('SAP_ITEM_TYPE', 'itItems'),
        'item_type_numeric_fallback' => (int) env('SAP_ITEM_TYPE_NUMERIC_FALLBACK', 0),
        'item_type_udf_field' => env('SAP_ITEM_TYPE_UDF_FIELD', ''),
        'item_type_udf_value' => env('SAP_ITEM_TYPE_UDF_VALUE', ''),
        'item_type_default_value' => env('SAP_ITEM_TYPE_DEFAULT_VALUE', 'P'),
        'product_type_default_value' => env('SAP_PRODUCT_TYPE_DEFAULT_VALUE', 'product'),
    ],
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
                'inventory.update.event|dispose|inventory_adjustment' => 'manual_inventory_adjustment',
                'inventory.update.event|manual_edit|hub_inventory' => 'manual_inventory_adjustment',
                'inventory.update.event|cycle_count|hub_inventory' => 'inventory_counting',
                'inventory.update.event|inventory_counting|hub_inventory' => 'inventory_counting',
                'inventory.update.event|counting|hub_inventory' => 'inventory_counting',
                'inventory.update.event|cycle_count|inventory' => 'inventory_counting',
                'inventory.update.event|inventory_counting|inventory' => 'inventory_counting',
            ],
        ],
        'return_order' => [
            'strict' => true,
            'allowed_statuses' => [
                'created',
                'pending',
                'approved',
                'received',
                'completed',
                'cancelled',
                'canceled',
                'return_request_approved',
                'return_request_rejected',
                'return_initiated',
                'return_shipment_created',
                'return_order_arrived_at_hub',
                'return_order_qc_processed',
                'return_order_picked_up',
                'return_order_cancelled',
                'return_order_canceled',
                'return_order_completed',
                'return_to_origin',
            ],
            'allowed_event_contains' => ['return'],
        ],
        'order' => [
            'strict' => true,
            'invoice_event_contains' => ['create', 'new'],
            'invoice_statuses' => ['created', 'new', 'pending', 'confirmed'],
            'initial_statuses' => ['created', 'new', 'pending', 'confirmed', 'on_hold'],
            'delivery_event_contains' => ['ship', 'deliver'],
            'delivery_statuses' => ['shipped', 'dispatched', 'out_for_delivery', 'in_transit', 'partially_delivered', 'delivered', 'completed'],
            'credit_note_event_contains' => ['cancel'],
            'credit_note_statuses' => ['cancelled', 'canceled', 'returned'],
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
    'order_sync' => [
        'append_comment' => (bool) env('OMNIFUL_ORDER_SYNC_APPEND_COMMENT', true),
        'status_udf_field' => env('OMNIFUL_ORDER_STATUS_UDF_FIELD', ''),
        'event_udf_field' => env('OMNIFUL_ORDER_EVENT_UDF_FIELD', ''),
        'updated_at_udf_field' => env('OMNIFUL_ORDER_UPDATED_AT_UDF_FIELD', ''),
    ],
    'order_fallback' => [
        'customer_code' => env('OMNIFUL_FALLBACK_CUSTOMER_CODE', ''),
    ],
    'sap_cost_centers' => [
        'costing_code' => env('SAP_COSTING_CODE', ''),
        'costing_code2' => env('SAP_COSTING_CODE2', ''),
        'costing_code3' => env('SAP_COSTING_CODE3', ''),
        'costing_code4' => env('SAP_COSTING_CODE4', ''),
        'costing_code5' => env('SAP_COSTING_CODE5', ''),
        'project_code' => env('SAP_PROJECT_CODE', ''),
        'apply_to_stock_transfer' => (bool) env('SAP_COST_CENTER_STOCK_TRANSFER', false),
    ],
    'stock_transfer' => [
        'in_transit_enabled' => (bool) env('OMNIFUL_IN_TRANSIT_ENABLED', false),
        'in_transit_warehouse' => env('OMNIFUL_IN_TRANSIT_WAREHOUSE', ''),
        'force_in_transit' => (bool) env('OMNIFUL_IN_TRANSIT_FORCE', false),
    ],
    'warehouse_resolution' => [
        'auto_create' => (bool) env('SAP_WAREHOUSE_AUTO_CREATE', false),
        'map' => (function () {
            $raw = trim((string) env('SAP_WAREHOUSE_MAP', ''));
            if ($raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            $pairs = array_filter(array_map('trim', explode(',', $raw)));
            $map = [];
            foreach ($pairs as $pair) {
                if (!str_contains($pair, ':')) {
                    continue;
                }

                [$source, $target] = array_map('trim', explode(':', $pair, 2));
                if ($source !== '' && $target !== '') {
                    $map[$source] = $target;
                }
            }

            return $map;
        })(),
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
