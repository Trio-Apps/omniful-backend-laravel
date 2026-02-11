<x-filament::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Order Overview</x-slot>
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <div class="text-xs text-gray-500">Order ID</div>
                    <div class="font-semibold">{{ $record->external_id ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Omniful Status</div>
                    <div class="font-semibold">{{ $record->omniful_status ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Last Event</div>
                    <div class="font-semibold">{{ $record->last_event_type ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">SAP Status</div>
                    <div class="font-semibold">{{ $record->sap_status ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">SAP Order DocNum</div>
                    <div class="font-semibold">{{ $record->sap_doc_num ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Last Event At</div>
                    <div class="font-semibold">{{ optional($record->last_event_at)->toDateTimeString() ?: '-' }}</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SAP Flow</x-slot>
            <div class="grid gap-4 md:grid-cols-4">
                <div>
                    <div class="text-xs text-gray-500">AR Reserve</div>
                    <div class="font-semibold">{{ $record->sap_doc_num ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Incoming Payment</div>
                    <div class="font-semibold">{{ $record->sap_payment_doc_num ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Delivery</div>
                    <div class="font-semibold">{{ $record->sap_delivery_doc_num ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">COGS JE</div>
                    <div class="font-semibold">{{ $record->sap_cogs_journal_num ?: '-' }}</div>
                </div>
            </div>
            @if ($record->sap_error)
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    {{ $record->sap_error }}
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Items</x-slot>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="px-3 py-2 text-left">SKU</th>
                            <th class="px-3 py-2 text-left">Qty</th>
                            <th class="px-3 py-2 text-left">Unit Price</th>
                            <th class="px-3 py-2 text-left">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr class="border-b">
                                <td class="px-3 py-2">{{ data_get($item, 'sku_code', '-') }}</td>
                                <td class="px-3 py-2">{{ data_get($item, 'quantity', '-') }}</td>
                                <td class="px-3 py-2">{{ data_get($item, 'unit_price', '-') }}</td>
                                <td class="px-3 py-2">{{ data_get($item, 'total', '-') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-3 py-4 text-center text-gray-500">No items</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Payload</x-slot>
            <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </x-filament::section>
    </div>
</x-filament::page>

