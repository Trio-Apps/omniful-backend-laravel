<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrder;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OmnifulOrderErrorMonitor extends Page
{
    protected ?string $maxContentWidth = 'full';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Order Errors';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.omniful-order-error-monitor';

    public array $summaryCards = [];

    public array $errorCases = [];

    public array $errorCaseLabels = [];

    public array $topErrorItems = [];

    public function mount(): void
    {
        $orders = $this->loadErroredOrders();
        $this->errorCases = $this->buildErrorCases($orders);
        $this->errorCaseLabels = collect($this->errorCases)
            ->mapWithKeys(fn (array $case) => [$case['fingerprint'] => $case['message']])
            ->all();
        $this->topErrorItems = $this->buildTopErrorItems($orders);
        $this->summaryCards = $this->buildSummaryCards($orders, $this->errorCases, $this->topErrorItems);
    }

    public function getTitle(): string
    {
        return 'Order Error Monitoring';
    }

    private function loadErroredOrders(): Collection
    {
        return OmnifulOrder::query()
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
                'sap_error',
                'sap_payment_error',
                'sap_card_fee_error',
                'sap_delivery_error',
                'sap_cogs_error',
                'sap_credit_note_error',
                'sap_cancel_cogs_error',
                'last_event_at',
                'last_payload',
            ]);
    }

    private function buildErrorCases(Collection $orders): array
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
                $groups[$fingerprint]['orders'][] = [
                    'id' => $order->id,
                    'external_id' => $order->external_id,
                    'omniful_status' => $order->omniful_status,
                    'sap_status' => $order->sap_status,
                    'url' => OmnifulOrderView::getUrl(['record' => $order->id]),
                    'last_event_at' => optional($order->last_event_at)?->format('Y-m-d H:i:s'),
                ];

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

    private function buildTopErrorItems(Collection $orders): array
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

    private function buildSummaryCards(Collection $orders, array $errorCases, array $topErrorItems): array
    {
        $topCase = $errorCases[0] ?? null;
        $topItem = $topErrorItems[0] ?? null;

        return [
            [
                'label' => 'Unique Error Cases',
                'value' => number_format(count($errorCases)),
            ],
            [
                'label' => 'Affected Orders',
                'value' => number_format($orders->count()),
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

    private function extractOrderErrors(OmnifulOrder $order): array
    {
        $fields = [
            'sap_error' => 'Order / AR Reserve Invoice',
            'sap_payment_error' => 'Incoming Payment',
            'sap_card_fee_error' => 'Card Fee Journal',
            'sap_delivery_error' => 'Delivery Note',
            'sap_cogs_error' => 'COGS Journal',
            'sap_credit_note_error' => 'AR Credit Memo',
            'sap_cancel_cogs_error' => 'Cancel COGS Reversal',
        ];

        $results = [];

        foreach ($fields as $field => $stage) {
            $raw = trim((string) ($order->{$field} ?? ''));
            if ($raw === '') {
                continue;
            }

            $parsed = $this->normalizeErrorMessage($raw);
            $results[] = [
                'field' => $field,
                'stage' => $stage,
                'message' => $parsed['message'],
                'fingerprint' => Str::lower($parsed['message']),
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

    private function normalizeErrorMessage(string $raw): array
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

    private function shouldIgnoreErrorEntry(array $entry): bool
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

    private function extractOrderSkus(array $payload): array
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
}
