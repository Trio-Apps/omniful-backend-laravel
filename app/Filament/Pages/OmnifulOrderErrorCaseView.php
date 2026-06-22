<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrder;
use App\Services\Webhooks\WebhookRetryService;
use App\Support\OrderErrorMonitoring;
use Filament\Notifications\Notification;
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

    /**
     * Re-queue a single errored order's full SAP flow. $cancelOld reverses the
     * existing invoice (credit memo + frees its order refs) then recreates clean.
     */
    public function resendOrder(string $externalId, bool $cancelOld = false): void
    {
        $externalId = trim($externalId);
        $order = OmnifulOrder::where('external_id', $externalId)->first();
        if (!$order) {
            Notification::make()->title('Order not found')->body($externalId)->danger()->send();

            return;
        }

        $result = app(WebhookRetryService::class)->forceResendOrder($order, $cancelOld);

        Notification::make()
            ->title(($result['ok'] ?? false) ? 'Resend queued' : 'Resend skipped')
            ->body((string) ($result['message'] ?? ''))
            ->{($result['ok'] ?? false) ? 'success' : 'warning'}()
            ->send();
    }

    /**
     * Re-queue every order currently listed in this case (after filters).
     */
    public function resendCaseOrders(bool $cancelOld = false): void
    {
        $retry = app(WebhookRetryService::class);
        $queued = 0;
        $skipped = 0;

        foreach ($this->orders as $row) {
            $externalId = trim((string) ($row['external_id'] ?? ''));
            if ($externalId === '') {
                $skipped++;
                continue;
            }
            $order = OmnifulOrder::where('external_id', $externalId)->first();
            if (!$order) {
                $skipped++;
                continue;
            }
            $result = $retry->forceResendOrder($order, $cancelOld);
            ($result['ok'] ?? false) ? $queued++ : $skipped++;
        }

        Notification::make()
            ->title('Resend queued for case')
            ->body('Queued ' . $queued . ' order(s)' . ($skipped > 0 ? ', skipped ' . $skipped : '')
                . ($cancelOld ? ' (cancel & reverse)' : ''))
            ->success()
            ->send();
    }

    public function getBreadcrumbs(): array
    {
        return [
            OmnifulOrderErrorMonitor::getUrl() => 'Order Errors',
            '#' => 'Error Case',
        ];
    }
}
