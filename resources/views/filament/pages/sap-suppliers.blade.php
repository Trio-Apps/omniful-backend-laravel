<x-filament::page>
    @php($sync = $this->getSupplierSyncPanel())
    @php($push = $this->getSupplierPushPanel())

    <div wire:poll.5s class="sap-queue-card">
        <div class="sap-queue-header">
            <div>
                <div class="sap-queue-title">Background Supplier Sync</div>
                <div class="sap-queue-subtitle">Queue supplier sync from here. The page stays responsive while the worker processes the request.</div>
            </div>
            <span class="sap-queue-badge sap-queue-badge--{{ $sync['tone'] }}">{{ $sync['status_label'] }}</span>
        </div>

        @if ($sync['has_event'])
            <div class="sap-queue-meta">
                <div><strong>Event:</strong> {{ $sync['event_key'] }}</div>
                <div><strong>Queued At:</strong> {{ $sync['requested_at'] }}</div>
                <div><strong>Last Update:</strong> {{ $sync['updated_at'] }}</div>
            </div>

            @if ($sync['summary_lines'] !== [])
                <div class="sap-queue-summary">
                    @foreach ($sync['summary_lines'] as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div>
            @endif

            @if (!empty($sync['error']))
                <div class="sap-queue-error">{{ $sync['error'] }}</div>
            @endif
        @else
            <div class="sap-queue-empty">No background supplier sync has been queued yet.</div>
        @endif

        <div class="sap-queue-note">
            Server requirement:
            <code>php artisan queue:work --stop-when-empty --queue=default --tries=1 --timeout=3600</code>
            must be running from cron or as a worker process.
        </div>
    </div>

    <div wire:poll.5s class="sap-queue-card">
        <div class="sap-queue-header">
            <div>
                <div class="sap-queue-title">Background Supplier Push</div>
                <div class="sap-queue-subtitle">Push supplier updates to Omniful in the background without blocking the page.</div>
            </div>
            <div class="sap-queue-actions">
                @if ($push['can_stop'])
                    <button type="button" class="sap-queue-stop" wire:click="cancelSupplierPush">
                        {{ $push['status'] === 'cancel_requested' ? 'Stopping...' : 'Stop' }}
                    </button>
                @endif
                <span class="sap-queue-badge sap-queue-badge--{{ $push['tone'] }}">{{ $push['status_label'] }}</span>
            </div>
        </div>

        @if ($push['has_event'])
            <div class="sap-queue-meta">
                <div><strong>Event:</strong> {{ $push['event_key'] }}</div>
                <div><strong>Queued At:</strong> {{ $push['requested_at'] }}</div>
                <div><strong>Last Update:</strong> {{ $push['updated_at'] }}</div>
            </div>

            @if ($push['summary_lines'] !== [])
                <div class="sap-queue-summary">
                    @foreach ($push['summary_lines'] as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div>
            @endif

            @if (!empty($push['error']))
                <div class="sap-queue-error">{{ $push['error'] }}</div>
            @endif
        @else
            <div class="sap-queue-empty">No background supplier push has been queued yet.</div>
        @endif
    </div>

    {{ $this->table }}

    <x-filament-actions::modals />

    <style>
        .fi-ta-table tbody tr {
            animation: sap-row-in 0.35s ease both;
        }
        .fi-ta-table tbody tr:nth-child(1) { animation-delay: 0.02s; }
        .fi-ta-table tbody tr:nth-child(2) { animation-delay: 0.04s; }
        .fi-ta-table tbody tr:nth-child(3) { animation-delay: 0.06s; }
        .fi-ta-table tbody tr:nth-child(4) { animation-delay: 0.08s; }
        .fi-ta-table tbody tr:nth-child(5) { animation-delay: 0.10s; }
        .fi-ta-table tbody tr:nth-child(6) { animation-delay: 0.12s; }
        .fi-ta-table tbody tr:nth-child(7) { animation-delay: 0.14s; }
        .fi-ta-table tbody tr:nth-child(8) { animation-delay: 0.16s; }
        .fi-ta-table tbody tr:nth-child(9) { animation-delay: 0.18s; }
        .fi-ta-table tbody tr:nth-child(10) { animation-delay: 0.20s; }
        @keyframes sap-row-in {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .sap-queue-card {
            margin-bottom: 1rem;
            border: 1px solid #d8e7e3;
            border-radius: 18px;
            background: linear-gradient(180deg, #ffffff 0%, #f7fbfa 100%);
            padding: 1.1rem 1.15rem;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
        }
        .sap-queue-header {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            justify-content: space-between;
        }
        .sap-queue-title {
            font-size: 1rem;
            font-weight: 800;
            color: #173830;
        }
        .sap-queue-subtitle {
            margin-top: 0.25rem;
            font-size: 0.82rem;
            color: #607772;
            max-width: 760px;
        }
        .sap-queue-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .sap-queue-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .sap-queue-stop {
            border: 1px solid #efcaca;
            background: #fff5f5;
            color: #b42318;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
        }
        .sap-queue-stop:hover {
            background: #feeaea;
        }
        .sap-queue-badge--success {
            background: #eaf8ef;
            color: #167241;
        }
        .sap-queue-badge--warning {
            background: #fff5db;
            color: #9a6700;
        }
        .sap-queue-badge--danger {
            background: #fdeaea;
            color: #b42318;
        }
        .sap-queue-badge--gray {
            background: #eef2f6;
            color: #51606f;
        }
        .sap-queue-meta {
            display: grid;
            gap: 0.35rem;
            margin-top: 0.95rem;
            font-size: 0.84rem;
            color: #324b46;
        }
        .sap-queue-summary {
            margin-top: 0.9rem;
            display: grid;
            gap: 0.25rem;
            padding: 0.8rem 0.9rem;
            border-radius: 12px;
            background: #f2f8f6;
            font-size: 0.82rem;
            color: #35514b;
        }
        .sap-queue-error {
            margin-top: 0.9rem;
            padding: 0.8rem 0.9rem;
            border-radius: 12px;
            background: #fdf0f0;
            color: #ae2c2c;
            font-size: 0.82rem;
            white-space: pre-wrap;
        }
        .sap-queue-empty {
            margin-top: 0.9rem;
            font-size: 0.84rem;
            color: #607772;
        }
        .sap-queue-note {
            margin-top: 0.95rem;
            font-size: 0.8rem;
            color: #5f7771;
        }
        .sap-queue-note code {
            display: inline-block;
            margin-top: 0.2rem;
            padding: 0.15rem 0.35rem;
            border-radius: 6px;
            background: #eef5f3;
            color: #214f47;
        }
    </style>
</x-filament::page>
