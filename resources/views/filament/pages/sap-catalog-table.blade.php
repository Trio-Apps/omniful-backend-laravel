<x-filament::page>
    @php($stats = method_exists($this, 'getStats') ? $this->getStats() : [])
    @php($quickLinks = method_exists($this, 'getQuickLinks') ? $this->getQuickLinks() : [])

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

    @if ($quickLinks !== [])
        <div class="sap-catalog-links">
            @foreach ($quickLinks as $link)
                <a href="{{ $link['url'] ?? '#' }}" class="sap-catalog-link">
                    <span class="sap-catalog-link-title">{{ $link['label'] ?? '' }}</span>
                    @if (!empty($link['description']))
                        <span class="sap-catalog-link-text">{{ $link['description'] }}</span>
                    @endif
                </a>
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

        .sap-catalog-links {
            display: grid;
            gap: 0.85rem;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            margin-bottom: 1.5rem;
        }

        .sap-catalog-link {
            display: grid;
            gap: 0.25rem;
            padding: 0.95rem 1rem;
            border-radius: 14px;
            border: 1px solid #d5e6e3;
            background: linear-gradient(180deg, #f8fcfb 0%, #f1f8f7 100%);
            text-decoration: none;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .sap-catalog-link:hover {
            transform: translateY(-1px);
            border-color: #9ecdc5;
            box-shadow: 0 10px 24px rgba(34, 109, 100, 0.08);
        }

        .sap-catalog-link-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #13423b;
        }

        .sap-catalog-link-text {
            font-size: 0.8rem;
            color: #55736e;
        }
    </style>
</x-filament::page>
