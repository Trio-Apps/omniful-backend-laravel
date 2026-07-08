<?php

namespace App\Filament\Widgets;

use App\Support\DashboardMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class OverviewStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        // Read the pre-computed metrics only. The heavy payload scan runs off the
        // web request in the scheduled `dashboard:cache-metrics` command, so this
        // widget never blocks. On a cold cache we fall back to instant indexed
        // counts (revenue/customers fill in once the scheduler warms the cache),
        // which avoids both the OOM 500 and the nginx 504 the inline scan caused.
        $data = Cache::get(DashboardMetrics::OVERVIEW_KEY)
            ?? app(DashboardMetrics::class)->overviewCounts();

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
