<x-filament-panels::page>
    @php($panel = $this->getPanel())

    {{-- Start form --}}
    <x-filament::section>
        <x-slot name="heading">Start a backfill</x-slot>
        <x-slot name="description">
            Pulls orders from Omniful for the chosen created-date range, finds the ones missing from our DB
            (dedup by Omniful order id) and enqueues them onto the order queue. Already-present orders are skipped.
            Rate-limited &amp; resumable — safe over long ranges.
        </x-slot>

        <div class="flex flex-wrap items-end gap-4">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">From (created date)</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model="dateFrom" />
                </x-filament::input.wrapper>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">To (created date)</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model="dateTo" />
                </x-filament::input.wrapper>
            </div>

            <x-filament::button
                wire:click="start"
                wire:target="start"
                wire:loading.attr="disabled"
                icon="heroicon-o-cloud-arrow-down"
            >
                Start Backfill
            </x-filament::button>

            @if (($panel['has_run'] ?? false) && ($panel['is_active'] ?? false))
                <x-filament::button
                    color="danger"
                    icon="heroicon-o-stop-circle"
                    wire:click="cancelRun"
                    wire:confirm="Stop the running backfill?"
                >
                    Stop
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>

    {{-- Live monitor --}}
    <div wire:poll.5s>
        <x-filament::section>
            <x-slot name="heading">
                @if ($panel['has_run'] ?? false)
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge :color="$panel['tone']">{{ $panel['status_label'] }}</x-filament::badge>
                        <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                            Run #{{ $panel['id'] }} &middot; {{ $panel['range'] }}
                        </span>
                    </div>
                @else
                    Live monitor
                @endif
            </x-slot>

            @if (! ($panel['has_run'] ?? false))
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No backfill has run yet. Pick a date range above and press <span class="font-semibold">Start Backfill</span>.
                </p>
            @else
                @php($stats = [
                    ['label' => 'Scanned', 'value' => $panel['scanned']],
                    ['label' => 'Already Have', 'value' => $panel['existing']],
                    ['label' => 'Missing', 'value' => $panel['missing']],
                    ['label' => 'Enqueued', 'value' => $panel['enqueued']],
                    ['label' => 'Pages', 'value' => $panel['pages']],
                    ['label' => 'Queue Pending', 'value' => $panel['queue_pending']],
                    ['label' => '429 Hits', 'value' => $panel['rate_limit_hits']],
                ])

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                    @foreach ($stats as $stat)
                        <div class="rounded-xl bg-white p-3 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <div class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                                {{ number_format((int) $stat['value']) }}
                            </div>
                            <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                    @if ($panel['last_activity'])<span>Activity: <span class="text-gray-700 dark:text-gray-300">{{ $panel['last_activity'] }}</span></span>@endif
                    @if ($panel['started_at'])<span>Started: {{ $panel['started_at'] }}</span>@endif
                    @if ($panel['finished_at'])<span>Finished: {{ $panel['finished_at'] }}</span>@endif
                </div>

                @if ($panel['last_error'])
                    <div class="mt-3 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                        {{ $panel['last_error'] }}
                    </div>
                @endif

                @if (! empty($panel['days']))
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    <th class="py-2 pr-3 text-left font-medium">Date</th>
                                    <th class="px-3 py-2 text-right font-medium">Orders</th>
                                    <th class="px-3 py-2 text-right font-medium">Already Have</th>
                                    <th class="px-3 py-2 text-right font-medium">Missing</th>
                                    <th class="py-2 pl-3 text-right font-medium">Enqueued</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($panel['days'] as $d)
                                    <tr>
                                        <td class="py-2 pr-3 text-left font-mono text-gray-700 dark:text-gray-300">{{ $d['day'] }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($d['total']) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($d['existing']) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums {{ $d['missing'] > 0 ? 'font-semibold text-warning-600 dark:text-warning-400' : 'text-gray-700 dark:text-gray-300' }}">{{ number_format($d['missing']) }}</td>
                                        <td class="py-2 pl-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($d['enqueued']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
