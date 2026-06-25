<x-filament::page>
    @php($panel = $this->getCleanupPanel())

    <x-filament::section>
        <x-slot name="heading">How it works</x-slot>
        <x-slot name="description">Reverse AR Reserve Invoices created for wrongly auto-created items, then re-send later.</x-slot>

        <div class="text-sm leading-6 text-gray-600 dark:text-gray-300 space-y-2">
            <p>
                <strong>Scan &amp; add</strong> (by Product/Item code, SAP DocNum, or Omniful order id) reads SAP
                and adds the matching invoices to the saved list below — they persist. Then per row, or by selecting
                rows, run:
            </p>
            <ul class="list-disc ps-5">
                <li><strong>Check</strong> — re-read the invoice live and refresh its state.</li>
                <li><strong>Cancel</strong> — reverse on LIVE SAP (cancel payment + delivery, credit note, COGS reversal, stamp <code>-0reversed</code>) and re-queue the order to <em>pending</em>.</li>
                <li><strong>Resend</strong> — clean Force Resend of the order to SAP (use after the items are created correctly).</li>
            </ul>
            <p class="text-danger-600 dark:text-danger-400">Whole-invoice reversal: an invoice that also contains valid items is reversed in full.</p>
        </div>
    </x-filament::section>

    <div wire:poll.5s>
        <x-filament::section>
            <x-slot name="heading">Background run status</x-slot>

            @if (! ($panel['has_event'] ?? false))
                <p class="text-sm text-gray-500 dark:text-gray-400">No cleanup run yet.</p>
            @else
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge :color="$panel['tone']">{{ $panel['status_label'] }}</x-filament::badge>
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

    {{ $this->table }}

    <x-filament-actions::modals />
</x-filament::page>
