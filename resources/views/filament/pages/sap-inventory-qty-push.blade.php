<x-filament-panels::page>
    @php($panel = $this->getPanel())

    <style>
        .iqp-wrap { display:flex; flex-direction:column; gap:1rem; }
        .iqp-card { border:1px solid rgba(148,163,184,.25); border-radius:.75rem; padding:1rem 1.25rem; background:rgba(148,163,184,.04); }
        .iqp-head { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        .iqp-badge { font-size:.75rem; font-weight:700; padding:.2rem .6rem; border-radius:999px; text-transform:uppercase; letter-spacing:.03em; }
        .iqp-gray { background:rgba(148,163,184,.18); color:#475569; }
        .iqp-success { background:rgba(16,185,129,.16); color:#047857; }
        .iqp-warning { background:rgba(245,158,11,.18); color:#b45309; }
        .iqp-danger { background:rgba(239,68,68,.16); color:#b91c1c; }
        .iqp-key { font-family:ui-monospace,monospace; font-size:.75rem; color:#64748b; }
        .iqp-meta { margin-top:.5rem; font-size:.8rem; color:#64748b; display:flex; gap:1.25rem; flex-wrap:wrap; }
        .iqp-grid { margin-top:1rem; display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); gap:.6rem; }
        .iqp-stat { border:1px solid rgba(148,163,184,.2); border-radius:.6rem; padding:.6rem .75rem; text-align:center; background:#fff; }
        .iqp-stat-num { font-size:1.4rem; font-weight:700; color:#0f172a; }
        .iqp-stat-label { font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-top:.15rem; }
        .iqp-error { margin-top:.75rem; padding:.6rem .75rem; border-radius:.5rem; background:rgba(239,68,68,.1); color:#b91c1c; font-size:.8rem; white-space:pre-wrap; word-break:break-word; }
        .iqp-cfg { display:flex; gap:1.25rem; flex-wrap:wrap; font-size:.82rem; color:#475569; }
        .iqp-cfg b { color:#0f172a; }
        .iqp-hint { font-size:.78rem; color:#64748b; margin-top:.35rem; }
        .iqp-sec { font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:.5rem; }
        table.iqp-tbl { width:100%; border-collapse:collapse; font-size:.82rem; }
        table.iqp-tbl th, table.iqp-tbl td { padding:.4rem .6rem; text-align:right; border-bottom:1px solid rgba(148,163,184,.18); }
        table.iqp-tbl th:first-child, table.iqp-tbl td:first-child { text-align:left; font-family:ui-monospace,monospace; }
        table.iqp-tbl th:last-child, table.iqp-tbl td:last-child { text-align:left; }
        table.iqp-tbl th { font-size:.66rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
        table.iqp-tbl td { color:#334155; }
        .iqp-fail { color:#b91c1c; font-weight:700; }
        .iqp-ok { color:#047857; font-weight:700; }
        .iqp-scroll { max-height:340px; overflow:auto; border:1px solid rgba(148,163,184,.18); border-radius:.5rem; }
        .iqp-sample { font-family:ui-monospace,monospace; font-size:.72rem; color:#64748b; padding:.35rem .5rem; border-bottom:1px solid rgba(148,163,184,.14); word-break:break-word; }
        .dark .iqp-stat { background:rgba(255,255,255,.05); border-color:rgba(255,255,255,.1); }
        .dark .iqp-stat-num, .dark .iqp-cfg b { color:#f1f5f9; }
        .dark .iqp-gray { color:#cbd5e1; }
        .dark .iqp-cfg, .dark .iqp-meta, .dark .iqp-hint, .dark .iqp-key, .dark .iqp-sec, .dark table.iqp-tbl th { color:#94a3b8; }
        .dark table.iqp-tbl td { color:#cbd5e1; }
        .dark table.iqp-tbl th, .dark table.iqp-tbl td { border-color:rgba(255,255,255,.1); }
    </style>

    <div class="iqp-wrap" wire:poll.5s>
        {{-- Status --}}
        <div class="iqp-card">
            <div class="iqp-head">
                <span class="iqp-badge iqp-{{ $panel['tone'] }}">{{ $panel['status_label'] }}</span>
                @if ($panel['event_key'])<span class="iqp-key">{{ $panel['event_key'] }}</span>@endif
            </div>

            @if (! $panel['has_event'])
                <div class="iqp-hint">No inventory push has run yet. Use <b>Run Now (Delta)</b> above to push changed quantities, or <b>Run Full</b> to push everything.</div>
            @endif

            @if (! empty($panel['progress']))
                <div class="iqp-grid">
                    @foreach (['total' => 'To Push', 'pushed' => 'Pushed', 'failed' => 'Failed', 'skipped_unmapped' => 'Skipped (unmapped)', 'considered' => 'Considered', 'hubs' => 'Hubs'] as $key => $label)
                        @isset($panel['progress'][$key])
                            <div class="iqp-stat">
                                <div class="iqp-stat-num">{{ number_format((int) $panel['progress'][$key]) }}</div>
                                <div class="iqp-stat-label">{{ $label }}</div>
                            </div>
                        @endisset
                    @endforeach
                </div>
            @endif

            <div class="iqp-meta">
                @if ($panel['requested_at'])<span>Requested: {{ $panel['requested_at'] }}</span>@endif
                @if ($panel['updated_at'])<span>Updated: {{ $panel['updated_at'] }}</span>@endif
            </div>

            @if ($panel['error'])<div class="iqp-error">{{ $panel['error'] }}</div>@endif
        </div>

        {{-- Per-hub results --}}
        @if (! empty($panel['hub_results']))
            <div class="iqp-card">
                <div class="iqp-sec">Per-hub result ({{ count($panel['hub_results']) }} hubs — worst first)</div>
                <div class="iqp-scroll">
                    <table class="iqp-tbl">
                        <thead>
                            <tr><th>Hub</th><th>Pushed</th><th>Failed</th><th>Reason</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($panel['hub_results'] as $h)
                                <tr>
                                    <td>{{ $h['hub'] }}</td>
                                    <td class="{{ $h['pushed'] > 0 ? 'iqp-ok' : '' }}">{{ number_format($h['pushed']) }}</td>
                                    <td class="{{ $h['failed'] > 0 ? 'iqp-fail' : '' }}">{{ number_format($h['failed']) }}</td>
                                    <td>{{ $h['reason'] ?: ($h['failed'] === 0 ? 'OK' : '') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="iqp-hint">"Invalid hub code" = this warehouse isn't a hub the seller operates in Omniful — turn its <b>Push Quantities</b> toggle OFF on the SAP Warehouses page. "[429]" = rate-limited (auto-retried with backoff).</div>
            </div>
        @endif

        {{-- Failed samples (raw Omniful responses) --}}
        @if (! empty($panel['failed_samples']))
            <div class="iqp-card">
                <div class="iqp-sec">Failed sample responses</div>
                <div class="iqp-scroll">
                    @foreach ($panel['failed_samples'] as $s)
                        <div class="iqp-sample">
                            @isset($s['hub'])<b>{{ $s['hub'] }}</b> @endisset
                            @isset($s['status'])[{{ $s['status'] }}] @endisset
                            @isset($s['sku'])sku={{ $s['sku'] }} @endisset
                            {{ isset($s['body']) ? \Illuminate\Support\Str::after($s['body'], ' :: ') : '' }}
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Config --}}
        <div class="iqp-card">
            <div class="iqp-cfg">
                <span>Scheduled: <b>{{ $panel['config']['enabled'] ? 'On' : 'Off' }}</b></span>
                <span>Mode: <b>{{ $panel['config']['mode'] }}</b></span>
                <span>Cadence: <b>{{ $panel['config']['cadence_minutes'] }} min</b></span>
                <span>Quantity: <b>{{ $panel['config']['quantity_source'] }}</b></span>
                <span>Seller: <b>{{ $panel['config']['seller_code'] ?: '—' }}</b></span>
                <span>Throttle: <b>{{ $panel['config']['throttle_ms'] }} ms</b></span>
            </div>
            <div class="iqp-cfg" style="margin-top:.4rem;">
                <span>Endpoint: <b class="iqp-key">{{ $panel['config']['endpoint'] }}</b></span>
            </div>
            <div class="iqp-hint">Quantities push per synced item × already-synced hub (SAP WarehouseCode = Omniful hub_code) via the SELLER token. Enable scheduled runs via <code>OMNIFUL_INVENTORY_PUSH_ENABLED</code> / the Integration settings toggle.</div>
        </div>
    </div>
</x-filament-panels::page>
