<x-filament::page>
    <style>
        .ie-table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        .ie-table th { text-align: left; font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; background: #f3f4f6; padding: 10px 12px; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .ie-table td { padding: 10px 12px; border-bottom: 1px solid #eef2f7; font-size: 13px; color: #374151; }
        .ie-table-wrap { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; }
        .ie-empty { text-align: center; color: #6b7280; padding: 12px; }
        .ie-num { text-align: center; font-variant-numeric: tabular-nums; }
    </style>

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Overview</x-slot>
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Event</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ data_get($event, 'event_name', '-') }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">External ID</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $record->external_id ?: '-' }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Received</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $record->received_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">SAP Status</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $record->sap_status ?: '-' }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">SAP DocNum</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $record->sap_doc_num ?: '-' }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">SAP DocEntry</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $record->sap_doc_entry ?: '-' }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Signature</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $record->signature_valid ? 'Valid' : 'Invalid / Missing' }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">GRN ID</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ data_get($data, 'grn_details.grn_id', data_get($data, 'grn_id', '-')) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">PO Reference</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ data_get($data, 'entity_id', data_get($data, 'display_id', '-')) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Destination Hub</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ data_get($data, 'grn_details.destination_hub_code', data_get($data, 'destination_hub_code', '-')) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Entity Type</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ data_get($data, 'entity_type', '-') }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">GRN Items</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ count($grnItems) }}</div>
                </div>
            </div>
        </x-filament::section>

        @if ($record->sap_error)
            <x-filament::section>
                <x-slot name="heading">SAP Error</x-slot>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ $record->sap_error }}</div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">GRN Items</x-slot>
            <div class="ie-table-wrap">
                <table class="ie-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th class="ie-num">QC Passed</th>
                            <th class="ie-num">QC Failed</th>
                            <th class="ie-num">Received</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($grnItems as $item)
                            <tr>
                                <td>{{ data_get($item, 'code', data_get($item, 'sku_code', '-')) }}</td>
                                <td class="ie-num">{{ data_get($item, 'qc_passed_items', '-') }}</td>
                                <td class="ie-num">{{ data_get($item, 'qc_failed_items', '-') }}</td>
                                <td class="ie-num">{{ data_get($item, 'received_quantity', data_get($item, 'quantity', '-')) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="ie-empty" colspan="4">No GRN items</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Payload</x-slot>
            <pre class="overflow-auto rounded-xl bg-slate-900 p-4 text-xs text-slate-100">{{ $payloadJson }}</pre>
        </x-filament::section>
    </div>
</x-filament::page>
