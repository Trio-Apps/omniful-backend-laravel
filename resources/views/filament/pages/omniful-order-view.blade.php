<x-filament::page>
    <style>
        .po-grid { display: grid; gap: 24px; }
        @media (min-width: 768px) { .po-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .po-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
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
    </style>

    <div class="space-y-6">
        <x-filament::section class="po-section-gap">
            <x-slot name="heading">Overview</x-slot>
            <div class="po-grid po-grid-3 po-section-pad">
                <div class="po-card">
                    <div class="po-label">Event</div>
                    <div class="po-value">{{ data_get($payload, 'event_name', $record->last_event_type ?: '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Display ID</div>
                    <div class="po-value">{{ data_get($data, 'order_id', $record->external_id ?: '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Status</div>
                    <div class="po-value">{{ data_get($data, 'status_code', $record->omniful_status ?: '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Hub Code</div>
                    <div class="po-value">{{ data_get($data, 'hub_code', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Total Amount</div>
                    <div class="po-value">{{ data_get($data, 'invoice.total', data_get($data, 'total', '-')) }} {{ data_get($data, 'invoice.currency', '') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Created At</div>
                    <div class="po-value">{{ data_get($data, 'order_created_at', data_get($data, 'created_at', '-')) }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP Status</div>
                    <div class="po-value">{{ $record->sap_status ?: '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP DocNum</div>
                    <div class="po-value">{{ $record->sap_doc_num ?: '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP Error</div>
                    <div class="po-value po-break">{{ $record->sap_error ?: '-' }}</div>
                </div>
            </div>
        </x-filament::section>

        <div class="po-grid po-grid-2">
            <x-filament::section class="po-section-gap">
                <x-slot name="heading">Supplier</x-slot>
                <div class="po-grid po-grid-2 po-section-pad">
                    <div class="po-kv">
                        <div class="po-label">Name</div>
                        <div class="po-value po-break">{{ data_get($data, 'supplier.name', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Code</div>
                        <div class="po-value po-break">{{ data_get($data, 'supplier.code', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Email</div>
                        <div class="po-value po-break">{{ data_get($data, 'supplier.email', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Phone</div>
                        <div class="po-value po-break">{{ data_get($data, 'supplier.phone', '-') }}</div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section class="po-section-gap">
                <x-slot name="heading">Seller</x-slot>
                <div class="po-grid po-grid-2 po-section-pad">
                    <div class="po-kv">
                        <div class="po-label">Name</div>
                        <div class="po-value po-break">{{ data_get($data, 'store_name', data_get($data, 'seller.name', '-')) }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Code</div>
                        <div class="po-value po-break">{{ data_get($data, 'seller_code', data_get($data, 'seller.code', '-')) }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Email</div>
                        <div class="po-value po-break">{{ data_get($data, 'seller.email', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Phone</div>
                        <div class="po-value po-break">{{ data_get($data, 'seller.phone', '-') }}</div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section class="po-section-gap">
            <x-slot name="heading">Items</x-slot>
            <div class="po-table-wrap po-section-pad">
                <table class="po-table">
                    <colgroup>
                        <col>
                        <col style="width: 90px;">
                        <col style="width: 120px;">
                        <col style="width: 120px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th class="po-num">Qty</th>
                            <th class="po-num">Unit Price</th>
                            <th class="po-num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td class="po-break">{{ data_get($item, 'sku_code', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'quantity', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'unit_price', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'total', '-') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="po-empty" colspan="4">No items</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament::page>
