<x-filament::page>
    <style>
        .ro-grid { display: grid; gap: 24px; }
        @media (min-width: 768px) { .ro-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1024px) {
            .ro-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .ro-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .ro-grid-5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        }
        .ro-card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; background: #ffffff; }
        .ro-kv { border: 1px solid #f1f5f9; background: #f8fafc; border-radius: 10px; padding: 12px; }
        .ro-label { font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; font-weight: 600; }
        .ro-value { margin-top: 4px; font-size: 14px; font-weight: 600; color: #111827; word-break: break-word; }
        .ro-break { word-break: break-word; overflow-wrap: anywhere; }
        .ro-table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        .ro-table th { text-align: left; font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; background: #f3f4f6; padding: 10px 12px; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .ro-table td { padding: 10px 12px; border-bottom: 1px solid #eef2f7; font-size: 13px; color: #374151; }
        .ro-table tbody tr:hover { background: #f9fafb; }
        .ro-right { text-align: right; }
        .ro-num { text-align: center; font-variant-numeric: tabular-nums; }
        .ro-empty { text-align: center; color: #6b7280; padding: 12px; }
        .ro-table-wrap { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; }
        .ro-section-pad { padding: 24px 16px; margin-top: 6px; margin-bottom: 6px; }
        .ro-section-gap { margin-bottom: 22px; }
    </style>

    <div class="space-y-6">
        <x-filament::section class="ro-section-gap">
            <x-slot name="heading">Overview</x-slot>
            <div class="ro-grid ro-grid-3 ro-section-pad">
                <div class="ro-card">
                    <div class="ro-label">Event</div>
                    <div class="ro-value">{{ data_get($event, 'event_name', '-') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Return Order ID</div>
                    <div class="ro-value">{{ data_get($data, 'return_order_id', data_get($data, 'id', '-')) }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Order ID</div>
                    <div class="ro-value">{{ data_get($data, 'order_id', '-') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Order Ref</div>
                    <div class="ro-value">{{ data_get($data, 'order_reference_id', '-') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Status</div>
                    <div class="ro-value">{{ data_get($data, 'status', '-') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Refund Status</div>
                    <div class="ro-value">{{ data_get($data, 'refund_status', '-') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Type</div>
                    <div class="ro-value">{{ data_get($data, 'type', '-') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Source</div>
                    <div class="ro-value">{{ data_get($data, 'source', '-') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Hub Code</div>
                    <div class="ro-value">{{ data_get($data, 'hub_code', '-') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Payment Method</div>
                    <div class="ro-value">{{ data_get($data, 'payment_method', '-') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Total</div>
                    <div class="ro-value">{{ data_get($data, 'total', '-') }} {{ data_get($data, 'invoice.currency', '') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">Created At</div>
                    <div class="ro-value">{{ data_get($data, 'order_created_at', '-') }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">SAP Status</div>
                    <div class="ro-value">{{ $record->sap_status ?? '-' }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">SAP DocNum</div>
                    <div class="ro-value">{{ $record->sap_doc_num ?? '-' }}</div>
                </div>
                <div class="ro-card">
                    <div class="ro-label">SAP Error</div>
                    <div class="ro-value ro-break">{{ $record->sap_error ?? '-' }}</div>
                </div>
            </div>
        </x-filament::section>

        <div class="ro-grid ro-grid-2">
            <x-filament::section class="ro-section-gap">
                <x-slot name="heading">Customer</x-slot>
                <div class="ro-grid ro-grid-2 ro-section-pad">
                    <div class="ro-kv">
                        <div class="ro-label">First Name</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'customer.first_name', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Last Name</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'customer.last_name', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Email</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'customer.email', '-') }}</div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section class="ro-section-gap">
                <x-slot name="heading">Pickup Address</x-slot>
                <div class="ro-grid ro-grid-2 ro-section-pad">
                    <div class="ro-kv">
                        <div class="ro-label">Address 1</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'pickup_address.address1', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Address 2</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'pickup_address.address2', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">City</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'pickup_address.city', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Country</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'pickup_address.country', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Phone</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'pickup_address.phone', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Email</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'pickup_address.email', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Latitude</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'pickup_address.latitude', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Longitude</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'pickup_address.longitude', '-') }}</div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <div class="ro-grid ro-grid-2">
            <x-filament::section class="ro-section-gap">
                <x-slot name="heading">Shipment</x-slot>
                <div class="ro-grid ro-grid-2 ro-section-pad">
                    <div class="ro-kv">
                        <div class="ro-label">AWB</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'shipment.awb_number', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Partner</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'shipment.shipping_partner_name', data_get($data, 'shipment.shipping_partner_tag', '-')) }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Partner Status</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'shipment.shipping_partner_status', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Shipment Status</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'shipment.status', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Boxes</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'shipment.number_of_boxes', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Weight</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'shipment.weight', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Shipment Created</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'shipment.shipment_created_at', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Order Shipped</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'shipment.order_shipped_at', '-') }}</div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section class="ro-section-gap">
                <x-slot name="heading">Invoice</x-slot>
                <div class="ro-grid ro-grid-4 ro-section-pad">
                    <div class="ro-kv">
                        <div class="ro-label">Currency</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'invoice.currency', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Subtotal</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'invoice.subtotal', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Tax</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'invoice.tax', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Discount</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'invoice.discount', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Total</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'invoice.total', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Total Paid</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'invoice.total_paid', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Total Due</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'invoice.total_due', '-') }}</div>
                    </div>
                    <div class="ro-kv">
                        <div class="ro-label">Tax Percent</div>
                        <div class="ro-value ro-break">{{ data_get($data, 'invoice.tax_percent', '-') }}</div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section class="ro-section-gap">
            <x-slot name="heading">Items</x-slot>
            <div class="ro-table-wrap ro-section-pad">
                <table class="ro-table">
                    <colgroup>
                        <col>
                        <col>
                        <col style="width: 120px;">
                        <col style="width: 120px;">
                        <col style="width: 120px;">
                        <col style="width: 120px;">
                        <col style="width: 120px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th class="ro-num">Return Qty</th>
                            <th class="ro-num">Delivered</th>
                            <th class="ro-num">Unit Price</th>
                            <th class="ro-num">Tax</th>
                            <th class="ro-num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td>{{ data_get($item, 'seller_sku.seller_sku_code', data_get($item, 'seller_sku_code', '-')) }}</td>
                                <td>{{ data_get($item, 'seller_sku.name', data_get($item, 'name', '-')) }}</td>
                                <td class="ro-num">{{ data_get($item, 'return_quantity', '-') }}</td>
                                <td class="ro-num">{{ data_get($item, 'delivered_quantity', '-') }}</td>
                                <td class="ro-num">{{ data_get($item, 'unit_price', '-') }}</td>
                                <td class="ro-num">{{ data_get($item, 'tax', '-') }}</td>
                                <td class="ro-num">{{ data_get($item, 'total', '-') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="ro-empty" colspan="7">No items</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament::page>
