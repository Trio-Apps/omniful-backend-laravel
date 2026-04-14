<x-filament::page>
    {{ $this->table }}

    @if ($isSapErrorModalOpen)
        <div
            style="position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.55); padding: 1rem;"
            wire:click="closeSapErrorModal"
        >
            <div
                style="width: 100%; max-width: 56rem; max-height: 80vh; overflow: hidden; border-radius: 16px; background: #ffffff; box-shadow: 0 24px 64px rgba(15, 23, 42, 0.24);"
                wire:click.stop
            >
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; border-bottom: 1px solid #e5e7eb; padding: 1rem 1.5rem;">
                    <h2 style="margin: 0; font-size: 1.125rem; font-weight: 700; color: #111827;">SAP Error</h2>
                    <button
                        type="button"
                        style="border: 0; border-radius: 8px; background: #f3f4f6; padding: 0.5rem 0.875rem; font-size: 0.875rem; font-weight: 600; color: #374151; cursor: pointer;"
                        wire:click="closeSapErrorModal"
                    >
                        Close
                    </button>
                </div>

                <div style="max-height: calc(80vh - 73px); overflow: auto; padding: 1.25rem 1.5rem;">
                    <pre style="margin: 0; white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere; border-radius: 12px; background: #020617; padding: 1rem; font-size: 0.875rem; line-height: 1.6; color: #e2e8f0;">{{ $activeSapError }}</pre>
                </div>
            </div>
        </div>
    @endif
</x-filament::page>
