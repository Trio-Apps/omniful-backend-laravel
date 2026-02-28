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
        .po-right { text-align: right; }
        .po-num { text-align: center; font-variant-numeric: tabular-nums; }
        .po-nowrap { white-space: nowrap; }
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
                    <div class="po-label">Action</div>
                    <div class="po-value">{{ data_get($event, 'action', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Entity</div>
                    <div class="po-value">{{ data_get($event, 'entity', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Signature</div>
                    <div class="po-value">{{ $record->signature_valid ? 'Valid' : 'Invalid / Missing' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Hub Code</div>
                    <div class="po-value">{{ data_get($data, 'hub_code', data_get($items, '0.hub_code', '-')) }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Items</div>
                    <div class="po-value">{{ is_array($items) ? count($items) : 0 }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Received At</div>
                    <div class="po-value">{{ $record->received_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP Status</div>
                    <div class="po-value">{{ $record->sap_status ?? '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP DocNum</div>
                    <div class="po-value">{{ $record->sap_doc_num ?? '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP DocEntry</div>
                    <div class="po-value">{{ $record->sap_doc_entry ?? '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Reference</div>
                    <div class="po-value">{{ $record->external_id ?: data_get($data, 'display_id', data_get($data, 'status_reference_id', '-')) }}</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section class="po-section-gap">
            <x-slot name="heading">Inventory Items</x-slot>
            <div class="po-table-wrap po-section-pad">
                <table class="po-table">
                    <colgroup>
                        <col style="width: 120px;">
                        <col style="width: 240px;">
                        <col style="width: 80px;">
                        <col style="width: 110px;">
                        <col style="width: 110px;">
                        <col style="width: 110px;">
                        <col style="width: 120px;">
                        <col style="width: 120px;">
                        <col style="width: 90px;">
                        <col style="width: 90px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Seller</th>
                            <th>SKU</th>
                            <th class="po-num">UOM</th>
                            <th class="po-num">On Hand</th>
                            <th class="po-num">Reserved</th>
                            <th class="po-num">Backstorage</th>
                            <th class="po-num">ATP</th>
                            <th class="po-num">ATP + BS</th>
                            <th class="po-num">Pass</th>
                            <th class="po-num">Fail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td class="po-break po-nowrap">{{ data_get($item, 'seller_code', '-') }}</td>
                                <td class="po-break po-nowrap">{{ data_get($item, 'seller_sku_code', '-') }}</td>
                                <td class="po-num po-nowrap">{{ data_get($item, 'uom', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'quantity_on_hand', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'quantity_reserved', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'quantity_backstorage', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'quantity_available_to_promise', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'quantity_available_to_promise_with_backstorage', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'quantity_location_pass_inventory_sum', '-') }}</td>
                                <td class="po-num">{{ data_get($item, 'quantity_location_fail_inventory_sum', '-') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="po-empty" colspan="10">No items</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if ($hasSapResultSummary)
            <x-filament::section class="po-section-gap">
                <x-slot name="heading">SAP Result</x-slot>
                <div class="po-grid po-grid-2 po-section-pad">
                    @if (array_key_exists('gr', $sapResultSummary))
                        <div class="po-kv">
                            <div class="po-label">Goods Receipt</div>
                            <div class="po-value">{{ $sapResultSummary['gr'] ?: '-' }}</div>
                        </div>
                    @endif
                    @if (array_key_exists('gi', $sapResultSummary))
                        <div class="po-kv">
                            <div class="po-label">Goods Issue</div>
                            <div class="po-value">{{ $sapResultSummary['gi'] ?: '-' }}</div>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @elseif ($record->sap_error)
            <x-filament::section class="po-section-gap">
                <x-slot name="heading">SAP Error</x-slot>
                <div class="po-json">{{ $record->sap_error }}</div>
            </x-filament::section>
        @endif

        <x-filament::section class="po-section-gap">
            <x-slot name="heading">Payload</x-slot>
            <div class="po-json">{{ $payloadJson }}</div>
        </x-filament::section>
    </div>
</x-filament::page>
