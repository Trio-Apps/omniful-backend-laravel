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
        .iqp-stat { border:1px solid rgba(148,163,184,.2); border-radius:.6rem; padding:.6rem .75rem; text-align:center; }
        .iqp-stat-num { font-size:1.4rem; font-weight:700; color:#0f172a; }
        .iqp-stat-label { font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-top:.15rem; }
        .iqp-error { margin-top:.75rem; padding:.6rem .75rem; border-radius:.5rem; background:rgba(239,68,68,.1); color:#b91c1c; font-size:.8rem; white-space:pre-wrap; word-break:break-word; }
        .iqp-cfg { display:flex; gap:1.25rem; flex-wrap:wrap; font-size:.82rem; color:#475569; }
        .iqp-cfg b { color:#0f172a; }
        .iqp-hint { font-size:.78rem; color:#64748b; margin-top:.35rem; }
        @media (prefers-color-scheme: dark) {
            .iqp-stat-num, .iqp-cfg b { color:#e2e8f0; }
            .iqp-gray { color:#cbd5e1; }
        }
        :root[data-theme="dark"] .iqp-stat-num, :root[data-theme="dark"] .iqp-cfg b { color:#e2e8f0; }
    </style>

    <div class="iqp-wrap" wire:poll.5s>
        <div class="iqp-card">
            <div class="iqp-head">
                <span class="iqp-badge iqp-{{ $panel['tone'] }}">{{ $panel['status_label'] }}</span>
                @if ($panel['event_key'])
                    <span class="iqp-key">{{ $panel['event_key'] }}</span>
                @endif
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

            @if (! empty($panel['summary_lines']))
                <div class="iqp-meta">
                    @foreach ($panel['summary_lines'] as $line)
                        <span>{{ $line }}</span>
                    @endforeach
                </div>
            @endif

            <div class="iqp-meta">
                @if ($panel['requested_at'])<span>Requested: {{ $panel['requested_at'] }}</span>@endif
                @if ($panel['updated_at'])<span>Updated: {{ $panel['updated_at'] }}</span>@endif
            </div>

            @if ($panel['error'])
                <div class="iqp-error">{{ $panel['error'] }}</div>
            @endif
        </div>

        <div class="iqp-card">
            <div class="iqp-cfg">
                <span>Scheduled: <b>{{ $panel['config']['enabled'] ? 'On' : 'Off' }}</b></span>
                <span>Default mode: <b>{{ $panel['config']['mode'] }}</b></span>
                <span>Cadence: <b>{{ $panel['config']['cadence_minutes'] }} min</b></span>
                <span>Quantity: <b>{{ $panel['config']['quantity_source'] }}</b></span>
            </div>
            <div class="iqp-hint">Quantities push per synced item × already-synced hub. Hubs are read from the warehouse sync — this flow never changes them. Enable scheduled runs via <code>OMNIFUL_INVENTORY_PUSH_ENABLED</code>.</div>
        </div>
    </div>
</x-filament-panels::page>
