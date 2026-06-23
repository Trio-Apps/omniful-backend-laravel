<?php

namespace App\Support;

use App\Filament\Pages\OmnifulOrderView;
use App\Models\IntegrationSetting;
use App\Models\OmnifulOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OrderErrorMonitoring
{
    public function loadErroredOrders()
    {
        $orders = OmnifulOrder::query()
            ->where(function ($query) {
                $query->whereNotNull('sap_error')
                    ->orWhereNotNull('sap_payment_error')
                    ->orWhereNotNull('sap_card_fee_error')
                    ->orWhereNotNull('sap_delivery_error')
                    ->orWhereNotNull('sap_cogs_error')
                    ->orWhereNotNull('sap_credit_note_error')
                    ->orWhereNotNull('sap_cancel_cogs_error')
                    ->orWhere('sap_status', 'failed');
            })
            ->orderByDesc('last_event_at')
            ->get([
                'id',
                'external_id',
                'omniful_status',
                'sap_status',
                'sap_doc_entry',
                'sap_doc_num',
                'sap_error',
                'sap_payment_status',
                'sap_payment_doc_entry',
                'sap_payment_error',
                'sap_card_fee_status',
                'sap_card_fee_journal_entry',
                'sap_card_fee_error',
                'sap_delivery_status',
                'sap_delivery_doc_entry',
                'sap_delivery_error',
                'sap_cogs_status',
                'sap_cogs_journal_entry',
                'sap_cogs_error',
                'sap_credit_note_status',
                'sap_credit_note_doc_entry',
                'sap_credit_note_error',
                'sap_cancel_cogs_status',
                'sap_cancel_cogs_journal_entry',
                'sap_cancel_cogs_error',
                'last_event_at',
                'last_payload',
            ]);

        // Hide errors for orders created in Omniful before the SAP integration
        // cutoff date — those orders are intentionally excluded from SAP, so
        // their (pre-go-live) errors should not show on the dashboard. This is a
        // display-only filter: nothing is mutated and it follows the same cutoff
        // setting as the webhook flow, so changing the date re-computes the view.
        $cutoff = $this->orderCutoffDate();
        if ($cutoff !== null) {
            $cutoffDate = $cutoff->format('Y-m-d');
            $orders = $orders->reject(function (OmnifulOrder $order) use ($cutoffDate) {
                $createdAt = $this->orderCreatedAtFromPayload((array) ($order->last_payload ?? []));

                // Conservative: only hide when we have a confident creation date
                // strictly before the cutoff; unknown dates stay visible.
                return $createdAt !== null && $createdAt->format('Y-m-d') < $cutoffDate;
            })->values();
        }

        return $orders;
    }

    /**
     * Configured SAP integration cutoff date (start of day), or null when none
     * is set. Mirrors OrderWebhookService: IntegrationSetting row first, then the
     * config('omniful.order_sync.cutoff_date') fallback.
     */
    private function orderCutoffDate(): ?Carbon
    {
        $settings = IntegrationSetting::query()->first();

        $value = null;
        if ($settings && array_key_exists('order_cutoff_date', $settings->getAttributes())) {
            $value = $settings->order_cutoff_date;
        }
        if ($value === null || $value === '') {
            $value = config('omniful.order_sync.cutoff_date');
        }
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Omniful order creation timestamp from the stored last_payload, mirroring
     * the data.order_created_at -> data.created_at precedence. Returns null when
     * missing or outside a sane year window (rejects Omniful's Go zero-time).
     */
    private function orderCreatedAtFromPayload(array $payload): ?Carbon
    {
        $value = trim((string) (
            data_get($payload, 'data.order_created_at')
            ?? data_get($payload, 'order_created_at')
            ?? data_get($payload, 'data.created_at')
            ?? data_get($payload, 'created_at')
            ?? ''
        ));
        if ($value === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        $year = (int) $parsed->format('Y');
        if ($year < 2000 || $year > 2099) {
            return null;
        }

        return $parsed;
    }

    /**
     * Clear stale *_error fields on orders where the corresponding *_doc_entry
     * already exists (the operation actually succeeded after a previous retry
     * but the error text was never wiped). Returns the number of fields cleared.
     */
    public function clearStaleErrors(): int
    {
        $stages = [
            ['error' => 'sap_error', 'doc' => 'sap_doc_entry', 'status' => 'sap_status'],
            ['error' => 'sap_payment_error', 'doc' => 'sap_payment_doc_entry', 'status' => 'sap_payment_status'],
            ['error' => 'sap_card_fee_error', 'doc' => 'sap_card_fee_journal_entry', 'status' => 'sap_card_fee_status'],
            ['error' => 'sap_delivery_error', 'doc' => 'sap_delivery_doc_entry', 'status' => 'sap_delivery_status'],
            ['error' => 'sap_cogs_error', 'doc' => 'sap_cogs_journal_entry', 'status' => 'sap_cogs_status'],
            ['error' => 'sap_credit_note_error', 'doc' => 'sap_credit_note_doc_entry', 'status' => 'sap_credit_note_status'],
            ['error' => 'sap_cancel_cogs_error', 'doc' => 'sap_cancel_cogs_journal_entry', 'status' => 'sap_cancel_cogs_status'],
        ];

        $cleared = 0;
        foreach ($stages as $stage) {
            $cleared += OmnifulOrder::query()
                ->whereNotNull($stage['error'])
                ->whereNotNull($stage['doc'])
                ->where($stage['doc'], '!=', '')
                ->update([$stage['error'] => null]);
        }

        return $cleared;
    }

    public function buildErrorCases(Collection $orders): array
    {
        $groups = [];

        foreach ($orders as $order) {
            $seenFingerprints = [];

            foreach ($this->extractOrderErrors($order) as $errorEntry) {
                if ($this->shouldIgnoreErrorEntry($errorEntry)) {
                    continue;
                }

                $fingerprint = $errorEntry['fingerprint'];
                if (isset($seenFingerprints[$fingerprint])) {
                    continue;
                }

                $seenFingerprints[$fingerprint] = true;

                if (!isset($groups[$fingerprint])) {
                    $groups[$fingerprint] = [
                        'fingerprint' => $fingerprint,
                        'stages' => [],
                        'message' => $errorEntry['message'],
                        'count' => 0,
                        'latest_at' => null,
                        'orders' => [],
                        'items' => [],
                    ];
                }

                $groups[$fingerprint]['count']++;
                $groups[$fingerprint]['stages'][$errorEntry['stage']] = true;
                $groups[$fingerprint]['orders'][] = $this->mapOrder($order);

                $latestAt = optional($order->last_event_at)?->timestamp ?? 0;
                $currentLatest = $groups[$fingerprint]['latest_at']['timestamp'] ?? 0;
                if ($latestAt >= $currentLatest) {
                    $groups[$fingerprint]['latest_at'] = [
                        'timestamp' => $latestAt,
                        'label' => optional($order->last_event_at)?->format('Y-m-d H:i:s') ?? '-',
                    ];
                }

                foreach ($this->extractOrderSkus($order->last_payload ?? []) as $sku) {
                    $groups[$fingerprint]['items'][$sku] = ($groups[$fingerprint]['items'][$sku] ?? 0) + 1;
                }
            }
        }

        return collect($groups)
            ->map(function (array $group) {
                uasort($group['items'], fn (int $a, int $b) => $b <=> $a);
                $group['stages'] = array_values(array_keys($group['stages']));
                $group['top_items'] = collect($group['items'])
                    ->take(5)
                    ->map(fn (int $count, string $sku) => ['sku' => $sku, 'count' => $count])
                    ->values()
                    ->all();
                unset($group['items']);
                $group['latest_at'] = $group['latest_at']['label'] ?? '-';
                return $group;
            })
            ->sortByDesc(function (array $group) {
                return sprintf('%08d-%s', $group['count'], $group['latest_at']);
            })
            ->values()
            ->all();
    }

    public function buildTopErrorItems(Collection $orders): array
    {
        $items = [];

        foreach ($orders as $order) {
            $caseFingerprints = collect($this->extractOrderErrors($order))
                ->reject(fn (array $entry) => $this->shouldIgnoreErrorEntry($entry))
                ->pluck('fingerprint')
                ->unique()
                ->values()
                ->all();

            if ($caseFingerprints === []) {
                continue;
            }

            foreach ($this->extractOrderSkus($order->last_payload ?? []) as $sku) {
                if (!isset($items[$sku])) {
                    $items[$sku] = [
                        'sku' => $sku,
                        'count' => 0,
                        'cases' => [],
                    ];
                }

                $items[$sku]['count']++;
                foreach ($caseFingerprints as $fingerprint) {
                    $items[$sku]['cases'][$fingerprint] = ($items[$sku]['cases'][$fingerprint] ?? 0) + 1;
                }
            }
        }

        return collect($items)
            ->map(function (array $item) {
                arsort($item['cases']);
                $item['top_cases'] = collect($item['cases'])
                    ->take(3)
                    ->map(fn (int $count, string $fingerprint) => [
                        'fingerprint' => $fingerprint,
                        'count' => $count,
                    ])
                    ->values()
                    ->all();
                unset($item['cases']);
                return $item;
            })
            ->sortByDesc('count')
            ->take(20)
            ->values()
            ->all();
    }

    public function buildSummaryCards(Collection $orders, array $errorCases, array $topErrorItems): array
    {
        $topCase = $errorCases[0] ?? null;
        $topItem = $topErrorItems[0] ?? null;

        // Count only orders that contributed at least one active error to a
        // case. Orders whose errors were fully resolved by the smart recovery
        // (doc entry populated or status moved to created/logged) drop out
        // here so the dashboard matches what's actually listed below.
        $activeOrders = $orders->filter(function ($order) {
            $entries = $this->extractOrderErrors($order);
            foreach ($entries as $entry) {
                if (!$this->shouldIgnoreErrorEntry($entry)) {
                    return true;
                }
            }
            return false;
        });

        return [
            [
                'label' => 'Unique Error Cases',
                'value' => number_format(count($errorCases)),
            ],
            [
                'label' => 'Affected Orders',
                'value' => number_format($activeOrders->count()),
            ],
            [
                'label' => 'Top Repeated Error',
                'value' => $topCase ? number_format((int) $topCase['count']) . ' cases' : '0',
                'subtext' => $topCase['message'] ?? 'No repeated errors',
            ],
            [
                'label' => 'Top Error SKU',
                'value' => $topItem['sku'] ?? '-',
                'subtext' => $topItem ? number_format((int) $topItem['count']) . ' affected orders' : 'No errored SKUs',
            ],
        ];
    }

    public function buildCaseDetail(Collection $orders, string $fingerprint, array $filters = []): array
    {
        $matching = collect();

        foreach ($orders as $order) {
            $entries = collect($this->extractOrderErrors($order))
                ->reject(fn (array $entry) => $this->shouldIgnoreErrorEntry($entry))
                ->filter(fn (array $entry) => $entry['fingerprint'] === $fingerprint)
                ->values();

            if ($entries->isEmpty()) {
                continue;
            }

            $skus = $this->extractOrderSkus($order->last_payload ?? []);
            $mapped = $this->mapOrder($order);
            $mapped['error_stages'] = $entries->pluck('stage')->unique()->values()->all();
            $mapped['skus'] = $skus;
            $mapped['date'] = optional($order->last_event_at)?->toDateString();
            $matching->push($mapped);
        }

        $filtered = $matching
            ->when(!empty($filters['stage']), fn (Collection $c) => $c->filter(fn (array $row) => in_array($filters['stage'], $row['error_stages'], true)))
            ->when(!empty($filters['sku']), function (Collection $c) use ($filters) {
                $needle = Str::lower(trim((string) $filters['sku']));
                return $c->filter(function (array $row) use ($needle) {
                    foreach ($row['skus'] as $sku) {
                        if (str_contains(Str::lower($sku), $needle)) {
                            return true;
                        }
                    }
                    return false;
                });
            })
            ->when(!empty($filters['date_from']), fn (Collection $c) => $c->filter(fn (array $row) => ($row['date'] ?? '') >= $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Collection $c) => $c->filter(fn (array $row) => ($row['date'] ?? '') <= $filters['date_to']))
            ->values();

        $itemCounts = [];
        $stageCounts = [];
        $dailyCounts = [];

        foreach ($filtered as $order) {
            foreach ($order['skus'] as $sku) {
                $itemCounts[$sku] = ($itemCounts[$sku] ?? 0) + 1;
            }

            foreach ($order['error_stages'] as $stage) {
                $stageCounts[$stage] = ($stageCounts[$stage] ?? 0) + 1;
            }

            $day = $order['date'] ?: '-';
            $dailyCounts[$day] = ($dailyCounts[$day] ?? 0) + 1;
        }

        arsort($itemCounts);
        arsort($stageCounts);
        krsort($dailyCounts);

        $message = collect($this->buildErrorCases($orders))
            ->firstWhere('fingerprint', $fingerprint)['message'] ?? $fingerprint;

        return [
            'message' => $message,
            'orders' => $filtered->all(),
            'summary' => [
                'orders' => $filtered->count(),
                'unique_skus' => count($itemCounts),
                'top_stage' => array_key_first($stageCounts) ?? '-',
                'latest_at' => $filtered->first()['last_event_at'] ?? '-',
            ],
            'top_items' => collect($itemCounts)->take(10)->map(fn (int $count, string $sku) => ['sku' => $sku, 'count' => $count])->values()->all(),
            'stage_breakdown' => collect($stageCounts)->map(fn (int $count, string $stage) => ['stage' => $stage, 'count' => $count])->values()->all(),
            'daily_breakdown' => collect($dailyCounts)->map(fn (int $count, string $day) => ['day' => $day, 'count' => $count])->values()->all(),
        ];
    }

    public function extractOrderErrors(OmnifulOrder $order): array
    {
        // Each stage: the error column, the human label, the matching doc-entry
        // column (an op succeeded once that column has a value), and the
        // matching status column (we skip stages that landed on a success
        // state like 'created').
        $stages = [
            [
                'error' => 'sap_error',
                'label' => 'Order / AR Reserve Invoice',
                'doc' => 'sap_doc_entry',
                'status' => 'sap_status',
            ],
            [
                'error' => 'sap_payment_error',
                'label' => 'Incoming Payment',
                'doc' => 'sap_payment_doc_entry',
                'status' => 'sap_payment_status',
            ],
            [
                'error' => 'sap_card_fee_error',
                'label' => 'Card Fee Journal',
                'doc' => 'sap_card_fee_journal_entry',
                'status' => 'sap_card_fee_status',
            ],
            [
                'error' => 'sap_delivery_error',
                'label' => 'Delivery Note',
                'doc' => 'sap_delivery_doc_entry',
                'status' => 'sap_delivery_status',
            ],
            [
                'error' => 'sap_cogs_error',
                'label' => 'COGS Journal',
                'doc' => 'sap_cogs_journal_entry',
                'status' => 'sap_cogs_status',
            ],
            [
                'error' => 'sap_credit_note_error',
                'label' => 'AR Credit Memo',
                'doc' => 'sap_credit_note_doc_entry',
                'status' => 'sap_credit_note_status',
            ],
            [
                'error' => 'sap_cancel_cogs_error',
                'label' => 'Cancel COGS Reversal',
                'doc' => 'sap_cancel_cogs_journal_entry',
                'status' => 'sap_cancel_cogs_status',
            ],
        ];

        $results = [];

        foreach ($stages as $stage) {
            $raw = trim((string) ($order->{$stage['error']} ?? ''));
            if ($raw === '') {
                continue;
            }

            // Stale-error suppression: the smart recovery flow (lazy create,
            // duplicate-ownership rebind, foreign-invoice handling, etc.)
            // ends up either populating the stage's doc entry (real success)
            // or moving the status to created. In either case the original
            // failure message is no longer active and should drop from the
            // monitor instead of haunting the dashboard forever.
            $docValue = trim((string) ($order->{$stage['doc']} ?? ''));
            if ($docValue !== '') {
                continue;
            }

            $statusValue = strtolower(trim((string) ($order->{$stage['status']} ?? '')));
            if (in_array($statusValue, ['created', 'logged', 'created_mixed', 'updated', 'ignored'], true)) {
                continue;
            }

            $parsed = $this->normalizeErrorMessage($raw);
            $results[] = [
                'field' => $stage['error'],
                'stage' => $stage['label'],
                'message' => $parsed['message'],
                'fingerprint' => $this->fingerprintForMessage($parsed['message']),
            ];
        }

        if ($results === [] && (string) $order->sap_status === 'failed') {
            $results[] = [
                'field' => 'sap_status',
                'stage' => 'General SAP Failure',
                'message' => 'Failed without a captured SAP error message',
                'fingerprint' => 'general sap failure|failed without a captured sap error message',
            ];
        }

        return $results;
    }

    /**
     * Group errors that differ only by a variable identifier (invoice
     * DocEntry/DocNum, order id) into ONE case. e.g. "(10002) 7075020 AR invoice
     * already exists" and "(10002) 7074822 AR invoice already exists" share a
     * fingerprint instead of spawning one case per number. Digit runs collapse to
     * '#'; different error TYPES still differ by their surrounding text.
     */
    public function fingerprintForMessage(string $message): string
    {
        return Str::lower(trim((string) preg_replace('/\d+/', '#', $message)));
    }

    public function normalizeErrorMessage(string $raw): array
    {
        if (preg_match('/cURL error\s+(\d+):/i', $raw, $curlMatches) === 1) {
            $curlCode = $curlMatches[1];

            if ($curlCode === '28') {
                return ['message' => '[cURL 28] SAP Service Layer timeout'];
            }

            return ['message' => '[cURL ' . $curlCode . '] SAP Service Layer request failed'];
        }

        $message = null;
        $code = null;

        if (preg_match('/"code"\s*:\s*(-?\d+)/', $raw, $matches) === 1) {
            $code = $matches[1];
        }

        if (preg_match('/"value"\s*:\s*"([^"]+)"/', $raw, $matches) === 1) {
            $message = stripcslashes($matches[1]);
        }

        $message = trim((string) ($message ?: preg_replace('/\s+/', ' ', $raw)));
        if ($code !== null && !str_starts_with($message, '[' . $code . ']')) {
            $message = '[' . $code . '] ' . $message;
        }

        return ['message' => $message];
    }

    public function shouldIgnoreErrorEntry(array $entry): bool
    {
        $message = Str::lower((string) ($entry['message'] ?? ''));

        if ($message === '') {
            return true;
        }

        return str_contains($message, 'ignored:')
            || str_contains($message, 'no sap action required')
            || str_contains($message, 'unmapped order event/status/payment')
            || str_contains($message, 'sto packed event is not part of ar reserve invoice flow');
    }

    public function extractOrderSkus(array $payload): array
    {
        $skuKeys = ['sku_code', 'seller_sku_code', 'item_code', 'sku'];
        $paths = [
            'items',
            'data.items',
            'data.order_items',
            'order_items',
        ];

        $skus = [];

        foreach ($paths as $path) {
            $items = data_get($payload, $path, []);
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                foreach ($skuKeys as $key) {
                    $value = trim((string) data_get($item, $key, ''));
                    if ($value !== '') {
                        $skus[$value] = true;
                        break;
                    }
                }
            }
        }

        return array_keys($skus);
    }

    public function mapOrder(OmnifulOrder $order): array
    {
        return [
            'id' => $order->id,
            'external_id' => $order->external_id,
            'omniful_status' => $order->omniful_status,
            'sap_status' => $order->sap_status,
            'url' => OmnifulOrderView::getUrl(['record' => $order->id]),
            'last_event_at' => optional($order->last_event_at)?->format('Y-m-d H:i:s'),
        ];
    }
}
