<x-filament::page>
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
            </div>
        </x-filament::section>

        @if ($record->sap_error)
            <x-filament::section>
                <x-slot name="heading">SAP Error</x-slot>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ $record->sap_error }}</div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Payload</x-slot>
            <pre class="overflow-auto rounded-xl bg-slate-900 p-4 text-xs text-slate-100">{{ $payloadJson }}</pre>
        </x-filament::section>
    </div>
</x-filament::page>
