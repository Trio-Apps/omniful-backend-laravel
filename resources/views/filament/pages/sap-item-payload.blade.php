@php
    $type = $preview['type'] ?? 'skipped';
    $resource = $preview['resource'] ?? null;
    $payload = $preview['payload'] ?? null;
    $note = $preview['note'] ?? null;

    $badge = match ($type) {
        'sku' => ['SKU', 'bg-green-100 text-green-800'],
        'kit' => ['KIT', 'bg-blue-100 text-blue-800'],
        'ignored' => ['Ignored', 'bg-yellow-100 text-yellow-800'],
        default => ['Skipped', 'bg-gray-100 text-gray-700'],
    };
@endphp

<div class="space-y-3 text-sm">
    <div class="flex flex-wrap items-center gap-2">
        <span class="font-semibold text-gray-700">{{ $code }}</span>
        <span class="rounded px-2 py-0.5 text-xs font-medium {{ $badge[1] }}">{{ $badge[0] }}</span>
        @if ($resource)
            <span class="text-xs text-gray-500">→ Omniful endpoint: <code>{{ $resource }}</code></span>
        @endif
    </div>

    @if ($note)
        <div class="rounded bg-yellow-50 px-3 py-2 text-yellow-800">
            {{ $note }}
        </div>
    @endif

    @if (is_array($payload))
        <pre class="max-h-[60vh] overflow-auto rounded bg-gray-900 p-4 text-xs leading-relaxed text-gray-100">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    @else
        <div class="text-gray-500">No payload would be sent for this item.</div>
    @endif
</div>
