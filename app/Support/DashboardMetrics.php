<?php

namespace App\Support;

use App\Models\OmnifulOrder;
use App\Models\OmnifulReturnOrderEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Computes the (expensive) dashboard metrics that require scanning order
 * payloads, and caches the result. The heavy work runs from a scheduled command
 * (CLI: no web timeout, generous memory) via warm(); the Filament widgets only
 * ever READ the cache. This keeps the landing page instant and stops it from
 * running a multi-second payload scan inside an HTTP request — which first
 * exhausted memory (500) and, after the memory bump, timed out at nginx (504).
 */
class DashboardMetrics
{
    public const OVERVIEW_KEY = 'dashboard.overview_stats.v2';

    public const REVENUE_KEY = 'dashboard.revenue_chart.v2';

    /** Cache lifetime. The scheduler refreshes well within this window. */
    public const TTL = 3600;

    /**
     * Recompute both heavy dashboard payloads and store them in cache.
     */
    public function warm(): void
    {
        Cache::put(self::OVERVIEW_KEY, $this->computeOverview(), self::TTL);
        Cache::put(self::REVENUE_KEY, $this->computeRevenue(), self::TTL);
    }

    // ------------------------------------------------------------------
    // Overview stats (OverviewStats widget)
    // ------------------------------------------------------------------

    /**
     * Full overview metrics: order/revenue/return/customer totals for the
     * current and previous 30-day windows plus their weekday trend series.
     *
     * @return array<string,mixed>
     */
    public function computeOverview(): array
    {
        $today = now()->startOfDay();
        $currentStart = $today->copy()->subDays(29);
        $previousStart = $today->copy()->subDays(59);
        $previousEnd = $today->copy()->subDays(30)->endOfDay();
        $trendCut = $currentStart->copy()->addDays(23);

        $current = $this->aggregatePeriod($currentStart, now(), $trendCut);
        $previous = $this->aggregatePeriod($previousStart, $previousEnd, null);

        $returnsCurrent = OmnifulReturnOrderEvent::query()
            ->whereBetween('received_at', [$currentStart, now()])
            ->count();
        $returnsPrevious = OmnifulReturnOrderEvent::query()
            ->whereBetween('received_at', [$previousStart, $previousEnd])
            ->count();

        return [
            'orders_current' => $current['orders'],
            'orders_previous' => $previous['orders'],
            'orders_trend' => $current['orders_trend'],
            'revenue_current' => $current['revenue'],
            'revenue_previous' => $previous['revenue'],
            'revenue_trend' => $current['revenue_trend'],
            'returns_current' => $returnsCurrent,
            'returns_previous' => $returnsPrevious,
            'returns_trend' => $this->buildDailyReturnsTrend($trendCut),
            'active_customers_current' => $current['active_customers'],
            'active_customers_previous' => $previous['active_customers'],
            'customers_trend' => $current['customers_trend'],
            'stale' => false,
        ];
    }

    /**
     * Instant, payload-free fallback used when the cache is cold: cheap indexed
     * counts only, with zeroed revenue/customers/trends. Prevents the dashboard
     * from ever running the heavy payload scan inside a web request; the
     * scheduled warm() replaces this with the real numbers shortly after.
     *
     * @return array<string,mixed>
     */
    public function overviewCounts(): array
    {
        $today = now()->startOfDay();
        $currentStart = $today->copy()->subDays(29);
        $previousStart = $today->copy()->subDays(59);
        $previousEnd = $today->copy()->subDays(30)->endOfDay();
        $zero = array_fill(0, 7, 0);

        return [
            'orders_current' => OmnifulOrder::query()->whereBetween('last_event_at', [$currentStart, now()])->count(),
            'orders_previous' => OmnifulOrder::query()->whereBetween('last_event_at', [$previousStart, $previousEnd])->count(),
            'orders_trend' => $zero,
            'revenue_current' => 0.0,
            'revenue_previous' => 0.0,
            'revenue_trend' => $zero,
            'returns_current' => OmnifulReturnOrderEvent::query()->whereBetween('received_at', [$currentStart, now()])->count(),
            'returns_previous' => OmnifulReturnOrderEvent::query()->whereBetween('received_at', [$previousStart, $previousEnd])->count(),
            'returns_trend' => $zero,
            'active_customers_current' => 0,
            'active_customers_previous' => 0,
            'customers_trend' => $zero,
            'stale' => true,
        ];
    }

    /**
     * Stream all orders in [$start, $end] exactly once, accumulating order
     * count, revenue, distinct customer emails, and — when $trendCut is given —
     * the weekday trend series. ->lazy() keeps memory bounded.
     *
     * @return array{orders:int,revenue:float,active_customers:int,orders_trend:array<int,int>,revenue_trend:array<int,int>,customers_trend:array<int,int>}
     */
    private function aggregatePeriod(Carbon $start, Carbon $end, ?Carbon $trendCut): array
    {
        $orders = 0;
        $revenue = 0.0;
        $emails = [];
        $ordersTrend = array_fill(0, 7, 0);
        $revenueTrend = array_fill(0, 7, 0);
        $customersByDay = [];

        OmnifulOrder::query()
            ->whereBetween('last_event_at', [$start, $end])
            ->select(['last_payload', 'last_event_at'])
            ->lazy(200)
            ->each(function (OmnifulOrder $row) use (
                &$orders,
                &$revenue,
                &$emails,
                &$ordersTrend,
                &$revenueTrend,
                &$customersByDay,
                $trendCut
            ): void {
                $orders++;
                $payload = (array) ($row->last_payload ?? []);

                $value = data_get($payload, 'data.invoice.total')
                    ?? data_get($payload, 'data.invoice.grand_total')
                    ?? data_get($payload, 'data.total_amount')
                    ?? data_get($payload, 'data.total');
                $numeric = is_numeric($value) ? (float) $value : null;
                if ($numeric !== null) {
                    $revenue += $numeric;
                }

                $email = strtolower(trim((string) (
                    data_get($payload, 'data.customer.email')
                    ?? data_get($payload, 'data.shipping_address.email')
                    ?? data_get($payload, 'data.billing_address.email')
                )));
                if ($email !== '') {
                    $emails[$email] = true;
                }

                if ($trendCut !== null && $row->last_event_at && $row->last_event_at->gte($trendCut)) {
                    $index = max(0, min(6, $row->last_event_at->dayOfWeekIso - 1));
                    $ordersTrend[$index]++;
                    if ($numeric !== null) {
                        $revenueTrend[$index] += (int) round($numeric);
                    }
                    if ($email !== '') {
                        $customersByDay[$row->last_event_at->toDateString()][$email] = true;
                    }
                }
            });

        $customersTrend = array_fill(0, 7, 0);
        foreach ($customersByDay as $day => $set) {
            $index = max(0, min(6, Carbon::parse($day)->dayOfWeekIso - 1));
            $customersTrend[$index] = max($customersTrend[$index], count($set));
        }

        return [
            'orders' => $orders,
            'revenue' => round($revenue, 2),
            'active_customers' => count($emails),
            'orders_trend' => $ordersTrend,
            'revenue_trend' => $revenueTrend,
            'customers_trend' => $customersTrend,
        ];
    }

    /**
     * Returns are light (single timestamp column), so a direct query is fine.
     *
     * @return array<int,int>
     */
    private function buildDailyReturnsTrend(Carbon $cut): array
    {
        $series = array_fill(0, 7, 0);

        $rows = OmnifulReturnOrderEvent::query()
            ->whereBetween('received_at', [$cut, now()])
            ->get(['received_at']);

        foreach ($rows as $row) {
            if (!$row->received_at) {
                continue;
            }
            $index = max(0, min(6, $row->received_at->dayOfWeekIso - 1));
            $series[$index]++;
        }

        return $series;
    }

    // ------------------------------------------------------------------
    // Revenue by payment method (RevenueChart widget)
    // ------------------------------------------------------------------

    /**
     * Sum revenue per payment method over the last 14 days, streaming rows so
     * the payload blobs are never all held in memory at once.
     *
     * @return array<string,float>
     */
    public function computeRevenue(): array
    {
        $start = now()->startOfDay()->subDays(13);
        $totals = [];

        OmnifulOrder::query()
            ->whereBetween('last_event_at', [$start, now()])
            ->select(['last_payload'])
            ->lazy(200)
            ->each(function (OmnifulOrder $row) use (&$totals): void {
                $payload = (array) ($row->last_payload ?? []);
                $paymentMethod = trim((string) (
                    data_get($payload, 'data.payment_method')
                    ?? data_get($payload, 'data.payment.method')
                    ?? data_get($payload, 'data.payment_type')
                    ?? 'unknown'
                ));
                if ($paymentMethod === '') {
                    $paymentMethod = 'unknown';
                }

                $amount = data_get($payload, 'data.invoice.total');
                if (!is_numeric($amount)) {
                    $amount = data_get($payload, 'data.invoice.grand_total');
                }
                if (!is_numeric($amount)) {
                    $amount = data_get($payload, 'data.total_amount');
                }
                if (!is_numeric($amount)) {
                    $amount = data_get($payload, 'data.total');
                }

                if (!is_numeric($amount)) {
                    return;
                }

                $key = strtolower($paymentMethod);
                $totals[$key] = ($totals[$key] ?? 0) + (float) $amount;
            });

        return $totals;
    }
}
