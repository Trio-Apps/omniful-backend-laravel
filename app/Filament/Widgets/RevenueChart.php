<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class RevenueChart extends ChartWidget
{
    protected ?string $heading = 'Revenue by Channel';

    protected ?string $description = 'Sample data for the dashboard';

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
        return [
            'labels' => ['Direct', 'Marketplace', 'Wholesale', 'Retail', 'Affiliate'],
            'datasets' => [
                [
                    'label' => 'Revenue ($k)',
                    'data' => [18.2, 12.4, 9.6, 14.1, 6.8],
                    'borderRadius' => 6,
                ],
            ],
        ];
    }
}
