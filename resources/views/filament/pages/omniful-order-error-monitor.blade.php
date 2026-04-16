<x-filament::page>
    <style>
        .oem-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .oem-stat {
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #fff;
            padding: 18px 20px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }

        .oem-stat-label {
            font-size: 11px;
            line-height: 16px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #64748b;
        }

        .oem-stat-value {
            margin-top: 10px;
            font-size: 30px;
            line-height: 1.05;
            font-weight: 700;
            color: #0f172a;
        }

        .oem-stat-subtext {
            margin-top: 10px;
            font-size: 13px;
            line-height: 19px;
            color: #475569;
        }

        .oem-list {
            display: grid;
            gap: 16px;
        }

        .oem-case {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: #fff;
            padding: 18px 20px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
        }

        .oem-case-head {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 180px;
            gap: 20px;
            align-items: start;
        }

        .oem-case-stage-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .oem-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .oem-chip-stage {
            border: 1px solid #dbe4ee;
            background: #f8fafc;
            color: #475569;
        }

        .oem-chip-sku {
            border: 1px solid #dbeafe;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .oem-chip-muted {
            color: #64748b;
        }

        .oem-case-message {
            font-size: 20px;
            line-height: 1.45;
            font-weight: 700;
            color: #0f172a;
            word-break: break-word;
        }

        .oem-case-meta {
            text-align: right;
        }

        .oem-case-meta-label {
            font-size: 11px;
            line-height: 16px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #64748b;
        }

        .oem-case-meta-value {
            margin-top: 10px;
            font-size: 18px;
            line-height: 1.1;
            font-weight: 700;
            color: #0f172a;
        }

        .oem-case-meta-subtext {
            margin-top: 8px;
            font-size: 12px;
            color: #64748b;
        }

        .oem-case-body {
            margin-top: 16px;
            display: grid;
            gap: 14px;
        }

        .oem-orders-toggle {
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: #0f766e;
        }

        .oem-order-list {
            margin-top: 12px;
            display: grid;
            gap: 8px;
        }

        .oem-order-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fafafa;
            text-decoration: none;
        }

        .oem-order-id {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }

        .oem-order-meta {
            margin-top: 4px;
            font-size: 12px;
            color: #6b7280;
        }

        .oem-order-time {
            font-size: 12px;
            color: #6b7280;
            white-space: nowrap;
        }

        .oem-table-wrap {
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #fff;
        }

        .oem-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .oem-table th {
            text-align: left;
            font-size: 11px;
            line-height: 16px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #64748b;
            background: #f8fafc;
            padding: 12px 14px;
            border-bottom: 1px solid #e5e7eb;
        }

        .oem-table td {
            padding: 14px;
            border-bottom: 1px solid #eef2f7;
            font-size: 13px;
            color: #334155;
            vertical-align: top;
        }

        .oem-table tr:last-child td {
            border-bottom: none;
        }

        .oem-empty {
            font-size: 14px;
            color: #64748b;
        }

        @media (max-width: 1200px) {
            .oem-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .oem-grid {
                grid-template-columns: 1fr;
            }

            .oem-case-head {
                grid-template-columns: 1fr;
            }

            .oem-case-meta {
                text-align: left;
            }
        }
    </style>

    <div class="space-y-6">
        <div class="oem-grid">
            @foreach ($summaryCards as $card)
                <div class="oem-stat">
                    <div class="oem-stat-label">{{ $card['label'] }}</div>
                    <div class="oem-stat-value">{{ $card['value'] }}</div>
                    @if (!empty($card['subtext']))
                        <div class="oem-stat-subtext">{{ $card['subtext'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        <x-filament::section>
            <x-slot name="heading">Repeated Error Cases</x-slot>

            @if ($errorCases === [])
                <div class="oem-empty">No captured order errors.</div>
            @else
                <div class="oem-list">
                    @foreach ($errorCases as $case)
                        <div class="oem-case">
                            <div class="oem-case-head">
                                <div>
                                    <div class="oem-case-stage-list">
                                        @foreach ($case['stages'] as $stage)
                                            <span class="oem-chip oem-chip-stage">{{ $stage }}</span>
                                        @endforeach
                                    </div>

                                    <div class="oem-case-message">{{ $case['message'] }}</div>
                                </div>

                                <div class="oem-case-meta">
                                    <div class="oem-case-meta-label">Affected Orders</div>
                                    <div class="oem-case-meta-value">{{ number_format($case['count']) }}</div>
                                    <div class="oem-case-meta-subtext">Latest: {{ $case['latest_at'] }}</div>
                                </div>
                            </div>

                            <div class="oem-case-body">
                                @if ($case['top_items'] !== [])
                                    <div class="oem-case-stage-list">
                                        @foreach ($case['top_items'] as $item)
                                            <span class="oem-chip oem-chip-sku">
                                                {{ $item['sku'] }}
                                                <span class="oem-chip-muted">{{ $item['count'] }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif

                                <details>
                                    <summary class="oem-orders-toggle">View affected orders</summary>

                                    <div class="oem-order-list">
                                        @foreach ($case['orders'] as $order)
                                            <a href="{{ $order['url'] }}" class="oem-order-row">
                                                <div>
                                                    <div class="oem-order-id">{{ $order['external_id'] }}</div>
                                                    <div class="oem-order-meta">
                                                        Omniful: {{ $order['omniful_status'] ?: '-' }}
                                                        |
                                                        SAP: {{ $order['sap_status'] ?: '-' }}
                                                    </div>
                                                </div>
                                                <div class="oem-order-time">{{ $order['last_event_at'] ?: '-' }}</div>
                                            </a>
                                        @endforeach
                                    </div>
                                </details>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Frequent Error Items</x-slot>

            @if ($topErrorItems === [])
                <div class="oem-empty">No repeated item patterns on errored orders.</div>
            @else
                <div class="oem-table-wrap">
                    <table class="oem-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Affected Orders</th>
                                <th>Top Error Cases</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topErrorItems as $item)
                                <tr>
                                    <td style="font-weight: 700; color: #0f172a;">{{ $item['sku'] }}</td>
                                    <td>{{ number_format($item['count']) }}</td>
                                    <td>
                                        <div style="display:grid;gap:8px;">
                                            @foreach ($item['top_cases'] as $case)
                                                <div style="display:flex;justify-content:space-between;gap:12px;">
                                                    <div style="color:#334155;">{{ $errorCaseLabels[$case['fingerprint']] ?? $case['fingerprint'] }}</div>
                                                    <div style="color:#64748b;white-space:nowrap;">{{ $case['count'] }}</div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament::page>
