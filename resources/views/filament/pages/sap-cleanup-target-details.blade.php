@php
    $related = (array) ($target->related ?? []);
    $payments = $related['payments'] ?? [];
    $deliveries = $related['deliveries'] ?? [];
    $cogs = $related['cogs_journals'] ?? [];
    $lines = (array) ($target->lines ?? []);

    $statusColor = match ($target->sap_doc_status) {
        'bost_Open' => 'success',
        'bost_Close', 'bost_Paid' => 'info',
        'cancelled', 'already_reversed' => 'warning',
        default => 'gray',
    };

    $sections = [
        ['key' => 'payments', 'label' => 'Incoming payments', 'rows' => $payments, 'num' => 'doc_num'],
        ['key' => 'deliveries', 'label' => 'Delivery notes', 'rows' => $deliveries, 'num' => 'doc_num'],
        ['key' => 'cogs', 'label' => 'COGS journals', 'rows' => $cogs, 'num' => 'number'],
    ];
@endphp

<div class="space-y-4">
    {{-- Invoice summary --}}
    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50/60 dark:bg-white/5 p-4">
        <div class="flex items-center justify-between gap-3 mb-3">
            <div class="text-sm text-gray-500">AR Reserve Invoice</div>
            <x-filament::badge :color="$statusColor">{{ $target->sap_doc_status ?? '—' }}</x-filament::badge>
        </div>

        <dl class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">DocNum</dt>
                <dd class="font-mono font-semibold">{{ $target->doc_num ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">DocEntry</dt>
                <dd class="font-mono">{{ $target->doc_entry }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Order (U_omo)</dt>
                <dd class="font-mono">{{ $target->order_external_id ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Customer</dt>
                <dd class="font-mono">{{ $target->card_code ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Total</dt>
                <dd class="font-semibold">{{ $target->doc_total === null ? '—' : number_format((float) $target->doc_total, 2) }}</dd>
            </div>
        </dl>
    </div>

    {{-- Related documents --}}
    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ($sections as $section)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">{{ $section['label'] }}</div>

                @if (empty($section['rows']))
                    <div class="text-sm text-gray-400">None</div>
                @else
                    <ul class="space-y-1.5">
                        @foreach ($section['rows'] as $row)
                            <li class="flex items-center gap-2">
                                <span class="font-mono text-sm font-medium">{{ $row[$section['num']] ?? ($row['jdt_num'] ?? '—') }}</span>
                                @if (! empty($row['cancelled']))
                                    <x-filament::badge color="danger" size="sm">cancelled</x-filament::badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Invoice lines --}}
    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Invoice lines</div>
        @if (empty($lines))
            <div class="text-sm text-gray-400">—</div>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach ($lines as $line)
                    <span class="inline-flex items-center gap-1 rounded-lg bg-gray-100 dark:bg-white/10 px-2.5 py-1 text-sm">
                        <span class="font-mono">{{ $line['item'] ?? '' }}</span>
                        <span class="text-gray-500">×</span>
                        <span class="font-semibold">{{ rtrim(rtrim(number_format((float) ($line['qty'] ?? 0), 2), '0'), '.') }}</span>
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    @if (! empty($target->last_error))
        <div class="rounded-xl border border-danger-200 bg-danger-50 dark:bg-danger-500/10 p-3 text-sm text-danger-700 dark:text-danger-400">
            {{ $target->last_error }}
        </div>
    @endif
</div>
