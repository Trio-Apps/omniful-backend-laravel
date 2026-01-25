<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class OrdersChart extends ChartWidget
{
    protected ?string $heading = 'Orders (Last 14 Days)';

    protected ?string $description = 'Sample data for the dashboard';

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
        return [
            'labels' => [
                'Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Day 7',
                'Day 8', 'Day 9', 'Day 10', 'Day 11', 'Day 12', 'Day 13', 'Day 14',
            ],
            'datasets' => [
                [
                    'label' => 'Orders',
                    'data' => [
                        42, 58, 51, 67, 80, 73, 92,
                        88, 95, 110, 104, 120, 133, 128,
                    ],
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
        ];
    }
}
