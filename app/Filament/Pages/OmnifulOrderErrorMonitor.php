<?php

namespace App\Filament\Pages;

use App\Support\OrderErrorMonitoring;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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
        $this->loadDashboard();
    }

    public function getTitle(): string
    {
        return 'Order Error Monitoring';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->loadDashboard()),
            Action::make('clearStaleErrors')
                ->label('Clear Resolved Errors')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Clear resolved order errors')
                ->modalDescription('Wipe stale *_error fields on orders whose corresponding *_doc_entry is already populated (operation actually succeeded after a previous retry but the message lingered). Active failures are not touched.')
                ->modalSubmitActionLabel('Clear stale errors')
                ->action(function () {
                    $cleared = app(OrderErrorMonitoring::class)->clearStaleErrors();
                    $this->loadDashboard();
                    Notification::make()
                        ->title('Stale errors cleared')
                        ->body('Cleared ' . number_format($cleared) . ' resolved error field(s) from orders that already completed successfully.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function loadDashboard(): void
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
}
