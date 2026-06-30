<?php

namespace App\Filament\Widgets;

use App\Models\OmnifulOrder;
use App\Models\OmnifulReturnOrderEvent;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class OverviewStats extends StatsOverviewWidget
{
    /**
     * How long the computed dashboard metrics are cached. The dashboard covers
     * a 30-day window, so a few minutes of staleness is fine — and it keeps the
     * landing page fast instead of re-scanning every order on each render/poll.
     */
    private const CACHE_TTL = 300;

    protected function getStats(): array
    {
        $data = Cache::remember('dashboard.overview_stats.v1', self::CACHE_TTL, fn (): array => $this->computeMetrics());

        return [
            Stat::make('Orders (30d)', number_format($data['orders_current']))
                ->description($this->describeDelta($data['orders_current'], $data['orders_previous']))
                ->descriptionIcon($this->deltaIcon($data['orders_current'], $data['orders_previous']))
                ->chart($data['orders_trend'])
                ->color($this->deltaColor($data['orders_current'], $data['orders_previous'])),
            Stat::make('Revenue (30d)', number_format($data['revenue_current'], 2))
                ->description($this->describeDelta($data['revenue_current'], $data['revenue_previous']))
                ->descriptionIcon($this->deltaIcon($data['revenue_current'], $data['revenue_previous']))
                ->chart($data['revenue_trend'])
                ->color($this->deltaColor($data['revenue_current'], $data['revenue_previous'])),
            Stat::make('Return Events (30d)', number_format($data['returns_current']))
                ->description($this->describeDelta($data['returns_current'], $data['returns_previous']))
                ->descriptionIcon($this->deltaIcon($data['returns_current'], $data['returns_previous']))
                ->chart($data['returns_trend'])
                ->color($this->deltaColor($data['returns_previous'], $data['returns_current'])),
            Stat::make('Active Customers (30d)', number_format($data['active_customers_current']))
                ->description($this->describeDelta($data['active_customers_current'], $data['active_customers_previous']))
                ->descriptionIcon($this->deltaIcon($data['active_customers_current'], $data['active_customers_previous']))
                ->chart($data['customers_trend'])
                ->color($this->deltaColor($data['active_customers_current'], $data['active_customers_previous'])),
        ];
    }

    /**
     * Compute every dashboard metric with a memory-bounded single pass per
     * period. The previous implementation loaded each order's full last_payload
     * JSON for 30 days (twice) into memory via ->get(), which exhausted PHP's
     * memory_limit on large datasets and crashed the dashboard with a blank 500
     * (the fatal happened before Laravel could even render an error page).
     * We now stream rows with ->lazy() so only a small chunk is held at a time.
     *
     * @return array<string,mixed>
     */
    private function computeMetrics(): array
    {
        $today = now()->startOfDay();
        $currentStart = $today->copy()->subDays(29);
        $previousStart = $today->copy()->subDays(59);
        $previousEnd = $today->copy()->subDays(30)->endOfDay();
        $trendCut = $currentStart->copy()->addDays(23);

        // Current period needs trends; previous period only needs totals.
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
        ];
    }

    /**
     * Stream all orders in [$start, $end] exactly once, accumulating order
     * count, revenue, distinct customer emails, and — when $trendCut is given —
     * the weekday trend series for orders, revenue and customers. Memory stays
     * bounded because ->lazy() hydrates rows in small chunks instead of loading
     * every payload at once.
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

    private function describeDelta(float|int $current, float|int $previous): string
    {
        if ($previous <= 0 && $current <= 0) {
            return 'No change vs previous 30 days';
        }

        if ($previous <= 0 && $current > 0) {
            return 'Up 100.0% vs previous 30 days';
        }

        $delta = (($current - $previous) / $previous) * 100;
        $direction = $delta > 0 ? 'Up' : ($delta < 0 ? 'Down' : 'No change');
        $value = number_format(abs($delta), 1);

        return $direction . ' ' . $value . '% vs previous 30 days';
    }

    private function deltaIcon(float|int $current, float|int $previous): string
    {
        if ($current > $previous) {
            return 'heroicon-m-arrow-trending-up';
        }
        if ($current < $previous) {
            return 'heroicon-m-arrow-trending-down';
        }

        return 'heroicon-m-minus';
    }

    private function deltaColor(float|int $current, float|int $previous): string
    {
        if ($current > $previous) {
            return 'success';
        }
        if ($current < $previous) {
            return 'danger';
        }

        return 'gray';
    }
}
