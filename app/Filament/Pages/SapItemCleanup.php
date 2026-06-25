<?php

namespace App\Filament\Pages;

use App\Models\SapSyncEvent;
use App\Services\SapItemCleanupService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Maintenance tool: reverse every AR Reserve Invoice tied to a wrongly
 * auto-created item — by product id, SAP doc number, or Omniful order id.
 * Always preview (read-only) first, then confirm to execute on LIVE SAP.
 */
class SapItemCleanup extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'SAP Item Cleanup';

    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.sap-item-cleanup';

    public ?string $cleanupMode = null;

    public ?string $cleanupValue = null;

    /** @var array<int,array<string,mixed>> */
    public array $previewRows = [];

    public bool $hasPreview = false;

    public bool $cleanupRequeue = true;

    /**
     * @return array<string,string>
     */
    public static function modeOptions(): array
    {
        return [
            'product_id' => 'Product / Item code',
            'sap_doc_number' => 'SAP invoice DocNum',
            'omniful_order_id' => 'Omniful order id',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview targets')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->form([
                    Select::make('mode')
                        ->label('Search by')
                        ->options(self::modeOptions())
                        ->default('product_id')
                        ->required(),
                    TextInput::make('value')
                        ->label('Value')
                        ->helperText('Product/item code, OR a SAP invoice DocNum, OR an Omniful order id — matching the chosen mode.')
                        ->required(),
                    Toggle::make('requeue')
                        ->label('Re-queue reversed orders for re-send to SAP')
                        ->helperText('After reversing, reset the matching Omniful orders to "pending" so they return to the In Queue list and can be re-sent (Force Resend). Turn off to only reverse in SAP.')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    $this->runPreview((string) $data['mode'], (string) $data['value'], (bool) ($data['requeue'] ?? true));
                }),

            Action::make('execute')
                ->label('Reverse previewed invoices')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => $this->hasPreview && $this->previewReversibleCount() > 0)
                ->requiresConfirmation()
                ->modalHeading('Reverse invoices on LIVE SAP')
                ->modalDescription(fn (): string => 'This will reverse '
                    . $this->previewReversibleCount() . ' invoice(s) on LIVE SAP: cancel each incoming payment + delivery, '
                    . 'create a credit note, post a COGS reversal, and stamp "-0reversed" on the order references. '
                    . 'This cannot be undone automatically.')
                ->modalSubmitActionLabel('Yes, reverse them')
                ->action(function (SapItemCleanupService $cleanup): void {
                    $this->runExecute($cleanup);
                }),

            Action::make('stopCleanup')
                ->label('Stop')
                ->icon('heroicon-o-stop')
                ->color('warning')
                ->visible(fn (): bool => $this->getCleanupPanel()['can_stop'] ?? false)
                ->requiresConfirmation()
                ->action(fn () => $this->cancelCleanup()),
        ];
    }

    public function runPreview(string $mode, string $value, bool $requeue = true): void
    {
        if (!in_array($mode, SapItemCleanupService::MODES, true)) {
            Notification::make()->title('Invalid mode')->danger()->send();

            return;
        }

        $cleanup = app(SapItemCleanupService::class);
        $this->cleanupMode = $mode;
        $this->cleanupValue = trim($value);
        $this->cleanupRequeue = $requeue;
        $this->previewRows = $cleanup->preview($mode, $this->cleanupValue);
        $this->hasPreview = true;

        $count = count($this->previewRows);
        Notification::make()
            ->title($count > 0 ? ($count . ' invoice(s) found') : 'No invoices found')
            ->body($count > 0
                ? 'Review the list below, then "Reverse previewed invoices" to execute.'
                : 'Nothing matches ' . $mode . ' = ' . $this->cleanupValue . '.')
            ->{$count > 0 ? 'success' : 'warning'}()
            ->send();
    }

    public function runExecute(SapItemCleanupService $cleanup): void
    {
        if (!$this->hasPreview || $this->cleanupMode === null || (string) $this->cleanupValue === '') {
            Notification::make()->title('Run a preview first')->warning()->send();

            return;
        }

        $result = $cleanup->dispatch($this->cleanupMode, (string) $this->cleanupValue, $this->cleanupRequeue, 'sap_item_cleanup_page');
        $event = $result['event'];

        if ((bool) $result['already_running']) {
            Notification::make()
                ->title('A cleanup is already running')
                ->body('Current event: ' . $event->event_key)
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Cleanup queued')
            ->body('Reversing the matched invoices in the background. Event: ' . $event->event_key)
            ->success()
            ->send();
    }

    public function cancelCleanup(): void
    {
        $event = SapSyncEvent::query()
            ->where('source_type', SapItemCleanupService::SOURCE_TYPE)
            ->whereIn('sap_status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($event === null) {
            Notification::make()->title('No running cleanup to stop')->warning()->send();

            return;
        }

        $payload = (array) ($event->payload ?? []);
        $event->update([
            'sap_status' => 'cancel_requested',
            'payload' => array_merge($payload, [
                'cancel_requested_at' => now()->toDateTimeString(),
            ]),
        ]);

        Notification::make()
            ->title('Stop requested')
            ->body('The cleanup will stop after the current invoice finishes.')
            ->success()
            ->send();
    }

    public function previewReversibleCount(): int
    {
        return count(array_filter($this->previewRows, fn ($row) => empty($row['already_reversed'])));
    }

    /**
     * @return array<string,mixed>
     */
    public function getCleanupPanel(): array
    {
        $event = SapSyncEvent::query()
            ->where('source_type', SapItemCleanupService::SOURCE_TYPE)
            ->latest('id')
            ->first();

        if ($event === null) {
            return [
                'has_event' => false,
                'status' => 'idle',
                'status_label' => 'Idle',
                'tone' => 'gray',
                'can_stop' => false,
                'event_key' => null,
                'requested_at' => null,
                'updated_at' => null,
                'summary_lines' => [],
                'error' => null,
            ];
        }

        $payload = (array) ($event->payload ?? []);
        $summary = (array) ($payload['summary'] ?? []);
        $summaryLines = [];
        foreach ($summary as $key => $value) {
            $summaryLines[] = ucwords(str_replace('_', ' ', (string) $key)) . ': ' . $value;
        }

        $status = (string) ($event->sap_status ?? 'unknown');

        return [
            'has_event' => true,
            'status' => $status,
            'status_label' => ucwords(str_replace('_', ' ', $status)),
            'tone' => $this->statusTone($status),
            'can_stop' => in_array($status, ['queued', 'running', 'cancel_requested'], true),
            'event_key' => $event->event_key,
            'requested_at' => $event->created_at?->toDateTimeString(),
            'updated_at' => $event->updated_at?->toDateTimeString(),
            'summary_lines' => $summaryLines,
            'error' => $event->sap_error,
        ];
    }

    private function statusTone(string $status): string
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
