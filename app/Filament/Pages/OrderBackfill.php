<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrderBackfillRun;
use App\Services\OmnifulOrderBackfillService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\WithFileUploads;

class OrderBackfill extends Page
{
    use WithFileUploads;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static ?string $navigationLabel = 'Order Backfill';

    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.order-backfill';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    /** Uploaded order-id list (xlsx / csv / txt) for an id_list backfill. */
    public $idFile = null;

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

    public function startIdList(): void
    {
        if (!$this->idFile) {
            Notification::make()->title('Upload an order-id file first')->warning()->send();

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

        try {
            $ids = $this->extractOrderIds(
                $this->idFile->getRealPath(),
                (string) $this->idFile->getClientOriginalName()
            );
        } catch (\Throwable $e) {
            Notification::make()->title('Could not read the file')->body($e->getMessage())->danger()->send();

            return;
        }

        if ($ids === []) {
            Notification::make()
                ->title('No order ids found')
                ->body('Expected numeric order ids (6+ digits) in the file.')
                ->warning()
                ->send();

            return;
        }

        $run = app(OmnifulOrderBackfillService::class)
            ->startIdListRun($ids, (string) $this->idFile->getClientOriginalName());
        $this->idFile = null;

        Notification::make()
            ->title('ID backfill started')
            ->body(number_format(count($ids)) . ' distinct order ids queued for pull (already-present + no-op are skipped).')
            ->success()
            ->send();
    }

    /**
     * Extract distinct numeric order ids (6+ digits) from an uploaded xlsx / csv /
     * txt file. For xlsx it streams every cell of every sheet (handles the 5-column
     * "pending orders" export without hard-coding columns); csv/txt is regex-scanned.
     *
     * @return array<int,string>
     */
    private function extractOrderIds(string $path, string $name): array
    {
        @set_time_limit(300);
        $ids = [];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($ext, ['xlsx', 'xlsm'], true)) {
            $reader = new \OpenSpout\Reader\XLSX\Reader();
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $v = trim((string) $cell->getValue());
                        if (strlen($v) >= 6 && ctype_digit($v)) {
                            $ids[$v] = true;
                        }
                    }
                }
            }
            $reader->close();
        } else {
            $content = (string) file_get_contents($path);
            if (preg_match_all('/\b\d{6,}\b/', $content, $m)) {
                foreach ($m[0] as $v) {
                    $ids[$v] = true;
                }
            }
        }

        return array_keys($ids);
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
            'skipped' => (int) $d->skipped,
            'enqueued' => (int) $d->enqueued,
        ])->all();

        return [
            'has_run' => true,
            'id' => $run->id,
            'status' => $run->status,
            'status_label' => ucwords(str_replace('_', ' ', (string) $run->status)),
            'tone' => $this->tone((string) $run->status),
            'is_active' => $run->isActive(),
            'source_type' => (string) ($run->source_type ?? 'date_range'),
            'source_label' => $run->source_label,
            'range' => (string) ($run->source_type ?? 'date_range') === 'id_list'
                ? (string) ($run->source_label ?? 'Order id list')
                : $run->date_from->format('Y-m-d') . ' → ' . $run->date_to->format('Y-m-d'),
            'scanned' => (int) $run->scanned,
            'existing' => (int) $run->existing,
            'missing' => (int) $run->missing,
            'skipped' => (int) $run->skipped,
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
