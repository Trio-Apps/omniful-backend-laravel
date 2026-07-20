<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrderBackfillRun;
use App\Services\OmnifulOrderBackfillService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderBackfill extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static ?string $navigationLabel = 'Order Backfill';

    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.order-backfill';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public function mount(): void
    {
        $this->dateFrom = now()->subDay()->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
    }

    public function getTitle(): string
    {
        return 'Omniful → Order Backfill';
    }

    public function start(): void
    {
        $from = trim((string) $this->dateFrom);
        $to = trim((string) $this->dateTo);

        if ($from === '' || $to === '') {
            Notification::make()->title('Pick both dates')->warning()->send();

            return;
        }
        try {
            Carbon::parse($from);
            Carbon::parse($to);
        } catch (\Throwable) {
            Notification::make()->title('Invalid date')->danger()->send();

            return;
        }

        if (OmnifulOrderBackfillRun::whereIn('status', ['queued', 'running', 'cancel_requested'])->exists()) {
            Notification::make()
                ->title('A backfill is already running')
                ->body('Wait for it to finish or cancel it first.')
                ->warning()
                ->send();

            return;
        }

        $run = app(OmnifulOrderBackfillService::class)->startRun($from, $to);

        Notification::make()
            ->title('Backfill started')
            ->body('Range ' . $run->date_from->format('Y-m-d') . ' → ' . $run->date_to->format('Y-m-d'))
            ->success()
            ->send();
    }

    public function cancelRun(): void
    {
        $run = OmnifulOrderBackfillRun::whereIn('status', ['queued', 'running', 'cancel_requested'])
            ->latest('id')
            ->first();

        if ($run === null) {
            Notification::make()->title('No active backfill')->warning()->send();

            return;
        }

        app(OmnifulOrderBackfillService::class)->requestCancel($run);

        Notification::make()
            ->title('Cancel requested')
            ->body('It will stop after the current page finishes.')
            ->success()
            ->send();
    }

    /**
     * @return array<string,mixed>
     */
    public function getPanel(): array
    {
        $run = OmnifulOrderBackfillRun::latest('id')->first();
        if ($run === null) {
            return ['has_run' => false];
        }

        $days = $run->days()->get()->map(fn (\App\Models\OmnifulOrderBackfillDay $d) => [
            'day' => $d->day->format('Y-m-d'),
            'total' => (int) $d->total,
            'existing' => (int) $d->existing,
            'missing' => (int) $d->missing,
            'enqueued' => (int) $d->enqueued,
        ])->all();

        return [
            'has_run' => true,
            'id' => $run->id,
            'status' => $run->status,
            'status_label' => ucwords(str_replace('_', ' ', (string) $run->status)),
            'tone' => $this->tone((string) $run->status),
            'is_active' => $run->isActive(),
            'range' => $run->date_from->format('Y-m-d') . ' → ' . $run->date_to->format('Y-m-d'),
            'scanned' => (int) $run->scanned,
            'existing' => (int) $run->existing,
            'missing' => (int) $run->missing,
            'enqueued' => (int) $run->enqueued,
            'pages' => (int) $run->pages,
            'rate_limit_hits' => (int) $run->rate_limit_hits,
            'last_activity' => $run->last_activity,
            'last_error' => $run->last_error,
            'started_at' => $run->started_at?->toDateTimeString(),
            'finished_at' => $run->finished_at?->toDateTimeString(),
            'days' => $days,
            'queue_pending' => $this->targetQueueDepth(),
        ];
    }

    private function targetQueueDepth(): int
    {
        try {
            return (int) DB::table('jobs')
                ->where('queue', (string) config('omniful.order_backfill.target_queue', 'omniful-orders'))
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function tone(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'queued', 'running', 'cancel_requested' => 'warning',
            'cancelled' => 'gray',
            'failed' => 'danger',
            default => 'gray',
        };
    }
}
