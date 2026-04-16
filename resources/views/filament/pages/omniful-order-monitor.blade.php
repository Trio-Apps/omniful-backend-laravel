<x-filament::page>
    <div class="mb-4 grid gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">In Queue Orders</div>
            <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($this->getQueuedOrdersCount()) }}</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Failed Orders</div>
            <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($this->getFailedOrdersCount()) }}</div>
        </div>
    </div>

    {{ $this->table }}
</x-filament::page>
