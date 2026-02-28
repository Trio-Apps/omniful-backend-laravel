<x-filament-panels::page>
    @php($sync = $this->getCatalogSyncPanel())

    <form wire:submit="save">
        {{ $this->form }}
    </form>

    <div class="sap-queue-card">
        <div class="sap-queue-header">
            <div>
                <div class="sap-queue-title">Background SAP Sync</div>
                <div class="sap-queue-subtitle">Queue a full SAP catalog sync from here. The button dispatches a background job and does not block the page.</div>
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
            <div class="sap-queue-empty">No background SAP sync has been queued yet.</div>
        @endif

        <div class="sap-queue-note">
            cPanel / server requirement:
            <code>php artisan queue:work --stop-when-empty --queue=default --tries=1 --timeout=3600</code>
            must be running from cron or as a worker process.
        </div>
    </div>

    <style>
        .sap-queue-card {
            margin-top: 1.5rem;
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
</x-filament-panels::page>
