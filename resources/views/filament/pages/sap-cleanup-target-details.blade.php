@php
    $related = (array) ($target->related ?? []);
    $lines = (array) ($target->lines ?? []);

    $statusColor = match ($target->sap_doc_status) {
        'bost_Open' => 'success',
        'bost_Close', 'bost_Paid' => 'info',
        'cancelled', 'already_reversed' => 'warning',
        default => 'gray',
    };

    $sections = [
        ['label' => 'Incoming payments', 'rows' => $related['payments'] ?? [], 'num' => 'doc_num'],
        ['label' => 'Delivery notes', 'rows' => $related['deliveries'] ?? [], 'num' => 'doc_num'],
        ['label' => 'COGS journals', 'rows' => $related['cogs_journals'] ?? [], 'num' => 'number'],
    ];
@endphp

<style>
    .sct-wrap { display: flex; flex-direction: column; gap: 0.85rem; }
    .sct-card { border: 1px solid #e5e7eb; border-radius: 14px; padding: 0.95rem 1.05rem; background: #fafafa; }
    .sct-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.7rem; }
    .sct-title { font-size: 0.8rem; color: #6b7280; font-weight: 600; }
    .sct-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem 1.75rem; }
    .sct-pair { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; font-size: 0.875rem; padding: 0.15rem 0; border-bottom: 1px dashed #eceff1; }
    .sct-k { color: #6b7280; }
    .sct-v { font-weight: 600; color: #1f2937; }
    .sct-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .sct-docs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.65rem; }
    .sct-doccard { border: 1px solid #e5e7eb; border-radius: 12px; padding: 0.75rem 0.85rem; background: #fff; }
    .sct-label { font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280; margin-bottom: 0.55rem; }
    .sct-list { display: flex; flex-direction: column; gap: 0.4rem; }
    .sct-num { display: flex; align-items: center; gap: 0.45rem; font-size: 0.9rem; font-weight: 600; color: #173830; }
    .sct-none { font-size: 0.85rem; color: #9ca3af; }
    .sct-x { font-size: 0.66rem; font-weight: 700; color: #b42318; background: #fdeaea; border-radius: 999px; padding: 0.05rem 0.45rem; }
    .sct-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
    .sct-chip { display: inline-flex; align-items: center; gap: 0.3rem; background: #eef5f3; border: 1px solid #dcebe7; border-radius: 10px; padding: 0.28rem 0.6rem; font-size: 0.85rem; color: #214f47; }
    .sct-err { border: 1px solid #f3c4c4; background: #fdf0f0; color: #a12c2c; border-radius: 12px; padding: 0.6rem 0.85rem; font-size: 0.85rem; }
    @media (max-width: 640px) {
        .sct-docs { grid-template-columns: 1fr; }
        .sct-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="sct-wrap">
    <div class="sct-card">
        <div class="sct-head">
            <div class="sct-title">AR Reserve Invoice</div>
            <x-filament::badge :color="$statusColor">{{ $target->sap_doc_status ?? '—' }}</x-filament::badge>
        </div>
        <div class="sct-grid">
            <div class="sct-pair"><span class="sct-k">DocNum</span><span class="sct-v sct-mono">{{ $target->doc_num ?? '—' }}</span></div>
            <div class="sct-pair"><span class="sct-k">DocEntry</span><span class="sct-v sct-mono">{{ $target->doc_entry }}</span></div>
            <div class="sct-pair"><span class="sct-k">Order (U_omo)</span><span class="sct-v sct-mono">{{ $target->order_external_id ?? '—' }}</span></div>
            <div class="sct-pair"><span class="sct-k">Customer</span><span class="sct-v sct-mono">{{ $target->card_code ?? '—' }}</span></div>
            <div class="sct-pair"><span class="sct-k">Total</span><span class="sct-v">{{ $target->doc_total === null ? '—' : number_format((float) $target->doc_total, 2) }}</span></div>
        </div>
    </div>

    <div class="sct-docs">
        @foreach ($sections as $section)
            <div class="sct-doccard">
                <div class="sct-label">{{ $section['label'] }}</div>
                @if (empty($section['rows']))
                    <div class="sct-none">None</div>
                @else
                    <div class="sct-list">
                        @foreach ($section['rows'] as $row)
                            <div class="sct-num">
                                <span class="sct-mono">{{ $row[$section['num']] ?? ($row['jdt_num'] ?? '—') }}</span>
                                @if (! empty($row['cancelled']))
                                    <span class="sct-x">cancelled</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="sct-card">
        <div class="sct-label">Invoice lines</div>
        @if (empty($lines))
            <div class="sct-none">—</div>
        @else
            <div class="sct-chips">
                @foreach ($lines as $line)
                    <span class="sct-chip">
                        <span class="sct-mono">{{ $line['item'] ?? '' }}</span> ×
                        <strong>{{ rtrim(rtrim(number_format((float) ($line['qty'] ?? 0), 2), '0'), '.') }}</strong>
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    @if (! empty($target->last_error))
        <div class="sct-err">{{ $target->last_error }}</div>
    @endif
</div>
