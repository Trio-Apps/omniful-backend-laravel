<x-filament::section>
    <div class="text-sm text-gray-600">SAP returned an error while creating the PO:</div>
    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" style="white-space: pre-wrap;">{{ $error ?? '-' }}</div>
</x-filament::section>
