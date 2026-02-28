<x-filament::page>
    @php($cards = $this->getModuleCards())
    @php($groups = $this->getLinkGroups())

    <div class="sap-overview-shell">
        <div class="sap-overview-hero">
            <div class="sap-overview-eyebrow">SAP B1 Catalog Workspace</div>
            <h2 class="sap-overview-title">One place to sync, review, and navigate SAP snapshots</h2>
            <p class="sap-overview-text">
                Use this hub to trigger module syncs and jump into the detailed pages for finance, sales, inventory, and banking data.
            </p>
        </div>

        <div class="sap-overview-grid">
            @foreach ($cards as $card)
                <div class="sap-overview-card">
                    <div class="sap-overview-card-title">{{ $card['title'] }}</div>
                    <div class="sap-overview-card-value">{{ $card['value'] }}</div>
                    <div class="sap-overview-card-hint">{{ $card['hint'] }}</div>
                </div>
            @endforeach
        </div>

        <div class="sap-overview-groups">
            @foreach ($groups as $group)
                <section class="sap-overview-group">
                    <h3 class="sap-overview-group-title">{{ $group['title'] }}</h3>
                    <div class="sap-overview-links">
                        @foreach ($group['links'] as $link)
                            <a href="{{ $link['url'] }}" class="sap-overview-link">{{ $link['label'] }}</a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </div>

    <style>
        .sap-overview-shell {
            display: grid;
            gap: 1.5rem;
        }

        .sap-overview-hero {
            padding: 1.35rem 1.5rem;
            border-radius: 20px;
            background:
                radial-gradient(circle at top right, rgba(34, 109, 100, 0.18), transparent 34%),
                linear-gradient(135deg, #f6fbfa 0%, #eef7f5 100%);
            border: 1px solid #d8e9e5;
        }

        .sap-overview-eyebrow {
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #2f6f66;
        }

        .sap-overview-title {
            margin-top: 0.35rem;
            font-size: 1.55rem;
            line-height: 1.2;
            font-weight: 800;
            color: #15352f;
        }

        .sap-overview-text {
            margin-top: 0.45rem;
            max-width: 760px;
            font-size: 0.92rem;
            color: #58716c;
        }

        .sap-overview-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }

        .sap-overview-card {
            padding: 1rem;
            border-radius: 16px;
            border: 1px solid #dbe7e4;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        .sap-overview-card-title {
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #53716b;
        }

        .sap-overview-card-value {
            margin-top: 0.45rem;
            font-size: 1.8rem;
            line-height: 1.1;
            font-weight: 800;
            color: #112b26;
        }

        .sap-overview-card-hint {
            margin-top: 0.45rem;
            font-size: 0.82rem;
            color: #68807b;
        }

        .sap-overview-groups {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        }

        .sap-overview-group {
            padding: 1rem;
            border-radius: 18px;
            border: 1px solid #dbe7e4;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbfa 100%);
        }

        .sap-overview-group-title {
            font-size: 0.94rem;
            font-weight: 800;
            color: #173a34;
        }

        .sap-overview-links {
            display: grid;
            gap: 0.55rem;
            margin-top: 0.85rem;
        }

        .sap-overview-link {
            display: block;
            padding: 0.7rem 0.8rem;
            border-radius: 12px;
            background: #f1f8f6;
            color: #20554d;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.88rem;
            transition: background-color 0.15s ease, transform 0.15s ease;
        }

        .sap-overview-link:hover {
            background: #e4f1ed;
            transform: translateY(-1px);
        }
    </style>
</x-filament::page>
