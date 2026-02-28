<x-filament::page>
    <style>
        .po-grid { display: grid; gap: 24px; }
        @media (min-width: 768px) { .po-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .po-card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; background: #ffffff; }
        .po-label { font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; font-weight: 600; }
        .po-value { margin-top: 4px; font-size: 14px; font-weight: 600; color: #111827; word-break: break-word; }
        .po-section-pad { padding: 20px 16px; }
        .po-json { white-space: pre-wrap; font-size: 12px; background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: 16px; overflow-x: auto; }
    </style>

    @php
        $fmt = function ($value) {
            if (is_array($value)) {
                return count($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : '-';
            }
            if ($value === null || $value === '') {
                return '-';
            }
            return (string) $value;
        };
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Overview</x-slot>
            <div class="po-grid po-grid-3 po-section-pad">
                <div class="po-card">
                    <div class="po-label">Event</div>
                    <div class="po-value">{{ data_get($event, 'event_name', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP Status</div>
                    <div class="po-value">{{ $record->sap_status ?? '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP Item</div>
                    <div class="po-value">{{ $record->sap_item_code ?? '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Signature</div>
                    <div class="po-value">{{ $record->signature_valid ? 'Valid' : 'Invalid / Missing' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SKU</div>
                    <div class="po-value">{{ data_get($data, 'seller_sku_code', data_get($data, 'sku_code', '-')) }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Name</div>
                    <div class="po-value">{{ data_get($data, 'name', data_get($data, 'product.name', '-')) }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Type</div>
                    <div class="po-value">{{ data_get($data, 'type', data_get($data, 'product.type', '-')) }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Status</div>
                    <div class="po-value">{{ data_get($data, 'status', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Seller</div>
                    <div class="po-value">{{ data_get($data, 'seller_code', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Created At</div>
                    <div class="po-value">{{ data_get($data, 'created_at', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Updated At</div>
                    <div class="po-value">{{ data_get($data, 'updated_at', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Received</div>
                    <div class="po-value">{{ $record->received_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Payload Rows</div>
                    <div class="po-value">{{ count($rows) }}</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Details</x-slot>
            <div class="po-grid po-grid-3 po-section-pad">
                <div class="po-card">
                    <div class="po-label">Product Name</div>
                    <div class="po-value">{{ data_get($data, 'product.name', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Product Type</div>
                    <div class="po-value">{{ data_get($data, 'product.type', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Seller SKU Code</div>
                    <div class="po-value">{{ data_get($data, 'seller_sku_code', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Created By</div>
                    <div class="po-value">{{ data_get($data, 'created_by', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Updated By</div>
                    <div class="po-value">{{ data_get($data, 'updated_by', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Number Of Pieces</div>
                    <div class="po-value">{{ data_get($data, 'number_of_pieces', '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Weight</div>
                    <div class="po-value">{{ $fmt(data_get($data, 'weight.value')) }} {{ $fmt(data_get($data, 'weight.uom')) }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Tax Inclusive (Selling Price)</div>
                    <div class="po-value">{{ $fmt(data_get($data, 'tax_inclusive.selling_price')) }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Tax Percentage (Selling Price)</div>
                    <div class="po-value">{{ $fmt(data_get($data, 'tax_percentage.selling_price')) }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Discount</div>
                    <div class="po-value">{{ $fmt(data_get($data, 'discount')) }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Dimensions (L x B x H)</div>
                    <div class="po-value">
                        {{ $fmt(data_get($data, 'dimensions.length')) }} x
                        {{ $fmt(data_get($data, 'dimensions.breadth')) }} x
                        {{ $fmt(data_get($data, 'dimensions.height')) }}
                    </div>
                </div>
            </div>
        </x-filament::section>

        @if ($record->sap_error)
            <x-filament::section>
                <x-slot name="heading">SAP Error</x-slot>
                <div class="po-json">{{ $record->sap_error }}</div>
            </x-filament::section>
        @endif

        @if (count($rows) > 1)
            <x-filament::section>
                <x-slot name="heading">Payload Rows</x-slot>
                <div class="po-grid po-grid-3 po-section-pad">
                    @foreach ($rows as $row)
                        <div class="po-card">
                            <div class="po-label">SKU</div>
                            <div class="po-value">{{ data_get($row, 'seller_sku_code', data_get($row, 'sku_code', '-')) }}</div>
                            <div class="po-label" style="margin-top: 10px;">Name</div>
                            <div class="po-value">{{ data_get($row, 'name', data_get($row, 'product.name', '-')) }}</div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Payload</x-slot>
            <div class="po-json">{{ $payloadJson }}</div>
        </x-filament::section>
    </div>
</x-filament::page>
