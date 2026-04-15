<x-filament::page>
    <div class="mb-4 rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="text-sm font-medium text-gray-600 dark:text-gray-300">
            In Queue Orders: <span class="font-semibold text-gray-900 dark:text-white">{{ number_format($this->getQueuedOrdersCount()) }}</span>
        </div>
    </div>

    {{ $this->table }}
</x-filament::page>
