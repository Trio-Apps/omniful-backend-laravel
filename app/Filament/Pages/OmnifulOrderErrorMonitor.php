<?php

namespace App\Filament\Pages;

use App\Support\OrderErrorMonitoring;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;

class OmnifulOrderErrorMonitor extends Page
{
    protected Width | string | null $maxContentWidth = 'full';

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
        $monitor = app(OrderErrorMonitoring::class);
        $orders = $monitor->loadErroredOrders();
        $this->errorCases = $monitor->buildErrorCases($orders);
        $this->errorCaseLabels = collect($this->errorCases)
            ->mapWithKeys(fn (array $case) => [$case['fingerprint'] => $case['message']])
            ->all();
        $this->topErrorItems = $monitor->buildTopErrorItems($orders);
        $this->summaryCards = $monitor->buildSummaryCards($orders, $this->errorCases, $this->topErrorItems);
    }

    public function getTitle(): string
    {
        return 'Order Error Monitoring';
    }
}
