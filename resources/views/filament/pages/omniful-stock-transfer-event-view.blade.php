<x-filament::page>
    <style>
        .st-grid { display: grid; gap: 24px; }
        @media (min-width: 768px) { .st-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .st-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; background: #ffffff; }
        .st-label { font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; font-weight: 600; }
        .st-value { margin-top: 4px; font-size: 14px; font-weight: 600; color: #111827; word-break: break-word; }
        .st-section-pad { padding: 24px 16px; margin-top: 6px; margin-bottom: 6px; }
        .st-table-wrap { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 8px; background: #ffffff; }
        .st-table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        .st-table th { text-align: left; font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; background: #f3f4f6; padding: 10px 12px; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .st-table td { padding: 10px 12px; border-bottom: 1px solid #eef2f7; font-size: 13px; color: #374151; }
        .st-num { text-align: center; font-variant-numeric: tabular-nums; }
        .st-break { word-break: break-word; overflow-wrap: anywhere; }
        .st-empty { text-align: center; color: #6b7280; padding: 12px; }
        .st-json { white-space: pre-wrap; font-size: 12px; background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: 16px; overflow-x: auto; }
    </style>

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Overview</x-slot>
            <div class="st-grid st-grid-3 st-section-pad">
                <div class="st-card">
                    <div class="st-label">Event</div>
                    <div class="st-value">{{ data_get($event, 'event_name', '-') }}</div>
                </div>
                <div class="st-card">
                    <div class="st-label">Reference</div>
                    <div class="st-value">{{ $record->external_id ?: '-' }}</div>
                </div>
                <div class="st-card">
                    <div class="st-label">Status</div>
                    <div class="st-value">{{ data_get($data, 'status', data_get($event, 'status', '-')) }}</div>
                </div>
                <div class="st-card">
                    <div class="st-label">From Warehouse</div>
                    <div class="st-value">{{ data_get($data, 'from_hub_code', data_get($data, 'source_hub_code', data_get($data, 'source_warehouse_code', '-'))) }}</div>
                </div>
                <div class="st-card">
                    <div class="st-label">To Warehouse</div>
                    <div class="st-value">{{ data_get($data, 'to_hub_code', data_get($data, 'destination_hub_code', data_get($data, 'destination_warehouse_code', '-'))) }}</div>
                </div>
                <div class="st-card">
                    <div class="st-label">Items</div>
                    <div class="st-value">{{ is_array($items) ? count($items) : 0 }}</div>
                </div>
                <div class="st-card">
                    <div class="st-label">SAP Status</div>
                    <div class="st-value">{{ $record->sap_status ?? '-' }}</div>
                </div>
                <div class="st-card">
                    <div class="st-label">SAP DocNum</div>
                    <div class="st-value">{{ $record->sap_doc_num ?? '-' }}</div>
                </div>
                <div class="st-card">
                    <div class="st-label">Received At</div>
                    <div class="st-value">{{ $record->received_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Transfer Items</x-slot>
            <div class="st-table-wrap st-section-pad">
                <table class="st-table">
                    <colgroup>
                        <col style="width: 220px;">
                        <col style="width: 140px;">
                        <col style="width: 180px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th class="st-num">Quantity</th>
                            <th>UOM</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td class="st-break">{{ data_get($item, 'seller_sku_code', data_get($item, 'sku_code', data_get($item, 'item_code', '-'))) }}</td>
                                <td class="st-num">{{ data_get($item, 'transfer_quantity', data_get($item, 'approved_quantity', data_get($item, 'quantity', '-'))) }}</td>
                                <td class="st-break">{{ data_get($item, 'uom', data_get($item, 'sku.package_type', '-')) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="st-empty" colspan="3">No transfer items</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if ($hasSapResultSummary)
            <x-filament::section>
                <x-slot name="heading">SAP Result</x-slot>
                <div class="st-json">{{ json_encode($sapResultSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</div>
            </x-filament::section>
        @elseif ($record->sap_error)
            <x-filament::section>
                <x-slot name="heading">SAP Error</x-slot>
                <div class="st-json">{{ $record->sap_error }}</div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Payload</x-slot>
            <div class="st-json">{{ $payloadJson }}</div>
        </x-filament::section>
    </div>
</x-filament::page>
