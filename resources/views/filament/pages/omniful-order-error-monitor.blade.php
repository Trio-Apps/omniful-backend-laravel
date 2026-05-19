<x-filament::page>
    <style>
        .oem-shell {
            display: grid;
            gap: 20px;
        }

        .oem-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .oem-stat {
            position: relative;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #ffffff;
            padding: 18px 20px;
            min-height: 118px;
            overflow: hidden;
        }

        .oem-stat::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: #cbd5e1;
        }

        .oem-stat-danger::before { background: linear-gradient(180deg, #ef4444, #b91c1c); }
        .oem-stat-warning::before { background: linear-gradient(180deg, #f59e0b, #b45309); }
        .oem-stat-info::before { background: linear-gradient(180deg, #6366f1, #4338ca); }
        .oem-stat-success::before { background: linear-gradient(180deg, #10b981, #047857); }

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
            font-weight: 800;
            color: #0f172a;
        }

        .oem-stat-danger .oem-stat-value { color: #b91c1c; }
        .oem-stat-warning .oem-stat-value { color: #92400e; }
        .oem-stat-info .oem-stat-value { color: #3730a3; }
        .oem-stat-success .oem-stat-value { color: #047857; }

        .oem-stat-subtext {
            margin-top: 10px;
            font-size: 13px;
            line-height: 20px;
            color: #475569;
        }

        .oem-panel {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: #ffffff;
            overflow: hidden;
        }

        .oem-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            background: #fcfcfd;
        }

        .oem-panel-title {
            font-size: 18px;
            line-height: 24px;
            font-weight: 700;
            color: #0f172a;
        }

        .oem-panel-note {
            font-size: 13px;
            color: #64748b;
        }

        .oem-table-wrap {
            overflow-x: auto;
        }

        .oem-table {
            width: 100%;
            border-collapse: collapse;
        }

        .oem-table th {
            text-align: left;
            vertical-align: top;
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
            vertical-align: top;
            padding: 14px;
            border-bottom: 1px solid #eef2f7;
            font-size: 13px;
            line-height: 20px;
            color: #334155;
        }

        .oem-table tr:last-child td {
            border-bottom: none;
        }

        .oem-table tbody tr {
            transition: background-color 0.15s ease;
        }

        .oem-table tbody tr:hover {
            background: #f8fafc;
        }

        .oem-stage-list,
        .oem-chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .oem-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            line-height: 16px;
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

        .oem-message {
            font-size: 16px;
            line-height: 24px;
            font-weight: 700;
            color: #0f172a;
            word-break: break-word;
        }

        .oem-count {
            font-size: 22px;
            line-height: 28px;
            font-weight: 800;
            color: #0f172a;
        }

        .oem-latest {
            margin-top: 4px;
            font-size: 12px;
            color: #64748b;
        }

        .oem-orders-toggle {
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: #0f766e;
            user-select: none;
        }

        .oem-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 40px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            text-decoration: none;
            background: #ffffff;
        }

        .oem-orders-box {
            margin-top: 10px;
            display: grid;
            gap: 8px;
        }

        .oem-order-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 10px 12px;
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
            margin-top: 2px;
            font-size: 12px;
            color: #6b7280;
        }

        .oem-order-time {
            font-size: 12px;
            color: #64748b;
            white-space: nowrap;
        }

        .oem-empty {
            padding: 18px 20px;
            font-size: 14px;
            color: #64748b;
        }

        @media (max-width: 1280px) {
            .oem-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .oem-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="oem-shell">
        <div class="oem-stats">
            @php
                $tones = ['oem-stat-info', 'oem-stat-danger', 'oem-stat-warning', 'oem-stat-info'];
            @endphp
            @foreach ($summaryCards as $index => $card)
                @php
                    $rawValue = (int) str_replace([',', ' '], '', (string) ($card['value'] ?? ''));
                    $tone = $tones[$index] ?? '';
                    if ($rawValue === 0 && in_array($card['label'] ?? '', ['Unique Error Cases', 'Affected Orders'], true)) {
                        $tone = 'oem-stat-success';
                    }
                @endphp
                <div class="oem-stat {{ $tone }}">
                    <div class="oem-stat-label">{{ $card['label'] }}</div>
                    <div class="oem-stat-value">{{ $card['value'] }}</div>
                    @if (!empty($card['subtext']))
                        <div class="oem-stat-subtext">{{ $card['subtext'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        <section class="oem-panel">
            <div class="oem-panel-header">
                <div class="oem-panel-title">Repeated Error Cases</div>
                <div class="oem-panel-note">Grouped by normalized SAP message</div>
            </div>

            @if ($errorCases === [])
                <div class="oem-empty">
                    <div style="font-size: 18px; font-weight: 600; color: #047857;">All clear — no active order errors</div>
                    <div style="margin-top: 6px; font-size: 13px; color: #64748b;">
                        Resolved errors are filtered out automatically. Use "Clear Resolved Errors" above to wipe stale messages on orders that already completed.
                    </div>
                </div>
            @else
                <div class="oem-table-wrap">
                    <table class="oem-table">
                        <thead>
                            <tr>
                                <th style="width: 190px;">Stages</th>
                                <th>Error Message</th>
                                <th style="width: 260px;">Top SKUs</th>
                                <th style="width: 150px;">Affected Orders</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($errorCases as $case)
                                <tr>
                                    <td>
                                        <div class="oem-stage-list">
                                            @foreach ($case['stages'] as $stage)
                                                <span class="oem-chip oem-chip-stage">{{ $stage }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        <div class="oem-message">{{ $case['message'] }}</div>
                                    </td>
                                    <td>
                                        @if ($case['top_items'] === [])
                                            <span class="oem-latest">No repeated SKUs</span>
                                        @else
                                            <div class="oem-chip-list">
                                                @foreach ($case['top_items'] as $item)
                                                    <span class="oem-chip oem-chip-sku">
                                                        {{ $item['sku'] }}
                                                        <span class="oem-chip-muted">{{ $item['count'] }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="oem-count">{{ number_format($case['count']) }}</div>
                                        <div class="oem-latest">Latest: {{ $case['latest_at'] }}</div>
                                    </td>
                                    <td>
                                        <a
                                            href="{{ \App\Filament\Pages\OmnifulOrderErrorCaseView::getUrl(['fingerprint' => $case['fingerprint']]) }}"
                                            class="oem-action"
                                        >
                                            Open Case
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="oem-panel">
            <div class="oem-panel-header">
                <div class="oem-panel-title">Frequent Error Items</div>
                <div class="oem-panel-note">Top SKUs appearing on errored orders</div>
            </div>

            @if ($topErrorItems === [])
                <div class="oem-empty">No repeated item patterns on errored orders.</div>
            @else
                <div class="oem-table-wrap">
                    <table class="oem-table">
                        <thead>
                            <tr>
                                <th style="width: 180px;">SKU</th>
                                <th style="width: 160px;">Affected Orders</th>
                                <th>Top Error Cases</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topErrorItems as $item)
                                <tr>
                                    <td>
                                        <div class="oem-order-id">{{ $item['sku'] }}</div>
                                    </td>
                                    <td>
                                        <div class="oem-count">{{ number_format($item['count']) }}</div>
                                    </td>
                                    <td>
                                        <div style="display:grid;gap:8px;">
                                            @foreach ($item['top_cases'] as $case)
                                                <div style="display:grid;grid-template-columns:minmax(0,1fr) 44px;gap:12px;align-items:start;">
                                                    <div>{{ $errorCaseLabels[$case['fingerprint']] ?? $case['fingerprint'] }}</div>
                                                    <div style="text-align:right;color:#64748b;">{{ $case['count'] }}</div>
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
        </section>
    </div>
</x-filament::page>
