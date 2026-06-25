<x-filament::page>
    @php($panel = $this->getCleanupPanel())

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
