<x-filament::page>
    <style>
        .ro-grid { display: grid; gap: 24px; }
        @media (min-width: 768px) { .ro-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1024px) {
            .ro-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .ro-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        .ro-card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; background: #ffffff; }
        .ro-card--active { border-color: #0f766e; box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.12); background: #f0fdfa; }
        .ro-kv { border: 1px solid #f1f5f9; background: #f8fafc; border-radius: 10px; padding: 12px; }
        .ro-label { font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; font-weight: 600; }
        .ro-value { margin-top: 4px; font-size: 14px; font-weight: 600; color: #111827; word-break: break-word; }
        .ro-break { word-break: break-word; overflow-wrap: anywhere; }
        .ro-badge { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 0.25rem 0.625rem; font-size: 12px; font-weight: 700; text-transform: capitalize; }
        .ro-badge--success { background: #eaf8ef; color: #166534; }
        .ro-badge--warning { background: #fff7db; color: #9a6700; }
        .ro-badge--danger { background: #fdecec; color: #b42318; }
        .ro-badge--gray { background: #eef2f6; color: #475569; }
        .ro-table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        .ro-table th { text-align: left; font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; background: #f3f4f6; padding: 10px 12px; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .ro-table td { padding: 10px 12px; border-bottom: 1px solid #eef2f7; font-size: 13px; color: #374151; }
        .ro-table tbody tr:hover { background: #f9fafb; }
        .ro-num { text-align: center; font-variant-numeric: tabular-nums; }
        .ro-empty { text-align: center; color: #6b7280; padding: 12px; }
        .ro-table-wrap { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; }
        .ro-section-pad { padding: 24px 16px; margin-top: 6px; margin-bottom: 6px; }
        .ro-section-gap { margin-bottom: 22px; }
        .ro-debug { white-space: pre-wrap; font-size: 12px; background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: 16px; overflow-x: auto; }
        .ro-progress { border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; background: #ffffff; }
        .ro-progress__bar { width: 100%; height: 10px; background: #e5e7eb; border-radius: 999px; overflow: hidden; margin-top: 10px; }
        .ro-progress__fill { height: 100%; background: linear-gradient(90deg, #0f766e, #14b8a6); border-radius: 999px; }
        .ro-progress__meta { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .ro-accordion { border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; overflow: hidden; }
        .ro-accordion + .ro-accordion { margin-top: 14px; }
        .ro-accordion summary { list-style: none; cursor: pointer; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13px; font-weight: 700; color: #0f172a; background: #f8fafc; }
        .ro-accordion summary::-webkit-details-marker { display: none; }
        .ro-accordion summary::after { content: '+'; font-size: 18px; line-height: 1; color: #64748b; }
        .ro-accordion[open] summary::after { content: '-'; }
        .ro-accordion__body { padding: 16px; border-top: 1px solid #e5e7eb; }
        .ro-main-accordion { border: 1px solid #e5e7eb; border-radius: 14px; background: #ffffff; overflow: hidden; }
        .ro-main-accordion + .ro-main-accordion { margin-top: 22px; }
        .ro-main-accordion summary { list-style: none; cursor: pointer; padding: 18px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 14px; font-weight: 800; color: #111827; background: #ffffff; }
        .ro-main-accordion summary::-webkit-details-marker { display: none; }
        .ro-main-accordion summary::after { content: '+'; font-size: 20px; line-height: 1; color: #64748b; }
        .ro-main-accordion[open] summary::after { content: '-'; }
        .ro-main-accordion__body { border-top: 1px solid #e5e7eb; background: #ffffff; }
    </style>

    <div class="space-y-6">
        <details class="ro-main-accordion" open>
            <summary>Overview</summary>
            <div class="ro-main-accordion__body">
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
                        <div class="ro-label">Order Ref</div>
                        <div class="ro-value">{{ data_get($data, 'order_reference_id', data_get($data, 'order_id', '-')) }}</div>
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
                        <div class="ro-label">Hub Code</div>
                        <div class="ro-value">{{ data_get($data, 'hub_code', '-') }}</div>
                    </div>
                    <div class="ro-card">
                        <div class="ro-label">Channel</div>
                        <div class="ro-value">{{ data_get($data, 'sales_channel.name', data_get($data, 'source', '-')) ?: '-' }}</div>
                    </div>
                    <div class="ro-card">
                        <div class="ro-label">Total Amount</div>
                        <div class="ro-value">{{ is_numeric(data_get($data, 'invoice.total', data_get($data, 'total'))) ? number_format((float) data_get($data, 'invoice.total', data_get($data, 'total')), 2) : data_get($data, 'invoice.total', data_get($data, 'total', '-')) }} {{ data_get($data, 'invoice.currency', '') }}</div>
                    </div>
                    <div class="ro-card">
                        <div class="ro-label">Received At</div>
                        <div class="ro-value">{{ optional($record->received_at)->format('M d, Y H:i:s') ?: '-' }}</div>
                    </div>
                    <div class="ro-card">
                        <div class="ro-label">SAP Status</div>
                        <div class="ro-value"><span class="ro-badge ro-badge--{{ $flowSummary['overall_tone'] ?? 'gray' }}">{{ $record->sap_status ?: '-' }}</span></div>
                    </div>
                    <div class="ro-card">
                        <div class="ro-label">SAP DocNum</div>
                        <div class="ro-value">{{ $record->sap_doc_num ?: '-' }}</div>
                    </div>
                    <div class="ro-card">
                        <div class="ro-label">SAP DocEntry</div>
                        <div class="ro-value">{{ $record->sap_doc_entry ?: '-' }}</div>
                    </div>
                </div>
            </div>
        </details>

        <details class="ro-main-accordion" open>
            <summary>Process Steps</summary>
            <div class="ro-main-accordion__body">
                <div class="space-y-4 ro-section-pad">
                    <div class="ro-progress">
                        <div class="ro-progress__meta">
                            <div>
                                <div class="ro-label">Flow Status</div>
                                <span class="ro-badge ro-badge--{{ $flowSummary['overall_tone'] ?? 'gray' }}">{{ $flowSummary['overall_label'] ?? '-' }}</span>
                            </div>
                            <div>
                                <div class="ro-label">Current Step</div>
                                <div class="ro-value">{{ $flowSummary['current_title'] ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="ro-label">Progress</div>
                                <div class="ro-value">{{ $flowSummary['completed_count'] ?? 0 }}/{{ $flowSummary['relevant_count'] ?? 0 }}</div>
                            </div>
                        </div>
                        <div class="ro-progress__bar">
                            <div class="ro-progress__fill" style="width: {{ $flowSummary['progress_percent'] ?? 0 }}%;"></div>
                        </div>
                    </div>

                    <div class="ro-grid ro-grid-2">
                        @foreach ($flowSteps as $step)
                            <div class="ro-card {{ ($flowSummary['current_key'] ?? null) === $step['key'] ? 'ro-card--active' : '' }}">
                                <div class="ro-label">{{ $step['title'] }}</div>
                                <div class="ro-value"><span class="ro-badge ro-badge--{{ $step['tone'] }}">{{ str_replace('_', ' ', $step['status']) }}</span></div>
                                <div class="ro-label" style="margin-top: 12px;">SAP Reference</div>
                                <div class="ro-value ro-break">{{ $step['reference'] }}</div>
                                <div class="ro-label" style="margin-top: 12px;">Error</div>
                                <div class="ro-value ro-break">{{ $step['error'] ?? '-' }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </details>

        <details class="ro-main-accordion" open>
            <summary>Return Details</summary>
            <div class="ro-main-accordion__body">
                <div class="ro-grid ro-grid-2 ro-section-pad">
                    <div class="ro-card">
                        <div class="ro-label" style="margin-bottom: 12px;">Customer</div>
                        <div class="ro-grid ro-grid-2">
                            <div class="ro-kv"><div class="ro-label">Name</div><div class="ro-value ro-break">{{ data_get($data, 'customer.first_name', '-') }} {{ data_get($data, 'customer.last_name', '') }}</div></div>
                            <div class="ro-kv"><div class="ro-label">Mobile</div><div class="ro-value ro-break">{{ data_get($data, 'customer.mobile', '-') }}</div></div>
                            <div class="ro-kv"><div class="ro-label">Email</div><div class="ro-value ro-break">{{ data_get($data, 'customer.email', '-') }}</div></div>
                            <div class="ro-kv"><div class="ro-label">Seller</div><div class="ro-value ro-break">{{ data_get($data, 'seller.name', data_get($data, 'seller_name', '-')) }}</div></div>
                        </div>
                    </div>

                    <div class="ro-card">
                        <div class="ro-label" style="margin-bottom: 12px;">Pickup Address</div>
                        <div class="ro-grid ro-grid-2">
                            <div class="ro-kv"><div class="ro-label">City</div><div class="ro-value ro-break">{{ data_get($data, 'pickup_address.city', '-') }}</div></div>
                            <div class="ro-kv"><div class="ro-label">Country</div><div class="ro-value ro-break">{{ data_get($data, 'pickup_address.country', '-') }}</div></div>
                            <div class="ro-kv"><div class="ro-label">Address</div><div class="ro-value ro-break">{{ data_get($data, 'pickup_address.address1', '-') }}</div></div>
                            <div class="ro-kv"><div class="ro-label">National Address</div><div class="ro-value ro-break">{{ data_get($data, 'pickup_address.national_address_code', '-') }}</div></div>
                        </div>
                    </div>
                </div>
            </div>
        </details>

        <details class="ro-main-accordion" open>
            <summary>Invoice & Items</summary>
            <div class="ro-main-accordion__body">
                <div class="ro-grid ro-grid-4 ro-section-pad">
                    @foreach ([
                        'Subtotal' => data_get($data, 'invoice.subtotal'),
                        'Tax' => data_get($data, 'invoice.tax'),
                        'Discount' => data_get($data, 'invoice.discount'),
                        'Total' => data_get($data, 'invoice.total', data_get($data, 'total')),
                    ] as $label => $value)
                        <div class="ro-kv">
                            <div class="ro-label">{{ $label }}</div>
                            <div class="ro-value">{{ is_numeric($value) ? number_format((float) $value, 2) : ($value ?? '-') }} {{ data_get($data, 'invoice.currency', '') }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="ro-table-wrap ro-section-pad">
                    <table class="ro-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Name</th>
                                <th class="ro-num">Return Qty</th>
                                <th class="ro-num">Delivered</th>
                                <th class="ro-num">Unit Price</th>
                                <th class="ro-num">Tax %</th>
                                <th class="ro-num">Tax</th>
                                <th class="ro-num">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($items as $item)
                                <tr>
                                    <td>{{ data_get($item, 'seller_sku.seller_sku_code', data_get($item, 'seller_sku_code', '-')) }}</td>
                                    <td class="ro-break">{{ data_get($item, 'seller_sku.name', data_get($item, 'name', '-')) }}</td>
                                    <td class="ro-num">{{ data_get($item, 'return_quantity', '-') }}</td>
                                    <td class="ro-num">{{ data_get($item, 'delivered_quantity', '-') }}</td>
                                    <td class="ro-num">{{ is_numeric(data_get($item, 'unit_price')) ? number_format((float) data_get($item, 'unit_price'), 2) : data_get($item, 'unit_price', '-') }}</td>
                                    <td class="ro-num">{{ data_get($item, 'tax_percent', '-') }}</td>
                                    <td class="ro-num">{{ is_numeric(data_get($item, 'tax')) ? number_format((float) data_get($item, 'tax'), 2) : data_get($item, 'tax', '-') }}</td>
                                    <td class="ro-num">{{ is_numeric(data_get($item, 'total')) ? number_format((float) data_get($item, 'total'), 2) : data_get($item, 'total', '-') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="ro-empty" colspan="8">No items</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <details class="ro-main-accordion">
            <summary>Omniful Payload</summary>
            <div class="ro-main-accordion__body">
                <div class="ro-section-pad">
                    <div class="ro-card">
                        <div class="ro-label">Latest Stored Omniful Payload</div>
                        <div class="ro-value" style="margin-bottom: 12px;"><span class="ro-badge ro-badge--gray">{{ data_get($event, 'event_name', 'event') }}</span></div>
                        @php
                            $payloadRoot = $event;
                            $payloadData = data_get($event, 'data');
                            $payloadItems = data_get($event, 'data.order_items', data_get($event, 'data.return_items', []));
                        @endphp

                        <details class="ro-accordion">
                            <summary>Root Payload</summary>
                            <div class="ro-accordion__body">
                                <pre class="ro-debug">{{ json_encode($payloadRoot ?: ['message' => 'No Omniful payload stored yet'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        </details>

                        <details class="ro-accordion">
                            <summary>Data Section</summary>
                            <div class="ro-accordion__body">
                                <pre class="ro-debug">{{ json_encode($payloadData ?: ['message' => 'No data section found'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        </details>

                        <details class="ro-accordion">
                            <summary>Items Section</summary>
                            <div class="ro-accordion__body">
                                <pre class="ro-debug">{{ json_encode($payloadItems ?: ['message' => 'No items found'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </details>

        <details class="ro-main-accordion">
            <summary>Step Payload Debug</summary>
            <div class="ro-main-accordion__body">
                <div class="space-y-6 ro-section-pad">
                    @foreach ($debugPayloads as $debugPayload)
                        <div class="ro-card">
                            <div class="ro-label">{{ $debugPayload['title'] }}</div>
                            <div class="ro-value" style="margin-bottom: 12px;">
                                @php($matchedStep = collect($flowSteps)->firstWhere('key', $debugPayload['key']))
                                @if ($matchedStep)
                                    <span class="ro-badge ro-badge--{{ $matchedStep['tone'] }}">{{ str_replace('_', ' ', $matchedStep['status']) }}</span>
                                @endif
                            </div>
                            <pre class="ro-debug">{{ json_encode($debugPayload['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    @endforeach
                </div>
            </div>
        </details>

        <details class="ro-main-accordion">
            <summary>SAP Responses</summary>
            <div class="ro-main-accordion__body">
                <div class="space-y-6 ro-section-pad">
                    @foreach ($sapResponses as $sapResponse)
                        <div class="ro-card">
                            <div class="ro-label">{{ $sapResponse['title'] }}</div>
                            <div class="ro-grid ro-grid-3" style="margin: 12px 0;">
                                @foreach (($sapResponse['summary'] ?? []) as $label => $value)
                                    <div class="ro-kv">
                                        <div class="ro-label">{{ str_replace('_', ' ', $label) }}</div>
                                        <div class="ro-value ro-break">{{ $value !== null && $value !== '' ? $value : '-' }}</div>
                                    </div>
                                @endforeach
                            </div>
                            <pre class="ro-debug">{{ json_encode($sapResponse['payload'] ?? ['message' => 'No SAP response stored yet'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    @endforeach
                </div>
            </div>
        </details>
    </div>
</x-filament::page>
