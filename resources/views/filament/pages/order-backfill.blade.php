<x-filament-panels::page>
    @php($panel = $this->getPanel())

    <style>
        .obf-wrap { display:flex; flex-direction:column; gap:1rem; }
        .obf-card { border:1px solid rgba(148,163,184,.25); border-radius:.75rem; padding:1rem 1.25rem; background:rgba(148,163,184,.04); }
        .obf-row { display:flex; align-items:flex-end; gap:1rem; flex-wrap:wrap; }
        .obf-field { display:flex; flex-direction:column; gap:.3rem; }
        .obf-field label { font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
        .obf-field input { border:1px solid rgba(148,163,184,.4); border-radius:.5rem; padding:.45rem .6rem; background:transparent; color:inherit; font-size:.9rem; }
        .obf-btn { border:0; border-radius:.5rem; padding:.55rem 1.1rem; font-weight:600; font-size:.85rem; cursor:pointer; }
        .obf-btn-primary { background:#2563eb; color:#fff; }
        .obf-btn-danger { background:#dc2626; color:#fff; }
        .obf-btn:disabled { opacity:.5; cursor:not-allowed; }
        .obf-head { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        .obf-badge { font-size:.75rem; font-weight:700; padding:.2rem .6rem; border-radius:999px; text-transform:uppercase; letter-spacing:.03em; }
        .obf-gray { background:rgba(148,163,184,.18); color:#475569; }
        .obf-success { background:rgba(16,185,129,.16); color:#047857; }
        .obf-warning { background:rgba(245,158,11,.18); color:#b45309; }
        .obf-danger { background:rgba(239,68,68,.16); color:#b91c1c; }
        .obf-grid { margin-top:1rem; display:grid; grid-template-columns:repeat(auto-fit,minmax(105px,1fr)); gap:.6rem; }
        .obf-stat { border:1px solid rgba(148,163,184,.2); border-radius:.6rem; padding:.6rem .75rem; text-align:center; }
        .obf-stat-num { font-size:1.35rem; font-weight:700; color:#0f172a; }
        .obf-stat-label { font-size:.66rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-top:.15rem; }
        .obf-meta { margin-top:.6rem; font-size:.8rem; color:#64748b; display:flex; gap:1.25rem; flex-wrap:wrap; }
        .obf-error { margin-top:.75rem; padding:.6rem .75rem; border-radius:.5rem; background:rgba(239,68,68,.1); color:#b91c1c; font-size:.8rem; white-space:pre-wrap; word-break:break-word; }
        .obf-hint { font-size:.78rem; color:#64748b; margin-top:.35rem; }
        table.obf-days { width:100%; border-collapse:collapse; margin-top:1rem; font-size:.82rem; }
        table.obf-days th, table.obf-days td { padding:.4rem .6rem; text-align:right; border-bottom:1px solid rgba(148,163,184,.18); }
        table.obf-days th:first-child, table.obf-days td:first-child { text-align:left; font-family:ui-monospace,monospace; }
        table.obf-days th { font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
        .obf-miss { color:#b45309; font-weight:700; }
        @media (prefers-color-scheme: dark) { .obf-stat-num { color:#e2e8f0; } .obf-gray { color:#cbd5e1; } }
        :root[data-theme="dark"] .obf-stat-num { color:#e2e8f0; }
    </style>

    <div class="obf-wrap">
        {{-- Start form --}}
        <div class="obf-card">
            <div class="obf-row">
                <div class="obf-field">
                    <label>From (created date)</label>
                    <input type="date" wire:model="dateFrom" />
                </div>
                <div class="obf-field">
                    <label>To (created date)</label>
                    <input type="date" wire:model="dateTo" />
                </div>
                <button class="obf-btn obf-btn-primary" wire:click="start" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="start">Start Backfill</span>
                    <span wire:loading wire:target="start">Starting…</span>
                </button>
                @if (($panel['has_run'] ?? false) && ($panel['is_active'] ?? false))
                    <button class="obf-btn obf-btn-danger" wire:click="cancelRun" wire:confirm="Stop the running backfill?">Stop</button>
                @endif
            </div>
            <div class="obf-hint">
                Pulls orders from Omniful for the chosen <b>created-date</b> range, finds the ones <b>missing</b> from our DB
                (dedup by Omniful order id), and enqueues them onto the order queue — the queue then pushes them to SAP.
                Already-present orders are skipped. Rate-limited &amp; resumable; safe to run over long ranges.
            </div>
        </div>

        {{-- Live monitor --}}
        <div class="obf-card" wire:poll.5s>
            @if (! ($panel['has_run'] ?? false))
                <div class="obf-hint">No backfill has run yet. Pick a date range above and press <b>Start Backfill</b>.</div>
            @else
                <div class="obf-head">
                    <span class="obf-badge obf-{{ $panel['tone'] }}">{{ $panel['status_label'] }}</span>
                    <span class="obf-hint" style="margin:0">Run #{{ $panel['id'] }} · {{ $panel['range'] }}</span>
                </div>

                <div class="obf-grid">
                    <div class="obf-stat"><div class="obf-stat-num">{{ number_format($panel['scanned']) }}</div><div class="obf-stat-label">Scanned</div></div>
                    <div class="obf-stat"><div class="obf-stat-num">{{ number_format($panel['existing']) }}</div><div class="obf-stat-label">Already Have</div></div>
                    <div class="obf-stat"><div class="obf-stat-num">{{ number_format($panel['missing']) }}</div><div class="obf-stat-label">Missing</div></div>
                    <div class="obf-stat"><div class="obf-stat-num">{{ number_format($panel['enqueued']) }}</div><div class="obf-stat-label">Enqueued</div></div>
                    <div class="obf-stat"><div class="obf-stat-num">{{ number_format($panel['pages']) }}</div><div class="obf-stat-label">Pages</div></div>
                    <div class="obf-stat"><div class="obf-stat-num">{{ number_format($panel['queue_pending']) }}</div><div class="obf-stat-label">Queue Pending</div></div>
                    <div class="obf-stat"><div class="obf-stat-num">{{ number_format($panel['rate_limit_hits']) }}</div><div class="obf-stat-label">429 Hits</div></div>
                </div>

                <div class="obf-meta">
                    @if ($panel['last_activity'])<span>Activity: {{ $panel['last_activity'] }}</span>@endif
                    @if ($panel['started_at'])<span>Started: {{ $panel['started_at'] }}</span>@endif
                    @if ($panel['finished_at'])<span>Finished: {{ $panel['finished_at'] }}</span>@endif
                </div>

                @if ($panel['last_error'])
                    <div class="obf-error">{{ $panel['last_error'] }}</div>
                @endif

                @if (! empty($panel['days']))
                    <table class="obf-days">
                        <thead>
                            <tr><th>Date</th><th>Orders</th><th>Already Have</th><th>Missing</th><th>Enqueued</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($panel['days'] as $d)
                                <tr>
                                    <td>{{ $d['day'] }}</td>
                                    <td>{{ number_format($d['total']) }}</td>
                                    <td>{{ number_format($d['existing']) }}</td>
                                    <td class="{{ $d['missing'] > 0 ? 'obf-miss' : '' }}">{{ number_format($d['missing']) }}</td>
                                    <td>{{ number_format($d['enqueued']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif
        </div>
    </div>
</x-filament-panels::page>
