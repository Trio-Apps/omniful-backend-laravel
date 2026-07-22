<x-filament-panels::page>
    @php($panel = $this->getPanel())

    {{-- Custom bits (stat grid + table) are styled with a scoped <style> block,
         NOT Tailwind utilities — the compiled panel CSS purges any utility class
         not already used elsewhere, so new arbitrary utilities render unstyled. --}}
    <style>
        .obf-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:.75rem; }
        .obf-stat { border-radius:.75rem; padding:.85rem .75rem; text-align:center; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.05); border:1px solid rgba(17,24,39,.07); }
        .obf-stat-num { font-size:1.6rem; font-weight:700; line-height:1.1; color:#111827; font-variant-numeric:tabular-nums; }
        .obf-stat-label { margin-top:.25rem; font-size:.72rem; font-weight:500; letter-spacing:.02em; color:#6b7280; }
        .obf-meta { margin-top:1rem; display:flex; flex-wrap:wrap; gap:.35rem 1.5rem; font-size:.85rem; color:#6b7280; }
        .obf-meta b { color:#374151; font-weight:600; }
        .obf-err { margin-top:.85rem; border-radius:.6rem; padding:.6rem .8rem; font-size:.85rem; background:rgba(239,68,68,.1); color:#b91c1c; white-space:pre-wrap; word-break:break-word; }
        .obf-tbl { width:100%; border-collapse:collapse; margin-top:1rem; font-size:.88rem; }
        .obf-tbl th { padding:.5rem .7rem; font-size:.7rem; font-weight:600; letter-spacing:.03em; text-transform:uppercase; color:#6b7280; border-bottom:1px solid rgba(17,24,39,.1); text-align:right; }
        .obf-tbl td { padding:.5rem .7rem; text-align:right; color:#374151; border-bottom:1px solid rgba(17,24,39,.06); font-variant-numeric:tabular-nums; }
        .obf-tbl th:first-child, .obf-tbl td:first-child { text-align:left; }
        .obf-tbl td:first-child { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
        .obf-miss { color:#d97706; font-weight:700; }
        /* Filament toggles dark mode via the `dark` class on <html> — key off THAT,
           not prefers-color-scheme (which follows the OS and washed the text out
           to near-white on a light panel when the OS was in dark mode). */
        .dark .obf-stat { background:rgba(255,255,255,.05); border-color:rgba(255,255,255,.1); box-shadow:none; }
        .dark .obf-stat-num { color:#f8fafc; }
        .dark .obf-stat-label, .dark .obf-meta { color:#94a3b8; }
        .dark .obf-meta b { color:#e2e8f0; }
        .dark .obf-tbl th { color:#94a3b8; border-color:rgba(255,255,255,.12); }
        .dark .obf-tbl td { color:#cbd5e1; border-color:rgba(255,255,255,.06); }
        .dark .obf-miss { color:#fbbf24; }
    </style>

    {{-- Start form --}}
    <x-filament::section>
        <x-slot name="heading">Start a backfill</x-slot>
        <x-slot name="description">
            Pulls orders from Omniful for the chosen created-date range, finds the ones missing from our DB
            (dedup by Omniful order id) and enqueues them onto the order queue. Already-present orders are skipped.
            Rate-limited &amp; resumable — safe over long ranges.
        </x-slot>

        <div style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:1rem;">
            <div style="display:flex; flex-direction:column; gap:.3rem;">
                <label style="font-size:.72rem; font-weight:500; color:#6b7280;">From (created date)</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model="dateFrom" />
                </x-filament::input.wrapper>
            </div>

            <div style="display:flex; flex-direction:column; gap:.3rem;">
                <label style="font-size:.72rem; font-weight:500; color:#6b7280;">To (created date)</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model="dateTo" />
                </x-filament::input.wrapper>
            </div>

            <x-filament::button wire:click="start" wire:target="start" wire:loading.attr="disabled" icon="heroicon-o-cloud-arrow-down">
                Start Backfill
            </x-filament::button>

            @if (($panel['has_run'] ?? false) && ($panel['is_active'] ?? false))
                <x-filament::button color="danger" icon="heroicon-o-stop-circle" wire:click="cancelRun" wire:confirm="Stop the running backfill?">
                    Stop
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>

    {{-- Start from an uploaded order-id list --}}
    <x-filament::section>
        <x-slot name="heading">Or backfill from an order-id list</x-slot>
        <x-slot name="description">
            Upload a file of Omniful order ids (xlsx / csv / txt — every numeric id in any column is read).
            Each id is pulled from Omniful directly by its order id; ids we already have, and no-op
            statuses (on_hold / picked / packed), are skipped. No date scan needed.
        </x-slot>

        <div style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:1rem;">
            <div style="display:flex; flex-direction:column; gap:.3rem;">
                <label style="font-size:.72rem; font-weight:500; color:#6b7280;">Order-id file (.xlsx / .csv / .txt)</label>
                <input type="file" wire:model="idFile" accept=".xlsx,.xlsm,.csv,.txt"
                       style="font-size:.85rem; padding:.4rem; border:1px solid rgba(17,24,39,.15); border-radius:.5rem; background:#fff;" />
                <span wire:loading wire:target="idFile" style="font-size:.72rem; color:#6b7280;">Uploading…</span>
            </div>

            <x-filament::button color="success" icon="heroicon-o-arrow-up-tray"
                                wire:click="startIdList" wire:target="startIdList,idFile" wire:loading.attr="disabled">
                Start ID Backfill
            </x-filament::button>
            <span wire:loading wire:target="startIdList" style="font-size:.8rem; color:#6b7280;">Reading file &amp; queueing…</span>
        </div>
    </x-filament::section>

    {{-- Live monitor --}}
    <div wire:poll.5s>
        <x-filament::section>
            <x-slot name="heading">
                @if ($panel['has_run'] ?? false)
                    <span style="display:inline-flex; align-items:center; gap:.6rem; flex-wrap:wrap;">
                        <x-filament::badge :color="$panel['tone']">{{ $panel['status_label'] }}</x-filament::badge>
                        <span style="font-size:.85rem; font-weight:400; color:#6b7280;">Run #{{ $panel['id'] }} &middot; {{ $panel['range'] }}</span>
                    </span>
                @else
                    Live monitor
                @endif
            </x-slot>

            @if (! ($panel['has_run'] ?? false))
                <p style="font-size:.9rem; color:#6b7280;">No backfill has run yet. Pick a date range above and press <b>Start Backfill</b>.</p>
            @else
                @php($stats = [
                    ['label' => 'Scanned', 'value' => $panel['scanned']],
                    ['label' => 'Already Have', 'value' => $panel['existing']],
                    ['label' => 'Missing', 'value' => $panel['missing']],
                    ['label' => 'Skipped', 'value' => $panel['skipped']],
                    ['label' => 'Enqueued', 'value' => $panel['enqueued']],
                    ['label' => 'Pages', 'value' => $panel['pages']],
                    ['label' => 'Queue Pending', 'value' => $panel['queue_pending']],
                    ['label' => '429 Hits', 'value' => $panel['rate_limit_hits']],
                ])

                <div class="obf-grid">
                    @foreach ($stats as $stat)
                        <div class="obf-stat">
                            <div class="obf-stat-num">{{ number_format((int) $stat['value']) }}</div>
                            <div class="obf-stat-label">{{ $stat['label'] }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="obf-meta">
                    @if ($panel['last_activity'])<span>Activity: <b>{{ $panel['last_activity'] }}</b></span>@endif
                    @if ($panel['started_at'])<span>Started: {{ $panel['started_at'] }}</span>@endif
                    @if ($panel['finished_at'])<span>Finished: {{ $panel['finished_at'] }}</span>@endif
                </div>

                @if ($panel['last_error'])
                    <div class="obf-err">{{ $panel['last_error'] }}</div>
                @endif

                @if (! empty($panel['days']))
                    <div style="overflow-x:auto;">
                        <table class="obf-tbl">
                            <thead>
                                <tr><th>Date</th><th>Orders</th><th>Already Have</th><th>Missing</th><th>Skipped</th><th>Enqueued</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($panel['days'] as $d)
                                    <tr>
                                        <td>{{ $d['day'] }}</td>
                                        <td>{{ number_format($d['total']) }}</td>
                                        <td>{{ number_format($d['existing']) }}</td>
                                        <td class="{{ $d['missing'] > 0 ? 'obf-miss' : '' }}">{{ number_format($d['missing']) }}</td>
                                        <td>{{ number_format($d['skipped']) }}</td>
                                        <td>{{ number_format($d['enqueued']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
