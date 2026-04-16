<?php

namespace App\Filament\Pages;

use App\Support\OrderErrorMonitoring;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OmnifulOrderErrorCaseView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected Width | string | null $maxContentWidth = 'full';

    protected string $view = 'filament.pages.omniful-order-error-case-view';

    public string $fingerprint = '';

    public string $message = '';

    public string $stage = '';

    public string $sku = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public array $summary = [];

    public array $orders = [];

    public array $topItems = [];

    public array $stageBreakdown = [];

    public array $dailyBreakdown = [];

    public function mount(?string $fingerprint = null): void
    {
        $this->fingerprint = trim((string) ($fingerprint ?? request()->query('fingerprint', '')));
        if ($this->fingerprint === '') {
            throw new ModelNotFoundException();
        }

        $this->stage = trim((string) request()->query('stage', ''));
        $this->sku = trim((string) request()->query('sku', ''));
        $this->dateFrom = trim((string) request()->query('date_from', ''));
        $this->dateTo = trim((string) request()->query('date_to', ''));

        $monitor = app(OrderErrorMonitoring::class);
        $detail = $monitor->buildCaseDetail(
            $monitor->loadErroredOrders(),
            $this->fingerprint,
            [
                'stage' => $this->stage,
                'sku' => $this->sku,
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
            ],
        );

        $this->message = $detail['message'];
        $this->summary = $detail['summary'];
        $this->orders = $detail['orders'];
        $this->topItems = $detail['top_items'];
        $this->stageBreakdown = $detail['stage_breakdown'];
        $this->dailyBreakdown = $detail['daily_breakdown'];
    }

    public function getTitle(): string
    {
        return 'Error Case';
    }

    public function getBreadcrumbs(): array
    {
        return [
            OmnifulOrderErrorMonitor::getUrl() => 'Order Errors',
            '#' => 'Error Case',
        ];
    }
}
