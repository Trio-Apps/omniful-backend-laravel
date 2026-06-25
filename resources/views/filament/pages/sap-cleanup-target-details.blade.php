@php
    $related = (array) ($target->related ?? []);
    $payments = $related['payments'] ?? [];
    $deliveries = $related['deliveries'] ?? [];
    $cogs = $related['cogs_journals'] ?? [];
    $lines = (array) ($target->lines ?? []);
@endphp

<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-2">
        <div><span class="text-gray-500">Invoice DocNum:</span> <strong>{{ $target->doc_num ?? '—' }}</strong></div>
        <div><span class="text-gray-500">DocEntry:</span> {{ $target->doc_entry }}</div>
        <div><span class="text-gray-500">Order (U_omo):</span> {{ $target->order_external_id ?? '—' }}</div>
        <div><span class="text-gray-500">Customer:</span> {{ $target->card_code ?? '—' }}</div>
        <div><span class="text-gray-500">Total:</span> {{ $target->doc_total === null ? '—' : number_format((float) $target->doc_total, 2) }}</div>
        <div><span class="text-gray-500">SAP status:</span> {{ $target->sap_doc_status ?? '—' }}</div>
    </div>

    <div>
        <div class="font-semibold mb-1">Incoming payments</div>
        @if (empty($payments))
            <div class="text-gray-500">None found.</div>
        @else
            <ul class="list-disc ps-5">
                @foreach ($payments as $p)
                    <li>DocNum {{ $p['doc_num'] ?? '—' }} (entry {{ $p['doc_entry'] ?? '—' }})
                        @if (! empty($p['cancelled'])) <span class="text-warning-600">— cancelled</span> @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div>
        <div class="font-semibold mb-1">Delivery notes</div>
        @if (empty($deliveries))
            <div class="text-gray-500">None found.</div>
        @else
            <ul class="list-disc ps-5">
                @foreach ($deliveries as $d)
                    <li>DocNum {{ $d['doc_num'] ?? '—' }} (entry {{ $d['doc_entry'] ?? '—' }})
                        @if (! empty($d['cancelled'])) <span class="text-warning-600">— cancelled</span> @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div>
        <div class="font-semibold mb-1">COGS journals</div>
        @if (empty($cogs))
            <div class="text-gray-500">None found.</div>
        @else
            <ul class="list-disc ps-5">
                @foreach ($cogs as $j)
                    <li>Journal No. {{ $j['number'] ?? $j['jdt_num'] ?? '—' }} (JdtNum {{ $j['jdt_num'] ?? '—' }})</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div>
        <div class="font-semibold mb-1">Invoice lines</div>
        @if (empty($lines))
            <div class="text-gray-500">—</div>
        @else
            <ul class="list-disc ps-5">
                @foreach ($lines as $l)
                    <li>{{ $l['item'] ?? '' }} × {{ rtrim(rtrim(number_format((float) ($l['qty'] ?? 0), 2), '0'), '.') }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    @if (! empty($target->last_error))
        <div class="text-danger-600 dark:text-danger-400">Last error: {{ $target->last_error }}</div>
    @endif
</div>
