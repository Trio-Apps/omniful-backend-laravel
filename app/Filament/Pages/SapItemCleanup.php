<?php

namespace App\Filament\Pages;

use App\Models\SapCleanupTarget;
use App\Models\SapSyncEvent;
use App\Services\SapItemCleanupService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Persistent worklist for reversing AR Reserve Invoices created for wrongly
 * auto-created items. Scan adds rows (read-only); each row can be Checked
 * (re-read live), Cancelled (reversed + order re-queued), or Resent (Force
 * Resend). Bulk actions and "Cancel all" run in the background.
 */
class SapItemCleanup extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'SAP Item Cleanup';

    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.sap-item-cleanup';

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

    private function cleanup(): SapItemCleanupService
    {
        return app(SapItemCleanupService::class);
    }

    /**
     * Comma-joined document numbers for a related-docs group (payments /
     * deliveries / cogs_journals); a cancelled doc is suffixed with "(x)".
     */
    private function relatedNumbers(SapCleanupTarget $record, string $key): string
    {
        $items = (array) (($record->related[$key] ?? []));
        $nums = [];
        foreach ($items as $item) {
            $num = $key === 'cogs_journals'
                ? (string) ($item['number'] ?? $item['jdt_num'] ?? '')
                : (string) ($item['doc_num'] ?? '');
            if ($num !== '') {
                $nums[] = $num . (!empty($item['cancelled']) ? ' (x)' : '');
            }
        }

        return $nums === [] ? '—' : implode(', ', $nums);
    }

    protected function getTableQuery(): Builder
    {
        return SapCleanupTarget::query()->latest('id');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('doc_num')->label('Invoice')->searchable()->sortable(),
            TextColumn::make('payment')
                ->label('Payment')
                ->getStateUsing(fn (SapCleanupTarget $record): string => $this->relatedNumbers($record, 'payments'))
                ->placeholder('—'),
            TextColumn::make('delivery')
                ->label('Delivery')
                ->getStateUsing(fn (SapCleanupTarget $record): string => $this->relatedNumbers($record, 'deliveries'))
                ->placeholder('—'),
            TextColumn::make('cogs')
                ->label('COGS')
                ->getStateUsing(fn (SapCleanupTarget $record): string => $this->relatedNumbers($record, 'cogs_journals'))
                ->placeholder('—'),
            TextColumn::make('order_external_id')->label('Order')->searchable(),
            TextColumn::make('cleanup_state')
                ->label('State')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'reversed' => 'info',
                    'resent' => 'success',
                    'failed' => 'danger',
                    'skipped' => 'warning',
                    default => 'gray',
                }),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('details')
                ->label('Details')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading(fn (SapCleanupTarget $record) => 'Invoice DocNum ' . $record->doc_num . ' — documents')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn (SapCleanupTarget $record) => view('filament.pages.sap-cleanup-target-details', [
                    'target' => $record,
                ])),

            Action::make('check')
                ->label('Check')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (SapCleanupTarget $record): void {
                    $res = $this->cleanup()->checkTarget($record);
                    Notification::make()
                        ->title('Checked DocNum ' . $record->doc_num)
                        ->body('SAP status: ' . ($res['status'] ?? ($res['reason'] ?? '—')))
                        ->{!empty($res['ok']) ? 'success' : 'warning'}()
                        ->send();
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Reverse this invoice on LIVE SAP')
                ->modalDescription('Cancels the payment + delivery, posts a credit note + COGS reversal, and stamps "-0reversed".')
                ->form([
                    Toggle::make('requeue')
                        ->label('Re-queue order for re-send to SAP')
                        ->default(true),
                ])
                ->action(function (array $data, SapCleanupTarget $record): void {
                    $res = $this->cleanup()->cancelTarget($record, (bool) ($data['requeue'] ?? true));
                    Notification::make()
                        ->title(!empty($res['ok']) ? 'Invoice reversed' : 'Reversal failed')
                        ->body(!empty($res['ok'])
                            ? ('DocNum ' . $record->doc_num . (!empty($res['skipped']) ? ' (already reversed)' : ''))
                            : (string) ($res['reason'] ?? ''))
                        ->{!empty($res['ok']) ? 'success' : 'danger'}()
                        ->send();
                }),

            Action::make('resend')
                ->label('Resend')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->visible(fn (SapCleanupTarget $record) => filled($record->order_external_id))
                ->requiresConfirmation()
                ->modalHeading('Re-send this order to SAP')
                ->modalDescription('Queues a clean Force Resend (assumes the old documents were already reversed).')
                ->action(function (SapCleanupTarget $record): void {
                    $res = $this->cleanup()->resendTarget($record);
                    Notification::make()
                        ->title(!empty($res['ok']) ? 'Re-send queued' : 'Re-send failed')
                        ->body((string) ($res['message'] ?? $res['reason'] ?? ''))
                        ->{!empty($res['ok']) ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }

    protected function getTableBulkActions(): array
    {
        return [
            BulkAction::make('checkSelected')
                ->label('Check selected')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->deselectRecordsAfterCompletion()
                ->action(fn (Collection $records) => $this->queueBulk('check', $records->pluck('id')->all())),

            BulkAction::make('cancelSelected')
                ->label('Cancel selected (reverse)')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Reverse selected invoices on LIVE SAP')
                ->form([
                    Toggle::make('requeue')
                        ->label('Re-queue orders for re-send to SAP')
                        ->default(true),
                ])
                ->deselectRecordsAfterCompletion()
                ->action(fn (array $data, Collection $records) => $this->queueBulk('cancel', $records->pluck('id')->all(), (bool) ($data['requeue'] ?? true))),

            BulkAction::make('resendSelected')
                ->label('Resend selected')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Re-send selected orders to SAP')
                ->deselectRecordsAfterCompletion()
                ->action(fn (Collection $records) => $this->queueBulk('resend', $records->pluck('id')->all())),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')
                ->label('Scan & add')
                ->icon('heroicon-o-magnifying-glass')
                ->color('primary')
                ->form([
                    Select::make('mode')
                        ->label('Search by')
                        ->options(self::modeOptions())
                        ->default('product_id')
                        ->required(),
                    TextInput::make('value')
                        ->label('Value')
                        ->helperText('Product/item code, OR a SAP invoice DocNum, OR an Omniful order id — matching the chosen mode. Read-only; adds matches to the list below.')
                        ->required(),
                ])
                ->action(fn (array $data) => $this->scanTargets($data)),

            Action::make('cancelAll')
                ->label('Cancel all')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => SapCleanupTarget::query()->whereNotIn('cleanup_state', ['reversed', 'resent'])->exists())
                ->requiresConfirmation()
                ->modalHeading('Reverse ALL not-yet-reversed targets on LIVE SAP')
                ->modalDescription('Runs in the background for every row whose state is not reversed/resent.')
                ->form([
                    Toggle::make('requeue')
                        ->label('Re-queue orders for re-send to SAP')
                        ->default(true),
                ])
                ->action(fn (array $data) => $this->cancelAll((bool) ($data['requeue'] ?? true))),

            Action::make('clearList')
                ->label('Clear list')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Clear the cleanup worklist')
                ->modalDescription('Removes all rows from this list. SAP is not touched.')
                ->action(fn () => $this->clearList()),

            Action::make('stopCleanup')
                ->label('Stop')
                ->icon('heroicon-o-stop')
                ->color('warning')
                ->visible(fn (): bool => $this->getCleanupPanel()['can_stop'] ?? false)
                ->requiresConfirmation()
                ->action(fn () => $this->cancelCleanup()),
        ];
    }

    public function scanTargets(array $data): void
    {
        $mode = (string) ($data['mode'] ?? '');
        $value = (string) ($data['value'] ?? '');

        if (!in_array($mode, SapItemCleanupService::MODES, true)) {
            Notification::make()->title('Invalid mode')->danger()->send();

            return;
        }

        $r = $this->cleanup()->scanAndAdd($mode, $value);
        Notification::make()
            ->title('Scan complete')
            ->body('Found ' . $r['found'] . ' · added ' . $r['added'] . ' · updated ' . $r['updated'])
            ->{$r['rows'] > 0 ? 'success' : 'warning'}()
            ->send();
    }

    public function cancelAll(bool $requeue = true): void
    {
        $ids = SapCleanupTarget::query()
            ->whereNotIn('cleanup_state', ['reversed', 'resent'])
            ->pluck('id')
            ->all();

        $this->queueBulk('cancel', $ids, $requeue);
    }

    public function clearList(): void
    {
        $count = SapCleanupTarget::query()->count();
        SapCleanupTarget::query()->delete();

        Notification::make()
            ->title('Worklist cleared')
            ->body($count . ' row(s) removed.')
            ->success()
            ->send();
    }

    private function queueBulk(string $action, array $ids, bool $requeue = true): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            Notification::make()->title('Nothing to process')->warning()->send();

            return;
        }

        $result = $this->cleanup()->dispatchBulk($action, $ids, $requeue, 'sap_item_cleanup_page');
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
            ->title(ucfirst($action) . ' queued for ' . count($ids) . ' row(s)')
            ->body('Running in the background. Event: ' . $event->event_key)
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
            ->body('The cleanup will stop after the current row finishes.')
            ->success()
            ->send();
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
