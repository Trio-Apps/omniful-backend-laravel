<x-filament::page>
    <div class="space-y-6">
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;">
            @foreach ($summaryCards as $card)
                <div style="border:1px solid #e5e7eb;border-radius:16px;background:#ffffff;padding:18px 20px;box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                    <div style="font-size:12px;line-height:16px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;">{{ $card['label'] }}</div>
                    <div style="margin-top:8px;font-size:28px;line-height:1.1;font-weight:700;color:#111827;">{{ $card['value'] }}</div>
                    @if (!empty($card['subtext']))
                        <div style="margin-top:8px;font-size:13px;line-height:18px;color:#4b5563;">{{ $card['subtext'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        <x-filament::section>
            <x-slot name="heading">Repeated Error Cases</x-slot>

            @if ($errorCases === [])
                <div class="text-sm text-gray-600">No captured order errors.</div>
            @else
                <div class="space-y-4">
                    @foreach ($errorCases as $case)
                        <div style="border:1px solid #e5e7eb;border-radius:16px;background:#ffffff;padding:18px 20px;">
                            <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;">
                                <div style="min-width:0;">
                                    <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;">{{ $case['stage'] }}</div>
                                    <div style="margin-top:8px;font-size:16px;font-weight:700;color:#111827;word-break:break-word;">{{ $case['message'] }}</div>
                                </div>
                                <div style="text-align:right;flex-shrink:0;">
                                    <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;">Affected Orders</div>
                                    <div style="margin-top:8px;font-size:28px;line-height:1;font-weight:700;color:#111827;">{{ number_format($case['count']) }}</div>
                                    <div style="margin-top:8px;font-size:12px;color:#6b7280;">Latest: {{ $case['latest_at'] }}</div>
                                </div>
                            </div>

                            @if ($case['top_items'] !== [])
                                <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
                                    @foreach ($case['top_items'] as $item)
                                        <span style="display:inline-flex;align-items:center;gap:6px;border:1px solid #dbeafe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:600;">
                                            {{ $item['sku'] }}
                                            <span style="color:#64748b;">{{ $item['count'] }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            <details style="margin-top:14px;">
                                <summary style="cursor:pointer;font-size:13px;font-weight:700;color:#0f766e;">View affected orders</summary>
                                <div style="margin-top:12px;display:grid;gap:10px;">
                                    @foreach ($case['orders'] as $order)
                                        <a href="{{ $order['url'] }}" style="display:flex;justify-content:space-between;gap:16px;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;text-decoration:none;background:#fafafa;">
                                            <div>
                                                <div style="font-size:14px;font-weight:700;color:#111827;">{{ $order['external_id'] }}</div>
                                                <div style="margin-top:4px;font-size:12px;color:#6b7280;">Omniful: {{ $order['omniful_status'] ?: '-' }} | SAP: {{ $order['sap_status'] ?: '-' }}</div>
                                            </div>
                                            <div style="font-size:12px;color:#6b7280;white-space:nowrap;">{{ $order['last_event_at'] ?: '-' }}</div>
                                        </a>
                                    @endforeach
                                </div>
                            </details>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Frequent Error Items</x-slot>

            @if ($topErrorItems === [])
                <div class="text-sm text-gray-600">No repeated item patterns on errored orders.</div>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:separate;border-spacing:0;">
                        <thead>
                            <tr>
                                <th style="text-align:left;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;padding:12px 14px;border-bottom:1px solid #e5e7eb;">SKU</th>
                                <th style="text-align:left;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;padding:12px 14px;border-bottom:1px solid #e5e7eb;">Affected Orders</th>
                                <th style="text-align:left;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;padding:12px 14px;border-bottom:1px solid #e5e7eb;">Top Error Cases</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topErrorItems as $item)
                                <tr>
                                    <td style="padding:14px;border-bottom:1px solid #eef2f7;font-size:14px;font-weight:700;color:#111827;">{{ $item['sku'] }}</td>
                                    <td style="padding:14px;border-bottom:1px solid #eef2f7;font-size:14px;color:#111827;">{{ number_format($item['count']) }}</td>
                                    <td style="padding:14px;border-bottom:1px solid #eef2f7;font-size:13px;color:#374151;">
                                        <div style="display:flex;flex-direction:column;gap:6px;">
                                            @foreach ($item['top_cases'] as $case)
                                                <div>{{ $errorCaseLabels[$case['fingerprint']] ?? $case['fingerprint'] }} <span style="color:#6b7280;">({{ $case['count'] }})</span></div>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament::page>
