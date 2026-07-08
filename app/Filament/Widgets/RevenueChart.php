<?php

namespace App\Filament\Widgets;

use App\Support\DashboardMetrics;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class RevenueChart extends ChartWidget
{
    protected ?string $heading = 'Revenue by Payment Method (Last 14 Days)';

    protected ?string $description = 'Actual revenue extracted from Omniful order payloads';

    protected string $color = 'success';

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'md' => 6,
        'xl' => 6,
    ];

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        // Read the pre-computed totals only — the payload scan runs off the web
        // request in the scheduled `dashboard:cache-metrics` command. A cold
        // cache renders an empty "No Data" chart instantly instead of timing out.
        $totals = Cache::get(DashboardMetrics::REVENUE_KEY) ?? [];

        arsort($totals);
        $top = array_slice($totals, 0, 7, true);
        if ($top === []) {
            $top = ['no_data' => 0];
        }

        return [
            'labels' => array_map(
                fn (string $k) => $k === 'no_data' ? 'No Data' : strtoupper($k),
                array_keys($top)
            ),
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => array_values(array_map(fn ($v) => round((float) $v, 2), $top)),
                    'borderRadius' => 6,
                ],
            ],
        ];
    }
}
