<x-filament::page>
    <style>
        .po-grid { display: grid; gap: 24px; }
        @media (min-width: 768px) { .po-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1024px) {
            .po-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .po-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        .po-card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; background: #ffffff; }
        .po-kv { border: 1px solid #f1f5f9; background: #f8fafc; border-radius: 10px; padding: 12px; }
        .po-label { font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; font-weight: 600; }
        .po-value { margin-top: 4px; font-size: 14px; font-weight: 600; color: #111827; word-break: break-word; }
        .po-break { word-break: break-word; overflow-wrap: anywhere; }
        .po-table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        .po-table th { text-align: left; font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; background: #f3f4f6; padding: 10px 12px; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .po-table td { padding: 10px 12px; border-bottom: 1px solid #eef2f7; font-size: 13px; color: #374151; }
        .po-table tbody tr:hover { background: #f9fafb; }
        .po-num { text-align: center; font-variant-numeric: tabular-nums; }
        .po-empty { text-align: center; color: #6b7280; padding: 12px; }
        .po-table-wrap { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; }
        .po-section-pad { padding: 24px 16px; margin-top: 6px; margin-bottom: 6px; }
        .po-section-gap { margin-bottom: 22px; }
        .po-json { white-space: pre-wrap; font-size: 12px; background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: 16px; overflow-x: auto; }
    </style>

    <div class="space-y-6">
        <x-filament::section class="po-section-gap">
            <x-slot name="heading">Overview</x-slot>
            <div class="po-grid po-grid-3 po-section-pad">
                <div class="po-card">
                    <div class="po-label">Event</div>
                    <div class="po-value">{{ data_get($event, 'event_name', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Order ID</div>
                    <div class="po-value">{{ data_get($data, 'order_id', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Status</div>
                    <div class="po-value">{{ data_get($data, 'status_code', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Seller</div>
                    <div class="po-value">{{ data_get($data, 'seller_code', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Hub</div>
                    <div class="po-value">{{ data_get($data, 'hub_code', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Payment</div>
                    <div class="po-value">{{ data_get($data, 'payment_method', data_get($data, 'invoice.payment_mode', '-')) }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Total</div>
                    <div class="po-value">{{ data_get($data, 'invoice.total', '-') }} {{ data_get($data, 'invoice.currency', '') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Order Created</div>
                    <div class="po-value">{{ data_get($data, 'order_created_at', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Signature</div>
                    <div class="po-value">{{ $record->signature_valid ? 'Valid' : 'Invalid / Missing' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP Status</div>
                    <div class="po-value">{{ $order?->sap_status ?: '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP Order</div>
                    <div class="po-value">{{ $order?->sap_doc_num ?: '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Credit Note</div>
                    <div class="po-value">{{ $order?->sap_credit_note_doc_num ?: ($order?->sap_credit_note_status ?: '-') }}</div>
                </div>
            </div>
        </x-filament::section>

        <div class="po-grid po-grid-2">
            <x-filament::section class="po-section-gap">
                <x-slot name="heading">Customer</x-slot>
                <div class="po-grid po-grid-2 po-section-pad">
                    <div class="po-kv">
                        <div class="po-label">Name</div>
                        <div class="po-value po-break">{{ data_get($data, 'customer.first_name', '-') }} {{ data_get($data, 'customer.last_name', '') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Email</div>
                        <div class="po-value po-break">{{ data_get($data, 'customer.email', '-') }}</div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section class="po-section-gap">
                <x-slot name="heading">Shipment</x-slot>
                <div class="po-grid po-grid-2 po-section-pad">
                    <div class="po-kv">
                        <div class="po-label">Type</div>
                        <div class="po-value">{{ data_get($data, 'shipment_type', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Delivery Type</div>
                        <div class="po-value">{{ data_get($data, 'delivery_type', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Courier</div>
                        <div class="po-value">{{ data_get($data, 'shipment.courier_partner.name', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Delivery Status</div>
                        <div class="po-value">{{ data_get($data, 'shipment.delivery_status', '-') }}</div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <div class="po-grid po-grid-2">
            <x-filament::section class="po-section-gap">
                <x-slot name="heading">Billing Address</x-slot>
                <div class="po-grid po-grid-2 po-section-pad">
                    <div class="po-kv">
                        <div class="po-label">Name</div>
                        <div class="po-value po-break">{{ data_get($data, 'billing_address.first_name', '-') }} {{ data_get($data, 'billing_address.last_name', '') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Phone</div>
                        <div class="po-value po-break">{{ data_get($data, 'billing_address.phone', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Address</div>
                        <div class="po-value po-break">{{ data_get($data, 'billing_address.address1', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Country</div>
                        <div class="po-value">{{ data_get($data, 'billing_address.country', '-') }}</div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section class="po-section-gap">
                <x-slot name="heading">Shipping Address</x-slot>
                <div class="po-grid po-grid-2 po-section-pad">
                    <div class="po-kv">
                        <div class="po-label">Name</div>
                        <div class="po-value po-break">{{ data_get($data, 'shipping_address.first_name', '-') }} {{ data_get($data, 'shipping_address.last_name', '') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Phone</div>
                        <div class="po-value po-break">{{ data_get($data, 'shipping_address.phone', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Address</div>
                        <div class="po-value po-break">{{ data_get($data, 'shipping_address.address1', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Country</div>
                        <div class="po-value">{{ data_get($data, 'shipping_address.country', '-') }}</div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section class="po-section-gap">
            <x-slot name="heading">Items</x-slot>
            <div class="po-table-wrap po-section-pad">
                <table class="po-table">
                    <colgroup>
                        <col style="width: 180px;">
                        <col>
                        <col style="width: 90px;">
                        <col style="width: 110px;">
                        <col style="width: 110px;">
                        <col style="width: 110px;">
                        <col style="width: 90px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th class="po-num">Qty</th>
                            <th class="po-num">Unit Price</th>
                            <th class="po-num">Tax</th>
                            <th class="po-num">Total</th>
                            <th class="po-num">Picked</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td class="po-break">{{ data_get($item, 'sku_code', '-') }}</td>
                                <td class="po-break">{{ data_get($item, 'sku_code', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'quantity', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'unit_price', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'tax', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'total', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'picked_quantity', '-') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="po-empty" colspan="7">No items</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <div class="po-grid po-grid-2">
            <x-filament::section class="po-section-gap">
                <x-slot name="heading">Tracked SAP</x-slot>
                <div class="po-grid po-grid-2 po-section-pad">
                    <div class="po-kv">
                        <div class="po-label">Order Error</div>
                        <div class="po-value po-break">{{ $order?->sap_error ?: '-' }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Payment Status</div>
                        <div class="po-value">{{ $order?->sap_payment_status ?: '-' }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Payment DocNum</div>
                        <div class="po-value">{{ $order?->sap_payment_doc_num ?: '-' }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Card Fee JE</div>
                        <div class="po-value">{{ $order?->sap_card_fee_journal_num ?: ($order?->sap_card_fee_status ?: '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">COGS JE</div>
                        <div class="po-value">{{ $order?->sap_cogs_journal_num ?: ($order?->sap_cogs_status ?: '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Cancel COGS</div>
                        <div class="po-value">{{ $order?->sap_cancel_cogs_journal_num ?: ($order?->sap_cancel_cogs_status ?: '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Latest Event At</div>
                        <div class="po-value">{{ $order?->last_event_at?->format('Y-m-d H:i:s') ?: '-' }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Latest Stored Status</div>
                        <div class="po-value">{{ $order?->omniful_status ?: '-' }}</div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section class="po-section-gap">
                <x-slot name="heading">Payload</x-slot>
                <div class="po-json">{{ $payloadJson }}</div>
            </x-filament::section>
        </div>
    </div>
</x-filament::page>
