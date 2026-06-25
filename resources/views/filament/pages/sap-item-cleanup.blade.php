@php
    $panel = $this->getCleanupPanel();
    $toneClasses = [
        'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-400',
        'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-400',
        'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400',
        'gray' => 'bg-gray-100 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-300',
    ];
    $reversible = $this->previewReversibleCount();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">How it works</x-slot>
        <x-slot name="description">Reverse AR Reserve Invoices created for wrongly auto-created items.</x-slot>

        <div class="text-sm leading-6 text-gray-600 dark:text-gray-300 space-y-2">
            <p>
                Use <strong>Preview targets</strong> to find the invoices (read-only) by
                <strong>Product / Item code</strong>, <strong>SAP invoice DocNum</strong>, or
                <strong>Omniful order id</strong>. Then <strong>Reverse previewed invoices</strong>
                executes on <strong>LIVE SAP</strong>: for each invoice it cancels the incoming
                payment + delivery, posts an AR credit note, posts a COGS reversal journal, and
                stamps <code>&lt;order&gt;-0reversed</code> on <code>U_omo</code> + <code>U_ZidId</code>.
            </p>
            <p class="text-danger-600 dark:text-danger-400">
                Whole-invoice reversal: an invoice that also contains valid items will be reversed in full.
            </p>
        </div>
    </x-filament::section>

    {{-- Background cleanup status --}}
    <div wire:poll.5s>
        <x-filament::section>
            <x-slot name="heading">Background cleanup status</x-slot>

            @if (! ($panel['has_event'] ?? false))
                <p class="text-sm text-gray-500 dark:text-gray-400">No cleanup has run yet.</p>
            @else
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $toneClasses[$panel['tone']] ?? $toneClasses['gray'] }}">
                            {{ $panel['status_label'] }}
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $panel['event_key'] }}</span>
                    </div>

                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        Requested: {{ $panel['requested_at'] ?? '—' }} · Updated: {{ $panel['updated_at'] ?? '—' }}
                    </div>

                    @if (! empty($panel['summary_lines']))
                        <ul class="list-disc ps-5 text-sm text-gray-700 dark:text-gray-200">
                            @foreach ($panel['summary_lines'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if (! empty($panel['error']))
                        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $panel['error'] }}</p>
                    @endif
                </div>
            @endif
        </x-filament::section>
    </div>

    {{-- Preview results --}}
    @if ($this->hasPreview)
        <x-filament::section>
            <x-slot name="heading">
                Preview — {{ count($this->previewRows) }} invoice(s)
                @if ($reversible !== count($this->previewRows))
                    ({{ $reversible }} reversible, {{ count($this->previewRows) - $reversible }} already reversed)
                @endif
            </x-slot>
            <x-slot name="description">{{ $this->cleanupMode }} = {{ $this->cleanupValue }}</x-slot>

            @if (empty($this->previewRows))
                <p class="text-sm text-gray-500 dark:text-gray-400">No matching invoices.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 pe-3">DocNum</th>
                                <th class="py-2 pe-3">Order (U_omo)</th>
                                <th class="py-2 pe-3">Customer</th>
                                <th class="py-2 pe-3">Total</th>
                                <th class="py-2 pe-3">Status</th>
                                <th class="py-2 pe-3">Items</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->previewRows as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                                    <td class="py-2 pe-3 font-medium">{{ $row['doc_num'] }}</td>
                                    <td class="py-2 pe-3">{{ $row['order'] }}</td>
                                    <td class="py-2 pe-3">{{ $row['card_code'] }}</td>
                                    <td class="py-2 pe-3">{{ number_format((float) $row['doc_total'], 2) }}</td>
                                    <td class="py-2 pe-3">
                                        @if (! empty($row['already_reversed']))
                                            <span class="text-gray-400">already reversed</span>
                                        @elseif (! empty($row['cancelled']))
                                            <span class="text-warning-600 dark:text-warning-400">cancelled</span>
                                        @else
                                            {{ $row['status'] }}
                                        @endif
                                    </td>
                                    <td class="py-2 pe-3">
                                        @foreach ($row['lines'] as $line)
                                            <div class="text-xs text-gray-600 dark:text-gray-300">
                                                {{ $line['item'] }} × {{ rtrim(rtrim(number_format((float) $line['qty'], 2), '0'), '.') }}
                                            </div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
