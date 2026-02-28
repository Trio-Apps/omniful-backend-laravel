<x-filament-widgets::widget>
    <x-filament::section>
        <div class="sap-widget-header">
            <div class="sap-widget-title">SAP Catalog Shortcuts</div>
            <div class="sap-widget-text">Jump directly to the synced SAP data areas from the main dashboard.</div>
        </div>

        <div class="sap-widget-grid">
            @foreach ($links as $link)
                <a href="{{ $link['url'] }}" class="sap-widget-link">
                    <span class="sap-widget-link-title">{{ $link['label'] }}</span>
                    <span class="sap-widget-link-hint">{{ $link['hint'] }}</span>
                </a>
            @endforeach
        </div>
    </x-filament::section>

    <style>
        .sap-widget-header {
            display: grid;
            gap: 0.2rem;
            margin-bottom: 1rem;
        }

        .sap-widget-title {
            font-size: 1rem;
            font-weight: 800;
            color: #16332d;
        }

        .sap-widget-text {
            font-size: 0.82rem;
            color: #5c7771;
        }

        .sap-widget-grid {
            display: grid;
            gap: 0.85rem;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .sap-widget-link {
            display: grid;
            gap: 0.3rem;
            padding: 0.9rem 1rem;
            border-radius: 14px;
            border: 1px solid #d7e7e3;
            background: linear-gradient(180deg, #ffffff 0%, #f5fbf9 100%);
            text-decoration: none;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .sap-widget-link:hover {
            transform: translateY(-1px);
            border-color: #9ccbc2;
            box-shadow: 0 10px 24px rgba(34, 109, 100, 0.08);
        }

        .sap-widget-link-title {
            font-size: 0.9rem;
            font-weight: 800;
            color: #16433c;
        }

        .sap-widget-link-hint {
            font-size: 0.78rem;
            color: #607872;
        }
    </style>
</x-filament-widgets::widget>
