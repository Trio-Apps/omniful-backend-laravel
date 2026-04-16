<x-filament::page>
    <style>
        .po-grid { display: grid; gap: 24px; }
        @media (min-width: 768px) { .po-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .po-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .po-card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; background: #ffffff; }
        .po-card--active { border-color: #0f766e; box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.12); background: #f0fdfa; }
        .po-kv { border: 1px solid #f1f5f9; background: #f8fafc; border-radius: 10px; padding: 12px; }
        .po-label { font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; font-weight: 600; }
        .po-value { margin-top: 4px; font-size: 14px; font-weight: 600; color: #111827; word-break: break-word; }
        .po-break { word-break: break-word; overflow-wrap: anywhere; }
        .po-badge { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 0.25rem 0.625rem; font-size: 12px; font-weight: 700; text-transform: capitalize; }
        .po-badge--success { background: #eaf8ef; color: #166534; }
        .po-badge--warning { background: #fff7db; color: #9a6700; }
        .po-badge--danger { background: #fdecec; color: #b42318; }
        .po-badge--gray { background: #eef2f6; color: #475569; }
        .po-table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        .po-table th { text-align: left; font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; background: #f3f4f6; padding: 10px 12px; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .po-table td { padding: 10px 12px; border-bottom: 1px solid #eef2f7; font-size: 13px; color: #374151; }
        .po-table tbody tr:hover { background: #f9fafb; }
        .po-num { text-align: center; font-variant-numeric: tabular-nums; }
        .po-empty { text-align: center; color: #6b7280; padding: 12px; }
        .po-table-wrap { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; }
        .po-section-pad { padding: 24px 16px; margin-top: 6px; margin-bottom: 6px; }
        .po-section-gap { margin-bottom: 22px; }
        .po-debug { white-space: pre-wrap; font-size: 12px; background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: 16px; overflow-x: auto; }
        .po-progress { border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; background: #ffffff; }
        .po-progress__bar { width: 100%; height: 10px; background: #e5e7eb; border-radius: 999px; overflow: hidden; margin-top: 10px; }
        .po-progress__fill { height: 100%; background: linear-gradient(90deg, #0f766e, #14b8a6); border-radius: 999px; }
        .po-progress__meta { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .po-accordion { border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; overflow: hidden; }
        .po-accordion + .po-accordion { margin-top: 14px; }
        .po-accordion summary { list-style: none; cursor: pointer; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13px; font-weight: 700; color: #0f172a; background: #f8fafc; }
        .po-accordion summary::-webkit-details-marker { display: none; }
        .po-accordion summary::after { content: '+'; font-size: 18px; line-height: 1; color: #64748b; }
        .po-accordion[open] summary::after { content: '-'; }
        .po-accordion__body { padding: 16px; border-top: 1px solid #e5e7eb; }
        .po-main-accordion { border: 1px solid #e5e7eb; border-radius: 14px; background: #ffffff; overflow: hidden; }
        .po-main-accordion + .po-main-accordion { margin-top: 22px; }
        .po-main-accordion summary { list-style: none; cursor: pointer; padding: 18px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 14px; font-weight: 800; color: #111827; background: #ffffff; }
        .po-main-accordion summary::-webkit-details-marker { display: none; }
        .po-main-accordion summary::after { content: '+'; font-size: 20px; line-height: 1; color: #64748b; }
        .po-main-accordion[open] summary::after { content: '-'; }
        .po-main-accordion__body { border-top: 1px solid #e5e7eb; background: #ffffff; }
    </style>

    <div class="space-y-6">
        <details class="po-main-accordion" open>
            <summary>Overview</summary>
            <div class="po-main-accordion__body">
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
                    <div class="po-value">{{ $overviewState['sap_status'] ?? ($record->sap_status ?: '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP DocNum</div>
                    <div class="po-value">{{ $record->sap_doc_num ?: '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP DocEntry</div>
                    <div class="po-value">{{ $record->sap_doc_entry ?: '-' }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">SAP Credit Note</div>
                    <div class="po-value">{{ $record->sap_credit_note_doc_num ?: ($record->sap_credit_note_status ?: '-') }}</div>
                </div>
                <div class="po-card" id="sap-error">
                    <div class="po-label">SAP Error</div>
                    <div class="po-value po-break">{{ array_key_exists('sap_error', $overviewState) ? ($overviewState['sap_error'] ?: '-') : ($record->sap_error ?: '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Cancel COGS</div>
                    <div class="po-value">{{ $record->sap_cancel_cogs_journal_num ?: ($record->sap_cancel_cogs_status ?: '-') }}</div>
                </div>
                <div class="po-card">
                    <div class="po-label">Credit Note Error</div>
                    <div class="po-value po-break">{{ $record->sap_credit_note_error ?: ($record->sap_cancel_cogs_error ?: '-') }}</div>
                </div>
            </div>
            </div>
        </details>

        <details class="po-main-accordion" open>
            <summary>Contacts & Addresses</summary>
            <div class="po-main-accordion__body">
        <div class="po-grid po-grid-2 po-section-pad">
            <div class="po-card">
                <div class="po-label" style="margin-bottom: 12px;">Customer</div>
                <div class="po-grid po-grid-2 po-section-pad">
                    <div class="po-kv">
                        <div class="po-label">Name</div>
                        <div class="po-value po-break">{{ data_get($data, 'customer.first_name', '-') }} {{ data_get($data, 'customer.last_name', '') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Customer ID</div>
                        <div class="po-value po-break">{{ data_get($data, 'customer.id', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Email</div>
                        <div class="po-value po-break">{{ data_get($data, 'customer.email', '-') }}</div>
                    </div>
                    <div class="po-kv">
                        <div class="po-label">Payment Method</div>
                        <div class="po-value po-break">{{ data_get($data, 'payment_method', '-') }}</div>
                    </div>
                </div>
            </div>

            <div class="po-card">
                <div class="po-label" style="margin-bottom: 12px;">Seller</div>
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
            </div>

            <div class="po-card">
                <div class="po-label" style="margin-bottom: 12px;">Billing Address</div>
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
                        <div class="po-value po-break">{{ data_get($data, 'billing_address.country', '-') }}</div>
                    </div>
                </div>
            </div>

            <div class="po-card">
                <div class="po-label" style="margin-bottom: 12px;">Shipping Address</div>
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
                        <div class="po-value po-break">{{ data_get($data, 'shipping_address.country', '-') }}</div>
                    </div>
                </div>
            </div>
        </div>
            </div>
        </details>

        <details class="po-main-accordion" open>
            <summary>Items</summary>
            <div class="po-main-accordion__body">
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
            </div>
        </details>

        <details class="po-main-accordion" open>
            <summary>Process Steps</summary>
            <div class="po-main-accordion__body">
            <div class="po-section-pad space-y-6">
                <div class="po-progress">
                    <div class="po-progress__meta">
                        <div>
                            <div class="po-label">Flow Status</div>
                            <div class="po-value">
                                <span class="po-badge po-badge--{{ $flowSummary['overall_tone'] }}">{{ $flowSummary['overall_label'] }}</span>
                            </div>
                        </div>
                        <div>
                            <div class="po-label">Current Step</div>
                            <div class="po-value">{{ $flowSummary['current_title'] }}</div>
                        </div>
                        <div>
                            <div class="po-label">Progress</div>
                            <div class="po-value">{{ $flowSummary['completed_count'] }}/{{ $flowSummary['relevant_count'] }}</div>
                        </div>
                    </div>
                    <div class="po-progress__bar">
                        <div class="po-progress__fill" style="width: {{ $flowSummary['progress_percent'] }}%;"></div>
                    </div>
                </div>

                <div class="po-grid po-grid-3">
                @foreach ($visibleFlowSteps as $step)
                    <div class="po-card {{ ($flowSummary['current_key'] ?? null) === $step['key'] ? 'po-card--active' : '' }}">
                        <div class="po-label">{{ $step['title'] }}</div>
                        <div class="po-value">
                            <span class="po-badge po-badge--{{ $step['tone'] }}">{{ str_replace('_', ' ', $step['status']) }}</span>
                        </div>
                        <div class="po-label" style="margin-top: 12px;">SAP Reference</div>
                        <div class="po-value po-break">{{ $step['reference'] }}</div>
                        <div class="po-label" style="margin-top: 12px;">Error</div>
                        <div class="po-value po-break">{{ $step['error'] ?? '-' }}</div>
                    </div>
                @endforeach
                </div>
            </div>
            </div>
        </details>

        <details class="po-main-accordion">
            <summary>Omniful Payload</summary>
            <div class="po-main-accordion__body">
            <div class="po-section-pad">
                <div class="po-card">
                    <div class="po-label">Latest Stored Omniful Payload</div>
                    <div class="po-value" style="margin-bottom: 12px;">
                        <span class="po-badge po-badge--gray">{{ data_get($payload, 'event_name', $record->last_event_type ?: 'event') }}</span>
                    </div>
                    @php
                        $payloadRoot = $payload;
                        $payloadData = data_get($payload, 'data');
                        $payloadItems = data_get($payload, 'data.order_items', data_get($payload, 'data.items', []));
                    @endphp

                    <details class="po-accordion" open>
                        <summary>Root Payload</summary>
                        <div class="po-accordion__body">
                            <pre class="po-debug">{{ json_encode($payloadRoot ?: ['message' => 'No Omniful payload stored yet'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    </details>

                    <details class="po-accordion">
                        <summary>Data Section</summary>
                        <div class="po-accordion__body">
                            <pre class="po-debug">{{ json_encode($payloadData ?: ['message' => 'No data section found'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    </details>

                    <details class="po-accordion">
                        <summary>Items Section</summary>
                        <div class="po-accordion__body">
                            <pre class="po-debug">{{ json_encode($payloadItems ?: ['message' => 'No items found'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    </details>
                </div>
            </div>
            </div>
        </details>

        <details class="po-main-accordion">
            <summary>Step Payload Debug</summary>
            <div class="po-main-accordion__body">
            <div class="space-y-6 po-section-pad">
                @foreach ($visibleDebugPayloads as $debugPayload)
                    <div class="po-card">
                        <div class="po-label">{{ $debugPayload['title'] }}</div>
                        <div class="po-value" style="margin-bottom: 12px;">
                            @php($matchedStep = collect($visibleFlowSteps)->firstWhere('key', $debugPayload['key']))
                            @if ($matchedStep)
                                <span class="po-badge po-badge--{{ $matchedStep['tone'] }}">{{ str_replace('_', ' ', $matchedStep['status']) }}</span>
                            @endif
                        </div>
                        <pre class="po-debug">{{ json_encode($debugPayload['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                @endforeach
            </div>
            </div>
        </details>

        <details class="po-main-accordion">
            <summary>SAP Responses</summary>
            <div class="po-main-accordion__body">
            <div class="space-y-6 po-section-pad">
                @foreach ($visibleSapResponses as $sapResponse)
                    <div class="po-card">
                        <div class="po-label">{{ $sapResponse['title'] }}</div>
                        <div class="po-value" style="margin-bottom: 12px;">
                            @php($matchedStep = collect($visibleFlowSteps)->firstWhere('key', $sapResponse['key']))
                            @if ($matchedStep)
                                <span class="po-badge po-badge--{{ $matchedStep['tone'] }}">{{ str_replace('_', ' ', $matchedStep['status']) }}</span>
                            @endif
                        </div>
                        @if (!empty($sapResponse['summary']))
                            <div class="po-grid po-grid-3" style="margin-bottom: 12px;">
                                @foreach ($sapResponse['summary'] as $label => $value)
                                    <div class="po-kv">
                                        <div class="po-label">{{ str_replace('_', ' ', $label) }}</div>
                                        <div class="po-value po-break">{{ $value !== '' ? $value : '-' }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <pre class="po-debug">{{ json_encode($sapResponse['payload'] ?? ['message' => 'No SAP response stored yet'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                @endforeach
            </div>
            </div>
        </details>
    </div>
</x-filament::page>
