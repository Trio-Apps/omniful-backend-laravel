<x-filament::page>
    <style>
        .oec-shell { display:grid; gap:20px; }
        .oec-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
        .oec-stat, .oec-panel { border:1px solid #e5e7eb; border-radius:16px; background:#fff; }
        .oec-stat { padding:18px 20px; min-height:110px; }
        .oec-label { font-size:11px; line-height:16px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#64748b; }
        .oec-value { margin-top:10px; font-size:28px; line-height:1.05; font-weight:800; color:#0f172a; }
        .oec-subtext { margin-top:8px; font-size:13px; line-height:20px; color:#475569; }
        .oec-panel-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; padding:18px 20px; border-bottom:1px solid #e5e7eb; background:#fcfcfd; }
        .oec-title { font-size:20px; line-height:30px; font-weight:800; color:#0f172a; word-break:break-word; }
        .oec-note { margin-top:6px; font-size:13px; color:#64748b; }
        .oec-body { padding:18px 20px; }
        .oec-filters { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)) auto auto; gap:12px; align-items:end; }
        .oec-field label { display:block; margin-bottom:6px; font-size:11px; line-height:16px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#64748b; }
        .oec-field input, .oec-field select { width:100%; border:1px solid #d1d5db; border-radius:12px; padding:10px 12px; font-size:14px; color:#0f172a; background:#fff; }
        .oec-btn { display:inline-flex; align-items:center; justify-content:center; min-width:120px; border-radius:12px; padding:10px 14px; font-size:14px; font-weight:700; text-decoration:none; }
        .oec-btn-primary { background:#0f766e; color:#fff; }
        .oec-btn-muted { border:1px solid #d1d5db; color:#334155; background:#fff; }
        .oec-list { display:flex; flex-wrap:wrap; gap:8px; }
        .oec-chip { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:5px 10px; font-size:12px; line-height:16px; font-weight:600; }
        .oec-chip-stage { border:1px solid #dbe4ee; background:#f8fafc; color:#475569; }
        .oec-chip-sku { border:1px solid #dbeafe; background:#eff6ff; color:#1d4ed8; }
        .oec-table-wrap { overflow-x:auto; }
        .oec-table { width:100%; border-collapse:collapse; }
        .oec-table th { text-align:left; font-size:11px; line-height:16px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#64748b; background:#f8fafc; padding:12px 14px; border-bottom:1px solid #e5e7eb; }
        .oec-table td { padding:12px 14px; border-bottom:1px solid #eef2f7; font-size:13px; line-height:20px; color:#334155; vertical-align:top; }
        .oec-order-link { text-decoration:none; color:#0f172a; font-weight:700; }
        .oec-empty { font-size:14px; color:#64748b; }
        @media (max-width: 1280px) { .oec-stats, .oec-filters { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (max-width: 768px) { .oec-stats, .oec-filters { grid-template-columns:1fr; } }
    </style>

    <div class="oec-shell">
        <section class="oec-panel">
            <div class="oec-panel-head">
                <div>
                    <div class="oec-title">{{ $message }}</div>
                    <div class="oec-note">Use filters to narrow this case by stage, SKU, or date range.</div>
                </div>
                <a href="{{ \App\Filament\Pages\OmnifulOrderErrorMonitor::getUrl() }}" class="oec-btn oec-btn-muted">Back to all errors</a>
            </div>
            <div class="oec-body">
                <form method="GET" action="{{ request()->url() }}" class="oec-filters">
                    <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">
                    <div class="oec-field">
                        <label>Stage</label>
                        <select name="stage">
                            <option value="">All stages</option>
                            @foreach ($stageBreakdown as $row)
                                <option value="{{ $row['stage'] }}" @selected($stage === $row['stage'])>{{ $row['stage'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="oec-field">
                        <label>SKU</label>
                        <input type="text" name="sku" value="{{ $sku }}" placeholder="Filter by SKU">
                    </div>
                    <div class="oec-field">
                        <label>Date From</label>
                        <input type="date" name="date_from" value="{{ $dateFrom }}">
                    </div>
                    <div class="oec-field">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="{{ $dateTo }}">
                    </div>
                    <button type="submit" class="oec-btn oec-btn-primary">Apply Filters</button>
                    <a href="{{ request()->url() . '?fingerprint=' . urlencode($fingerprint) }}" class="oec-btn oec-btn-muted">Reset</a>
                </form>
            </div>
        </section>

        <div class="oec-stats">
            <div class="oec-stat">
                <div class="oec-label">Affected Orders</div>
                <div class="oec-value">{{ number_format($summary['orders'] ?? 0) }}</div>
            </div>
            <div class="oec-stat">
                <div class="oec-label">Unique SKUs</div>
                <div class="oec-value">{{ number_format($summary['unique_skus'] ?? 0) }}</div>
            </div>
            <div class="oec-stat">
                <div class="oec-label">Top Stage</div>
                <div class="oec-value" style="font-size:20px;">{{ $summary['top_stage'] ?? '-' }}</div>
            </div>
            <div class="oec-stat">
                <div class="oec-label">Latest Occurrence</div>
                <div class="oec-value" style="font-size:20px;">{{ $summary['latest_at'] ?? '-' }}</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:20px;">
            <section class="oec-panel">
                <div class="oec-panel-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                    <div class="oec-title" style="font-size:18px;">Affected Orders</div>
                    @if ($orders !== [])
                        <div style="display:flex;gap:8px;">
                            <button type="button" wire:click="resendCaseOrders(false)"
                                wire:confirm="Resend ALL {{ count($orders) }} order(s) in this case (no cancel)?"
                                style="padding:6px 12px;border-radius:8px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-size:13px;cursor:pointer;">
                                Resend all
                            </button>
                            <button type="button" wire:click="resendCaseOrders(true)"
                                wire:confirm="Resend ALL {{ count($orders) }} order(s) AND reverse their existing SAP invoices? This is destructive."
                                style="padding:6px 12px;border-radius:8px;border:1px solid #b91c1c;background:#fff;color:#b91c1c;font-size:13px;cursor:pointer;">
                                Resend all + Cancel
                            </button>
                        </div>
                    @endif
                </div>
                @if ($orders === [])
                    <div class="oec-body"><div class="oec-empty">No orders match the current filters.</div></div>
                @else
                    <div class="oec-table-wrap">
                        <table class="oec-table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Stages</th>
                                    <th>SKUs</th>
                                    <th>Statuses</th>
                                    <th>Last Event At</th>
                                    <th>Resend</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($orders as $order)
                                    <tr>
                                        <td><a href="{{ $order['url'] }}" class="oec-order-link">{{ $order['external_id'] }}</a></td>
                                        <td>
                                            <div class="oec-list">
                                                @foreach ($order['error_stages'] as $rowStage)
                                                    <span class="oec-chip oec-chip-stage">{{ $rowStage }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td>
                                            <div class="oec-list">
                                                @foreach (array_slice($order['skus'], 0, 6) as $rowSku)
                                                    <span class="oec-chip oec-chip-sku">{{ $rowSku }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td>Omniful: {{ $order['omniful_status'] ?: '-' }}<br>SAP: {{ $order['sap_status'] ?: '-' }}</td>
                                        <td>{{ $order['last_event_at'] ?: '-' }}</td>
                                        <td>
                                            <div style="display:flex;gap:6px;white-space:nowrap;">
                                                <button type="button"
                                                    wire:click="resendOrder('{{ $order['external_id'] }}', false)"
                                                    wire:confirm="Resend order {{ $order['external_id'] }} (no cancel)?"
                                                    style="padding:4px 10px;border-radius:6px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-size:12px;cursor:pointer;">
                                                    Resend
                                                </button>
                                                <button type="button"
                                                    wire:click="resendOrder('{{ $order['external_id'] }}', true)"
                                                    wire:confirm="Resend order {{ $order['external_id'] }} AND reverse its existing SAP invoice? This is destructive."
                                                    style="padding:4px 10px;border-radius:6px;border:1px solid #b91c1c;background:#fff;color:#b91c1c;font-size:12px;cursor:pointer;">
                                                    + Cancel
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <div style="display:grid;gap:20px;">
                <section class="oec-panel">
                    <div class="oec-panel-head">
                        <div class="oec-title" style="font-size:18px;">Top SKUs</div>
                    </div>
                    <div class="oec-body">
                        @if ($topItems === [])
                            <div class="oec-empty">No SKUs match the current filters.</div>
                        @else
                            <div class="oec-list">
                                @foreach ($topItems as $item)
                                    <span class="oec-chip oec-chip-sku">{{ $item['sku'] }} <span class="oec-chip-muted">{{ $item['count'] }}</span></span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

                <section class="oec-panel">
                    <div class="oec-panel-head">
                        <div class="oec-title" style="font-size:18px;">Stage Breakdown</div>
                    </div>
                    <div class="oec-table-wrap">
                        <table class="oec-table">
                            <thead><tr><th>Stage</th><th>Count</th></tr></thead>
                            <tbody>
                                @forelse ($stageBreakdown as $row)
                                    <tr><td>{{ $row['stage'] }}</td><td>{{ $row['count'] }}</td></tr>
                                @empty
                                    <tr><td colspan="2" class="oec-empty">No stage data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="oec-panel">
                    <div class="oec-panel-head">
                        <div class="oec-title" style="font-size:18px;">Daily Occurrences</div>
                    </div>
                    <div class="oec-table-wrap">
                        <table class="oec-table">
                            <thead><tr><th>Day</th><th>Count</th></tr></thead>
                            <tbody>
                                @forelse ($dailyBreakdown as $row)
                                    <tr><td>{{ $row['day'] }}</td><td>{{ $row['count'] }}</td></tr>
                                @empty
                                    <tr><td colspan="2" class="oec-empty">No date data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-filament::page>
