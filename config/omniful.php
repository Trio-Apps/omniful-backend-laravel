<?php

return [
    'dashboard_actions' => [
        'master_data_sync_enabled' => (bool) env('OMNIFUL_DASHBOARD_MASTER_DATA_SYNC_ENABLED', false),
    ],
    // Master-data sync enablement per domain. Items and suppliers sync
    // SAP -> Omniful (one direction, locked — see IntegrationDirectionService).
    // Warehouses sync Omniful -> SAP. Each domain's pages/syncs are gated by
    // its flag; inventory is a separate webhook-driven flow not governed here.
    'master_data_sync' => [
        'items_enabled' => (bool) env('OMNIFUL_SYNC_ITEMS_ENABLED', true),
        'suppliers_enabled' => (bool) env('OMNIFUL_SYNC_SUPPLIERS_ENABLED', true),
        'warehouses_enabled' => (bool) env('OMNIFUL_SYNC_WAREHOUSES_ENABLED', true),
    ],
    'sync_endpoints' => [
        'warehouses' => env('OMNIFUL_WAREHOUSES_ENDPOINT', '/sales-channel/public/v1/tenants/hubs'),
        'suppliers' => env('OMNIFUL_SUPPLIERS_ENDPOINT', '/sales-channel/public/v1/suppliers'),
        'items' => env('OMNIFUL_ITEMS_ENDPOINT', '/sales-channel/public/v1/master/skus'),
        'kits' => env('OMNIFUL_KITS_ENDPOINT', '/sales-channel/public/v1/master/skus/kits'),
    ],
    'sync_methods' => [
        'warehouses' => env('OMNIFUL_WAREHOUSES_METHOD', 'post'),
        'suppliers' => env('OMNIFUL_SUPPLIERS_METHOD', 'post'),
        'items' => env('OMNIFUL_ITEMS_METHOD', 'post'),
        'kits' => env('OMNIFUL_KITS_METHOD', 'post'),
    ],
    // Method used to UPDATE an existing record on the SAME collection endpoint
    // when Omniful reports it already exists. Per the Omniful API, SKUs and kits
    // update via PUT to /master/skus and /master/skus/kits. Suppliers and
    // warehouses have NO update endpoint (create only) — left empty so an
    // existing record is treated as already in sync instead of erroring.
    'sync_update_methods' => [
        'warehouses' => env('OMNIFUL_WAREHOUSES_UPDATE_METHOD', ''),
        'suppliers' => env('OMNIFUL_SUPPLIERS_UPDATE_METHOD', ''),
        'items' => env('OMNIFUL_ITEMS_UPDATE_METHOD', 'put'),
        'kits' => env('OMNIFUL_KITS_UPDATE_METHOD', 'put'),
    ],
    // SAP -> Omniful item/bundle integration driven by item-master UDF flags.
    // We read OITM items whose integrated flag = "not integrated" and:
    //   * sales-only items (SalesItem=tYES, InventoryItem=tNO) are bundles:
    //     look them up in the ZIDCOMBO UDO; if it has sub-item lines, create a
    //     KIT in Omniful and mark integrated + bundle-integrated; otherwise
    //     ignore (do not integrate).
    //   * inventory items (InventoryItem=tYES) are pushed to Omniful as a SKU
    //     and marked integrated.
    // Field names are configurable because they differ per SAP company.
    'item_integration' => [
        // OITM UDF that flags whether an item has been integrated to Omniful.
        'integrated_udf_field' => env('SAP_ITEM_INTEGRATED_UDF', 'U_omInt'),
        // OITM UDF that flags whether a bundle/combo has been integrated.
        'bundle_integrated_udf_field' => env('SAP_ITEM_BUNDLE_INTEGRATED_UDF', 'U_OmBInt'),
        // Value that means "not integrated yet" (what we read) and "integrated"
        // (what we stamp after a successful push).
        'not_integrated_value' => env('SAP_ITEM_NOT_INTEGRATED_VALUE', 'N'),
        'integrated_value' => env('SAP_ITEM_INTEGRATED_VALUE', 'Y'),
        // SAP UDO holding combo/bundle definitions (Service Layer object code,
        // NOT the @table name) and its embedded sub-items collection + fields.
        'combo_udo' => env('SAP_COMBO_UDO', 'ZIDCOMBO'),
        'combo_lines_collection' => env('SAP_COMBO_LINES_COLLECTION', 'ZID_COMBOSLINESCollection'),
        'combo_line_item_field' => env('SAP_COMBO_LINE_ITEM_FIELD', 'U_ItemCode'),
        'combo_line_qty_field' => env('SAP_COMBO_LINE_QTY_FIELD', 'U_QTY'),
        // Default currency stamped on the Omniful KIT payload.
        'kit_currency' => env('OMNIFUL_KIT_CURRENCY', 'SAR'),
        // Max items processed per run (0 = unlimited).
        'batch_limit' => (int) env('SAP_ITEM_INTEGRATION_BATCH_LIMIT', 0),
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
    // SAP -> Omniful supplier integration driven by a BP-master UDF flag,
    // mirroring the item flow: only Business Partners (suppliers) whose
    // U_OmBPInt = "N" are pulled; after a successful push to Omniful the flag
    // is stamped back to "Y". Field name/values are configurable per company.
    'supplier_integration' => [
        'integrated_udf_field' => env('SAP_SUPPLIER_INTEGRATED_UDF', 'U_OmBPInt'),
        'not_integrated_value' => env('SAP_SUPPLIER_NOT_INTEGRATED_VALUE', 'N'),
        'integrated_value' => env('SAP_SUPPLIER_INTEGRATED_VALUE', 'Y'),
    ],
    'sync_timeout' => (int) env('OMNIFUL_TIMEOUT', 20),
    'push_batch' => [
        'suppliers' => (int) env('OMNIFUL_PUSH_BATCH_SUPPLIERS', 50),
        // Number of items sent to Omniful per bulk request (the /skus endpoint
        // accepts an array). Sending one item per request triggers Omniful's
        // 429 rate limit on large catalogs; batching collapses 200 requests
        // into a handful.
        'items' => (int) env('OMNIFUL_PUSH_BATCH_ITEMS', 25),
        // Pause (ms) between batches to stay under the rate limit.
        'delay_ms' => (int) env('OMNIFUL_PUSH_BATCH_DELAY_MS', 200),
    ],
    // Stamped as "created_by" on every Omniful catalog payload (items/kits/
    // suppliers) so records pushed from here are attributable to SAP.
    'created_by' => env('OMNIFUL_CREATED_BY', 'Sap'),
    // Stamped as "updated_by" when an existing record is updated (the PUT path).
    'updated_by' => env('OMNIFUL_UPDATED_BY', 'Sap'),
    'item_push_defaults' => [
        'type' => env('OMNIFUL_ITEM_TYPE', 'simple'),
        'status' => env('OMNIFUL_ITEM_STATUS', 'live'),
        'uom' => env('OMNIFUL_ITEM_UOM', 'ea'),
        // Seller scope for tenant-side SKU creation; set empty to omit the field.
        'seller_code' => env('OMNIFUL_ITEM_SELLER_CODE', 'PL-873'),
        'is_perishable' => (bool) env('OMNIFUL_ITEM_IS_PERISHABLE', false),
        'retail_price' => (float) env('OMNIFUL_ITEM_RETAIL_PRICE', 1),
        'selling_price' => (float) env('OMNIFUL_ITEM_SELLING_PRICE', 1),
        'currency' => env('OMNIFUL_ITEM_CURRENCY', 'SAR'),
        'description' => env('OMNIFUL_ITEM_DESCRIPTION', 'N/A'),
        // Weight + dimensions are not provided per-item by SAP OITM, so these
        // config defaults are sent for every SKU. A weight uom other than "ea"
        // is treated as "ea" by Omniful unless weighted SKUs are enabled.
        'weight_value' => (float) env('OMNIFUL_ITEM_WEIGHT_VALUE', 1),
        'weight_uom' => env('OMNIFUL_ITEM_WEIGHT_UOM', 'ea'),
        'dimension_length' => (float) env('OMNIFUL_ITEM_DIMENSION_LENGTH', 10),
        'dimension_breadth' => (float) env('OMNIFUL_ITEM_DIMENSION_BREADTH', 5),
        'dimension_height' => (float) env('OMNIFUL_ITEM_DIMENSION_HEIGHT', 15),
        'dimension_unit' => env('OMNIFUL_ITEM_DIMENSION_UNIT', 'cm'),
        'barcode_fallback_to_code' => (bool) env('OMNIFUL_ITEM_BARCODE_FALLBACK_TO_CODE', true),
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
                    'statuses' => ['created', 'pending', 'open', 'processing', 'ordered'],
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
                [
                    // Omniful fires purchase_order.close.event once a PO has
                    // been fully GRN'd and put away. SAP B1 auto-closes the
                    // PO when all lines are received via GRPO, so this is
                    // purely informational — we just log the close.
                    'name' => 'closed',
                    'event_contains' => ['close', 'closed'],
                    'statuses' => ['closed', 'completed', 'fully_received'],
                    'sap_status' => 'closed_logged',
                ],
            ],
            'default_sap_status' => 'logged',
        ],
        'inventory' => [
            'strict' => true,
            'routes' => [
                'inventory.update.event|receiving|purchase_order' => 'grpo',
                'inventory.update.event|dispose|inventory_adjustment' => 'manual_inventory_adjustment',
                'inventory.update.event|conversion|inventory_adjustment' => 'manual_inventory_adjustment',
                'inventory.update.event|manual_edit|hub_inventory' => 'manual_inventory_adjustment',
                'inventory.update.event|inventory_posting|hub_inventory' => 'inventory_posting',
                'inventory.update.event|inventory_posting|inventory' => 'inventory_posting',
                'inventory.update.event|inventory_posting|inventory_adjustment' => 'inventory_posting',
                'inventory.update.event|posting|hub_inventory' => 'inventory_posting',
                'inventory.update.event|posting|inventory' => 'inventory_posting',
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
                'initiated',
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
            'delivery_statuses' => ['shipped'],
            'credit_note_event_contains' => ['cancel'],
            'credit_note_statuses' => ['cancelled', 'canceled', 'returned', 'return_to_origin'],
            'prepaid_indicators' => ['prepaid', 'online', 'card', 'credit_card', 'paid'],
            'cod_indicators' => ['cod', 'cash_on_delivery', 'cash on delivery'],
        ],
    ],
    'order_payment' => [
        'enabled' => (bool) env('OMNIFUL_ORDER_PAYMENT_ENABLED', true),
        'transfer_account' => env('OMNIFUL_INCOMING_PAYMENT_TRANSFER_ACCOUNT', ''),
        'method_transfer_accounts' => [
            'visa' => 'CC',
            'master' => 'CC',
            'mastercard' => 'CC',
            'tamara' => 'CC',
            'tabby' => 'CC',
            'tab' => 'CC',
            'tap' => 'CC',
            'tapkeynet' => 'CC',
            'tapmada' => 'CC',
            'tapcreditcard' => 'CC',
            'tapapplepay' => 'CC',
        ],
        'method_credit_cards' => [
            'mada' => 1,
            'visa' => 2,
            'master' => 3,
            'mastercard' => 3,
            'tabby' => 6,
            'tamara' => 7,
            'applepay' => 8,
            'tapapplepay' => 8,
            'amex' => 9,
            'americanexpress' => 9,
            'zidpay' => 18,
        ],
        'invoice_type_candidates' => array_values(array_filter(array_map(
            fn ($v) => is_numeric(trim((string) $v)) ? (int) trim((string) $v) : null,
            explode(',', (string) env('OMNIFUL_INCOMING_PAYMENT_INVOICE_TYPES', '17,13'))
        ))),
        'card_fee_journal_enabled' => (bool) env('OMNIFUL_CARD_FEE_JOURNAL_ENABLED', true),
        'card_fee_expense_account' => env('OMNIFUL_CARD_FEE_EXPENSE_ACCOUNT', '2102001'),
        'card_fee_offset_account' => env('OMNIFUL_CARD_FEE_OFFSET_ACCOUNT', ''),
        'card_fee_percent' => (float) env('OMNIFUL_CARD_FEE_PERCENT', 0),
        // Payment gateway / card fees in Saudi Arabia are taxable services
        // (ZATCA standard 15% VAT). Configure the input VAT recoverable G/L
        // account here so the card fee journal splits gross -> net + input VAT.
        // Leaving the account blank reverts to the legacy 2-line JE (no VAT).
        'card_fee_vat_percent' => (float) env('OMNIFUL_CARD_FEE_VAT_PERCENT', 15),
        'card_fee_vat_recoverable_account' => env('OMNIFUL_CARD_FEE_VAT_RECOVERABLE_ACCOUNT', ''),
        'card_fee_method_percent_map' => [
            'tamara' => 4.0,
            'tabby' => 4.0,
            'tab' => 1.5,
            'tap' => 2.0,
            'tapkeynet' => 1.5,
            'mada' => 0.9,
            'tapmada' => 0.9,
            'visa' => 2.0,
            'master' => 2.0,
            'mastercard' => 2.0,
            'tapcreditcard' => 2.0,
            'applepay' => 1.5,
            'tapapplepay' => 1.5,
            'tabbyaddon' => 1.0,
            'tamaraaddon' => 1.5,
            'zidpaymada' => 0.75,
            'zidpayvisa' => 1.75,
            'zidpay' => 1.75,
        ],
    ],
    'order_accounting' => [
        'cogs_journal_enabled' => (bool) env('OMNIFUL_COGS_JOURNAL_ENABLED', true),
        'cogs_expense_account' => env('OMNIFUL_COGS_EXPENSE_ACCOUNT', ''),
        'inventory_offset_account' => env('OMNIFUL_COGS_INVENTORY_OFFSET_ACCOUNT', ''),
        'return_cogs_reversal_enabled' => (bool) env('OMNIFUL_RETURN_COGS_REVERSAL_ENABLED', false),
    ],
    'order_sync' => [
        'append_comment' => (bool) env('OMNIFUL_ORDER_SYNC_APPEND_COMMENT', true),
        'status_udf_field' => env('OMNIFUL_ORDER_STATUS_UDF_FIELD', ''),
        'event_udf_field' => env('OMNIFUL_ORDER_EVENT_UDF_FIELD', ''),
        'updated_at_udf_field' => env('OMNIFUL_ORDER_UPDATED_AT_UDF_FIELD', ''),
        'order_number_udf_field' => 'U_omo',
        'channel_udf_field' => 'U_omChannel',
    ],
    'purchase_order' => [
        // SAP supplier (CardCode) used for Omniful POs/GRPOs when the Omniful
        // payload carries no supplier. Set empty to keep the synthetic-vendor
        // fallback. Must be an existing OCRD supplier CardCode.
        'fallback_supplier_code' => env('OMNIFUL_PO_FALLBACK_SUPPLIER_CODE', 'Dokhon'),
        // Supplier codes whose PO/GRPO webhooks are IGNORED (not created in SAP).
        // Comma/space separated. Managed from the Integration Settings page; this
        // env value is only a fallback. Matched case-insensitively.
        'ignored_supplier_codes' => env('OMNIFUL_PO_IGNORED_SUPPLIER_CODES', ''),
    ],
    'order_tax' => [
        'ksa_taxable_code' => 'SOV',
        'ksa_zero_tax_code' => 'EOV',
        'foreign_code' => 'EOV',
    ],
    // Purchase Orders / GRPO use INPUT VAT codes, not the OUTPUT VAT codes
    // configured above for sales. Leave any of these blank to fall back to
    // the SAP Item Master "Tax Group" default (recommended on tenants where
    // the item master is already configured correctly).
    'purchase_tax' => [
        'ksa_taxable_code' => env('OMNIFUL_PURCHASE_TAX_KSA_TAXABLE_CODE', ''),
        'ksa_zero_tax_code' => env('OMNIFUL_PURCHASE_TAX_KSA_ZERO_CODE', ''),
        'foreign_code' => env('OMNIFUL_PURCHASE_TAX_FOREIGN_CODE', ''),
    ],
    'order_rounding' => [
        // Send Rounding=tYES on AR Reserve Invoices so SAP rounds the stored
        // DocTotal to the company currency precision and the Incoming Payment
        // settles to zero. Requires the SAP Rounding G/L Account to be
        // configured first; enable from the dashboard toggle after that.
        'enabled' => (bool) env('OMNIFUL_ORDER_ROUNDING_ENABLED', false),
    ],
    'order_freight' => [
        // Freight expense (DocumentAdditionalExpenses.ExpenseCode) for
        // DOMESTIC (KSA) customers. SAP often keeps separate freight expense
        // definitions per local/foreign tax treatment.
        'expense_code' => 1,
        // Freight expense code for FOREIGN customers. Falls back to the
        // domestic code above when left empty.
        'expense_code_foreign' => (int) env('OMNIFUL_FREIGHT_EXPENSE_CODE_FOREIGN', 2),
        // When set, freight is posted as a DocumentLines item line (not as a
        // DocumentAdditionalExpense) using PriceAfterVAT = freight_gross. This
        // is the only way to land freight on a clean 2-dp gross on tenants
        // where SAP rounds DocumentAdditionalExpenses.LineTotal to 2-dp on
        // input (and so the per-line VAT recomputation always introduces a
        // 3-dp tail that re-opens Balance Due). The item code is
        // auto-provisioned in SAP on first use, with SalesItem=tYES.
        'item_code' => env('OMNIFUL_FREIGHT_ITEM_CODE', 'FREIGHT'),
        'item_name' => env('OMNIFUL_FREIGHT_ITEM_NAME', 'Freight / Shipping'),
    ],
    'order_fallback' => [
        'customer_code' => env('OMNIFUL_FALLBACK_CUSTOMER_CODE', ''),
        'customer_code_by_source' => (function () {
            $raw = trim((string) env('OMNIFUL_ORDER_CUSTOMER_CODE_BY_SOURCE', ''));
            if ($raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $map = [];
                foreach ($decoded as $source => $customerCode) {
                    $source = strtolower(trim((string) $source));
                    $customerCode = trim((string) $customerCode);
                    if ($source !== '' && $customerCode !== '') {
                        $map[$source] = $customerCode;
                    }
                }

                return $map;
            }

            $pairs = array_filter(array_map('trim', explode(',', $raw)));
            $map = [];
            foreach ($pairs as $pair) {
                if (!str_contains($pair, ':')) {
                    continue;
                }

                [$source, $customerCode] = array_map('trim', explode(':', $pair, 2));
                if ($source !== '' && $customerCode !== '') {
                    $map[strtolower($source)] = $customerCode;
                }
            }

            return $map;
        })(),
        'warehouse_code' => env('OMNIFUL_ORDER_FALLBACK_WAREHOUSE_CODE', ''),
    ],
    'order_customer_mapping' => [
        'local_customer_code' => env('OMNIFUL_LOCAL_CUSTOMER_CODE', 'C00046'),
        'foreign_customer_code' => env('OMNIFUL_FOREIGN_CUSTOMER_CODE', 'C00047'),
        'local_country_tokens' => array_values(array_filter(array_map(
            fn ($value) => strtoupper(trim((string) $value)),
            explode(',', (string) env('OMNIFUL_LOCAL_COUNTRY_TOKENS', 'SA,SAU,KSA,SAUDI ARABIA'))
        ))),
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
    'goods_issue' => [
        // Disposal (action=dispose) Goods Issue UDFs. Set empty to omit.
        // reason_udf_field must exist on IGE1 (lines); reference_udf_field on
        // OIGE (header).
        'reason_udf_field' => env('OMNIFUL_GOODS_ISSUE_REASON_UDF_FIELD', 'U_Reason'),
        'reference_udf_field' => env('OMNIFUL_GOODS_ISSUE_REFERENCE_UDF_FIELD', 'U_omo'),
    ],
    'stock_transfer' => [
        'in_transit_enabled' => (bool) env('OMNIFUL_IN_TRANSIT_ENABLED', false),
        'in_transit_warehouse' => env('OMNIFUL_IN_TRANSIT_WAREHOUSE', ''),
        'force_in_transit' => (bool) env('OMNIFUL_IN_TRANSIT_FORCE', false),
        // Stamp the destination warehouse of each StockTransfer line into this
        // row-level UDF (in addition to the standard WarehouseCode). Set empty
        // to disable. Requires the UDF to exist on the WTR1 (lines) table.
        'line_destination_udf_field' => env('OMNIFUL_STOCK_TRANSFER_DEST_UDF_FIELD', 'U_WhsDest'),
        // Stock Transfer Events monitor screen focuses on these two
        // statuses by default (left the source warehouse / arrived at the
        // target warehouse). All other statuses are still recorded and
        // remain reachable from the Status filter, just hidden on first load.
        'monitor_focus_statuses' => (function () {
            $raw = trim((string) env('OMNIFUL_STOCK_TRANSFER_FOCUS_STATUSES', 'shipped,received'));
            $parts = array_values(array_filter(array_map(
                static fn ($s) => strtolower(trim((string) $s)),
                explode(',', $raw)
            )));

            return $parts !== [] ? $parts : ['shipped', 'received'];
        })(),
    ],
    'inventory_monitor' => [
        // Inventory Events screen focuses on the "completed" status by
        // default (counting / cycle-count completion). All other statuses
        // are still recorded and reachable from the Status filter, just
        // hidden on first load.
        'monitor_focus_statuses' => (function () {
            $raw = trim((string) env('OMNIFUL_INVENTORY_FOCUS_STATUSES', 'completed'));
            $parts = array_values(array_filter(array_map(
                static fn ($s) => strtolower(trim((string) $s)),
                explode(',', $raw)
            )));

            return $parts !== [] ? $parts : ['completed'];
        })(),
    ],
    'warehouse_resolution' => [
        'auto_create' => (bool) env('SAP_WAREHOUSE_AUTO_CREATE', false),
        // Minutes to cache a resolved (case-corrected) SAP warehouse code so
        // repeated lookups across many orders don't re-hit SAP. 0 disables.
        'cache_ttl_minutes' => (int) env('SAP_WAREHOUSE_RESOLVE_CACHE_MINUTES', 1440),
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
        'phone_number' => env('OMNIFUL_HUB_PHONE', '555555555'),
        'country_code' => env('OMNIFUL_HUB_COUNTRY_CODE', 'SA'),
        'country_calling_code' => env('OMNIFUL_HUB_COUNTRY_CALLING_CODE', '+966'),
        'currency_code' => env('OMNIFUL_HUB_CURRENCY', 'SAR'),
        'currency_name' => env('OMNIFUL_HUB_CURRENCY_NAME', 'Saudi Riyal'),
        'currency_display_name' => env('OMNIFUL_HUB_CURRENCY_DISPLAY_NAME', 'SAR (Saudi Riyal)'),
        'timezone' => env('OMNIFUL_HUB_TIMEZONE', 'Asia/Riyadh'),
        'address_line1' => env('OMNIFUL_HUB_ADDRESS_LINE1', 'N/A'),
        'address_line2' => env('OMNIFUL_HUB_ADDRESS_LINE2', 'Industrial Area'),
        'building_number' => '1234',
        'city' => env('OMNIFUL_HUB_CITY', 'Riyadh'),
        'state' => env('OMNIFUL_HUB_STATE', ''),
        'country' => env('OMNIFUL_HUB_COUNTRY', 'Saudi Arabia'),
        'postal_code' => env('OMNIFUL_HUB_POSTAL_CODE', '122001'),
        'address' => [
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'national_address_code' => 'RIYD0001',
            'additional_number' => '1233',
            'street' => 'King Fahd Road',
            'area' => 'Riyadh',
        ],
        'is_click_and_collect' => (bool) env('OMNIFUL_HUB_CLICK_AND_COLLECT', false),
        'is_pos_enabled' => (bool) env('OMNIFUL_HUB_POS_ENABLED', false),
        'is_wms_enabled' => (bool) env('OMNIFUL_HUB_WMS_ENABLED', true),
        'services' => ['wms'],
        'working_hours' => [
            'monday' => [['start_time' => 900, 'end_time' => 2359]],
            'tuesday' => [['start_time' => 900, 'end_time' => 2359]],
            'wednesday' => [['start_time' => 900, 'end_time' => 2359]],
            'thursday' => [['start_time' => 900, 'end_time' => 2359]],
            'friday' => [['start_time' => 900, 'end_time' => 2359]],
            'saturday' => [['start_time' => 900, 'end_time' => 2359]],
            'sunday' => [['start_time' => 900, 'end_time' => 2359]],
        ],
        'configuration' => (function () {
            $raw = env('OMNIFUL_HUB_CONFIGURATION', '');
            if ($raw === '') {
                return [
                    'adjust_inventory' => [
                        'manual_barcode_allowed' => false,
                        'enter_quantity_manually' => false,
                    ],
                    'gate_entry' => [
                        'enabled' => false,
                    ],
                    'bulk_ship_configuration' => [
                        'picker_capacity' => 100,
                    ],
                    'picking' => [
                        'enabled' => true,
                        'cart_capacity' => 9,
                        'picker_capacity' => 1,
                        'bin_scan_enabled' => true,
                        'single_order_tags' => [],
                        'location_scan_enabled' => false,
                        'manual_barcode_allowed' => false,
                        'enter_quantity_manually' => false,
                        'multi_order_picking_type' => 3,
                        'single_order_picking_type' => 2,
                        'multi_order_picking_allowed' => false,
                        'single_order_picking_allowed' => true,
                        'multi_piece_picking_cart_association' => false,
                        'single_piece_picking_cart_association' => false,
                    ],
                    'put_away' => [
                        'manual_barcode_allowed' => false,
                        'enter_quantity_manually' => false,
                    ],
                    'location_configuration' => [
                        'layout' => [
                            'bin' => [
                                'exist' => false,
                                'order' => 8,
                                'prefix' => 'B',
                                'display_name' => 'Bin',
                            ],
                            'hall' => [
                                'exist' => false,
                                'order' => 3,
                                'prefix' => 'H',
                                'display_name' => 'Hall',
                            ],
                            'rack' => [
                                'exist' => false,
                                'order' => 5,
                                'prefix' => 'R',
                                'display_name' => 'Rack',
                            ],
                            'aisle' => [
                                'exist' => false,
                                'order' => 4,
                                'prefix' => 'A',
                                'display_name' => 'Aisle',
                            ],
                            'floor' => [
                                'exist' => false,
                                'order' => 2,
                                'prefix' => 'F',
                                'display_name' => 'Floor',
                            ],
                            'shelf' => [
                                'exist' => false,
                                'order' => 6,
                                'prefix' => 'S',
                                'display_name' => 'Shelf',
                            ],
                            'location' => [
                                'exist' => false,
                                'order' => 7,
                                'prefix' => 'L',
                                'display_name' => 'Location',
                            ],
                            'custom_prefix' => [
                                'exist' => true,
                                'order' => 1,
                                'prefix' => 'P',
                                'display_name' => 'Prefix',
                            ],
                        ],
                        'enabled' => false,
                    ],
                    'expiry_configuration' => [
                        'include_near_expiry_items' => false,
                    ],
                    'acceptable_shelf_life_validation' => [
                        'enabled' => false,
                    ],
                    'schedule_order' => [
                        'time' => [
                            'hour' => '2',
                            'minute' => '45',
                        ],
                        'enabled' => true,
                    ],
                    'cycle_count_configuration' => [
                        'blind_count' => true,
                        'update_inventory' => true,
                        'manual_barcode_allowed' => false,
                        'enter_quantity_manually' => false,
                    ],
                    'packing_configuration' => [
                        'qc_enabled' => true,
                        'manual_barcode_allowed' => true,
                        'enter_quantity_manually' => false,
                        'automatic_shipment_creation' => false,
                        'auto_print_awb_on_all_items_scanned' => false,
                    ],
                    'purchase_order' => [
                        'enabled' => true,
                        'over_receive_allowed' => true,
                    ],
                ];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        })(),
    ],
];
