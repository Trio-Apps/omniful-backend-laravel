<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Orders', '1,248')
                ->description('Up 12% vs last week')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart([12, 18, 16, 22, 28, 25, 30])
                ->color('success'),
            Stat::make('Revenue', '$32.4k')
                ->description('Up 5.3% this month')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart([8, 10, 9, 12, 14, 13, 16])
                ->color('success'),
            Stat::make('Returns', '38')
                ->description('Down 2.1% this month')
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->chart([6, 5, 7, 4, 5, 3, 4])
                ->color('danger'),
            Stat::make('Active Customers', '842')
                ->description('Stable this week')
                ->descriptionIcon('heroicon-m-minus')
                ->chart([14, 14, 13, 14, 15, 14, 14])
                ->color('gray'),
        ];
    }
}
