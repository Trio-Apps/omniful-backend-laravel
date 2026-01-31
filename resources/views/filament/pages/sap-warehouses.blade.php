<x-filament::page>
    <div wire:loading.flex wire:target="syncWarehouses" class="sap-sync-overlay">
        <div class="sap-sync-panel">
            <div class="sap-sync-spinner"></div>
            <div class="sap-sync-text">
                <div class="sap-sync-title">Syncing Warehouses from SAP</div>
                <div class="sap-sync-subtitle">Fetching warehouse master list...</div>
            </div>
            <div class="sap-sync-bar">
                <div class="sap-sync-bar-fill"></div>
            </div>
            <div class="sap-sync-hint">Please keep this window open.</div>
        </div>
    </div>
    <div wire:loading.flex wire:target="pushWarehouses" class="sap-sync-overlay">
        <div class="sap-sync-panel">
            <div class="sap-sync-spinner sap-sync-spinner--blue"></div>
            <div class="sap-sync-text">
                <div class="sap-sync-title">Pushing Warehouses to Omniful</div>
                <div class="sap-sync-subtitle">Sending updates...</div>
            </div>
            <div class="sap-sync-bar sap-sync-bar--blue">
                <div class="sap-sync-bar-fill sap-sync-bar-fill--blue"></div>
            </div>
            <div class="sap-sync-hint">Please keep this window open.</div>
        </div>
    </div>
    <div wire:loading.remove wire:target="syncWarehouses" class="sap-sync-complete">
        <div class="sap-sync-complete-bar"></div>
    </div>
    <div wire:loading.remove wire:target="pushWarehouses" class="sap-sync-complete">
        <div class="sap-sync-complete-bar sap-sync-complete-bar--blue"></div>
    </div>
    {{ $this->table }}

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
        .sap-sync-overlay {
            position: fixed;
            inset: 0;
            z-index: 80;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(3px);
        }
        .sap-sync-panel {
            width: min(520px, 92vw);
            border-radius: 20px;
            background: #ffffff;
            padding: 28px;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25);
            display: grid;
            gap: 16px;
            text-align: left;
        }
        .sap-sync-spinner {
            width: 48px;
            height: 48px;
            border-radius: 999px;
            border: 4px solid #d1fae5;
            border-top-color: #10b981;
            animation: sap-spin 0.8s linear infinite;
        }
        .sap-sync-spinner--blue {
            border-color: #dbeafe;
            border-top-color: #3b82f6;
        }
        .sap-sync-text {
            display: grid;
            gap: 4px;
        }
        .sap-sync-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        .sap-sync-subtitle {
            font-size: 13px;
            color: #475569;
        }
        .sap-sync-bar {
            height: 8px;
            border-radius: 999px;
            background: #ecfdf3;
            overflow: hidden;
        }
        .sap-sync-bar--blue {
            background: #eff6ff;
        }
        .sap-sync-bar-fill {
            height: 100%;
            width: 45%;
            background: linear-gradient(90deg, #10b981 0%, #34d399 100%);
            animation: sap-progress 1.25s ease-in-out infinite;
        }
        .sap-sync-bar-fill--blue {
            background: linear-gradient(90deg, #3b82f6 0%, #60a5fa 100%);
        }
        .sap-sync-hint {
            font-size: 12px;
            color: #94a3b8;
        }
        .sap-sync-complete {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 79;
            display: none;
        }
        .sap-sync-complete-bar {
            position: absolute;
            left: 50%;
            top: 50%;
            width: min(520px, 92vw);
            height: 8px;
            transform: translate(-50%, -50%);
            border-radius: 999px;
            background: #10b981;
            animation: sap-complete 0.7s ease forwards;
        }
        .sap-sync-complete-bar--blue {
            background: #3b82f6;
        }
        @keyframes sap-progress {
            0% { transform: translateX(-110%); }
            50% { transform: translateX(20%); }
            100% { transform: translateX(220%); }
        }
        @keyframes sap-spin {
            to { transform: rotate(360deg); }
        }
        @keyframes sap-complete {
            0% { opacity: 0; transform: translate(-50%, -50%) scaleX(0.2); }
            50% { opacity: 1; transform: translate(-50%, -50%) scaleX(1); }
            100% { opacity: 0; transform: translate(-50%, -50%) scaleX(1); }
        }
    </style>
</x-filament::page>
