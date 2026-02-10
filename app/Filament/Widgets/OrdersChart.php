<?php

namespace App\Filament\Widgets;

use App\Models\OmnifulOrder;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;

class OrdersChart extends ChartWidget
{
    protected ?string $heading = 'Orders (Last 14 Days)';

    protected ?string $description = 'Actual order events stored in Omniful Orders';

    protected string $color = 'primary';

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'md' => 6,
        'xl' => 6,
    ];

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $start = now()->startOfDay()->subDays(13);
        $end = now()->endOfDay();

        $rows = OmnifulOrder::query()
            ->whereBetween('last_event_at', [$start, $end])
            ->get(['last_event_at']);

        $daily = [];
        foreach (CarbonPeriod::create($start, '1 day', $end) as $day) {
            $daily[$day->toDateString()] = 0;
        }

        foreach ($rows as $row) {
            if (!$row->last_event_at) {
                continue;
            }
            $key = $row->last_event_at->toDateString();
            if (array_key_exists($key, $daily)) {
                $daily[$key]++;
            }
        }

        return [
            'labels' => array_map(
                fn (string $date) => Carbon::parse($date)->format('M d'),
                array_keys($daily)
            ),
            'datasets' => [
                [
                    'label' => 'Orders',
                    'data' => array_values($daily),
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
        ];
    }
}
