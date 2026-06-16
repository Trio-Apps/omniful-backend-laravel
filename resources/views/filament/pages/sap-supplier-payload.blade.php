@php
    $type = $preview['type'] ?? 'skipped';
    $resource = $preview['resource'] ?? null;
    $payload = $preview['payload'] ?? null;
    $note = $preview['note'] ?? null;

    $response = $response ?? null;
    $responseCode = $responseCode ?? null;
    $syncedAt = $syncedAt ?? null;

    $badge = match ($type) {
        'supplier' => ['SUPPLIER', 'bg-green-100 text-green-800'],
        'ignored' => ['Ignored', 'bg-yellow-100 text-yellow-800'],
        default => ['Skipped', 'bg-gray-100 text-gray-700'],
    };

    // Colour the HTTP status: 2xx green, anything else red.
    $codeOk = $responseCode !== null && (int) $responseCode >= 200 && (int) $responseCode < 300;
    $codeClass = $responseCode === null
        ? 'bg-gray-100 text-gray-700'
        : ($codeOk ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
@endphp

<div class="space-y-4 text-sm">
    <div class="flex flex-wrap items-center gap-2">
        <span class="font-semibold text-gray-700">{{ $code }}</span>
        <span class="rounded px-2 py-0.5 text-xs font-medium {{ $badge[1] }}">{{ $badge[0] }}</span>
        @if ($resource)
            <span class="text-xs text-gray-500">→ Omniful endpoint: <code>{{ $resource }}</code></span>
        @endif
        @if ($responseCode !== null)
            <span class="rounded px-2 py-0.5 text-xs font-medium {{ $codeClass }}">HTTP {{ $responseCode }}</span>
        @endif
        @if ($syncedAt)
            <span class="text-xs text-gray-400">· {{ $syncedAt }}</span>
        @endif
    </div>

    @if ($note)
        <div class="rounded bg-yellow-50 px-3 py-2 text-yellow-800">
            {{ $note }}
        </div>
    @endif

    {{-- Payload sent to Omniful --}}
    <div>
        <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Payload sent to Omniful</div>
        @if (is_array($payload))
            <pre class="max-h-[40vh] overflow-auto rounded bg-gray-900 p-4 text-xs leading-relaxed text-gray-100">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @else
            <div class="text-gray-500">No payload would be sent for this supplier.</div>
        @endif
    </div>

    {{-- Response returned by Omniful --}}
    <div>
        <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Omniful response</div>
        @if (filled($response))
            @php
                // The stored body is prefixed with "[METHOD] URL :: {json}". Try to
                // pretty-print the JSON part; otherwise show the raw string.
                $pretty = $response;
                if (($pos = strpos((string) $response, '::')) !== false) {
                    $head = trim(substr((string) $response, 0, $pos));
                    $jsonPart = trim(substr((string) $response, $pos + 2));
                    $decoded = json_decode($jsonPart, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $pretty = $head . "\n\n" . json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
            @endphp
            <pre class="max-h-[40vh] overflow-auto rounded bg-gray-900 p-4 text-xs leading-relaxed {{ $codeOk ? 'text-green-200' : 'text-red-200' }}">{{ $pretty }}</pre>
        @else
            <div class="text-gray-500">No response captured yet — push this supplier to Omniful first.</div>
        @endif
    </div>
</div>
