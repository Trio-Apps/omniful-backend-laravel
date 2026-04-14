<x-filament::page>
    {{ $this->table }}

    @if ($isSapErrorModalOpen)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            wire:click="closeSapErrorModal"
        >
            <div
                class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl"
                wire:click.stop
            >
                <div class="flex items-center justify-between border-b px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">SAP Error</h2>
                    <button
                        type="button"
                        class="rounded-md px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700"
                        wire:click="closeSapErrorModal"
                    >
                        Close
                    </button>
                </div>

                <div class="max-h-[70vh] overflow-auto px-6 py-5">
                    <pre class="whitespace-pre-wrap break-words rounded-xl bg-slate-950 p-4 text-sm text-slate-100">{{ $activeSapError }}</pre>
                </div>
            </div>
        </div>
    @endif
</x-filament::page>
