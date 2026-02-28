<x-filament::page>
    @php($stats = method_exists($this, 'getStats') ? $this->getStats() : [])

    @if ($stats !== [])
        <div class="sap-catalog-grid">
            @foreach ($stats as $stat)
                <div class="sap-catalog-card">
                    <div class="sap-catalog-label">{{ $stat['label'] ?? '' }}</div>
                    <div class="sap-catalog-value">{{ $stat['value'] ?? 0 }}</div>
                    @if (!empty($stat['hint']))
                        <div class="sap-catalog-hint">{{ $stat['hint'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{ $this->table }}

    <style>
        .sap-catalog-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            margin-bottom: 1.5rem;
        }

        .sap-catalog-card {
            border: 1px solid #dbe4ea;
            border-radius: 16px;
            padding: 1rem;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbfc 100%);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        .sap-catalog-label {
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #48606f;
        }

        .sap-catalog-value {
            margin-top: 0.4rem;
            font-size: 1.7rem;
            line-height: 1.1;
            font-weight: 800;
            color: #13212b;
        }

        .sap-catalog-hint {
            margin-top: 0.45rem;
            font-size: 0.8rem;
            color: #607586;
        }
    </style>
</x-filament::page>
