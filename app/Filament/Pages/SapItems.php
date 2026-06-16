<?php

namespace App\Filament\Pages;

use App\Models\SapItem;
use App\Models\SapSyncEvent;
use App\Services\IntegrationDirectionService;
use App\Services\MasterData\SapItemIntegrationService;
use App\Services\SapItemBackgroundPushService;
use App\Services\SapItemBackgroundSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class SapItems extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'SAP Items';

    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.sap-items';

    public static function shouldRegisterNavigation(): bool
    {
        // Item sync is stopped — hide the page from the menu. It reappears
        // automatically if the item sync is re-enabled (OMNIFUL_SYNC_ITEMS_ENABLED).
        return app(IntegrationDirectionService::class)->isDomainEnabled('items');
    }

    protected function getTableQuery(): Builder
    {
        return SapItem::query()->orderBy('code');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('code')->label('Code')->searchable(),
            TextColumn::make('name')->label('Name')->searchable(),
            TextColumn::make('uom_group_entry')->label('UoM Group'),
            TextColumn::make('status')
                ->label('SAP')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'synced' => 'success',
                    'failed' => 'danger',
                    default => 'gray',
                }),
            TextColumn::make('omniful_status')
                ->label('Omniful')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'synced' => 'success',
                    'failed' => 'danger',
                    'syncing' => 'warning',
                    'pending' => 'warning',
                    default => 'gray',
                }),
            TextColumn::make('synced_at')->label('Synced')->dateTime(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('payload')
                ->label('Payload')
                ->icon('heroicon-o-code-bracket')
                ->color('info')
                ->modalHeading('Omniful Payload Preview')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(function ($record) {
                    // Prefer the exact payload/response captured during the last
                    // push; fall back to a live preview when the item hasn't been
                    // pushed yet (no response available in that case).
                    if (is_array($record->omniful_payload)) {
                        $sent = $record->omniful_payload;
                        $preview = [
                            'type' => isset($sent['child_skus']) ? 'kit' : 'sku',
                            'resource' => isset($sent['child_skus']) ? 'kits' : 'items',
                            'payload' => $sent,
                            'note' => null,
                        ];
                    } else {
                        $raw = is_array($record->payload) ? $record->payload : [];
                        if (trim((string) ($raw['ItemCode'] ?? '')) === '') {
                            $raw['ItemCode'] = $record->code;
                        }
                        $preview = app(SapItemIntegrationService::class)->previewPayload($raw);
                    }

                    return view('filament.pages.sap-item-payload', [
                        'code' => $record->code,
                        'preview' => $preview,
                        'response' => $record->omniful_response,
                        'responseCode' => $record->omniful_response_code,
                        'syncedAt' => $record->omniful_synced_at,
                    ]);
                }),
            Action::make('error')
                ->label('Reason')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->error)
                ->modalHeading('Sync Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.sap-sync-error', [
                    'error' => $record->error,
                ])),
            Action::make('omnifulError')
                ->label('Omniful Error')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->omniful_error)
                ->modalHeading('Omniful Sync Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.sap-sync-error', [
                    'error' => $record->omniful_error,
                ])),
        ];
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('openCatalog')
                ->label('Open SAP Catalog')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->url(SapCatalogOverview::getUrl()),
        ];

        $actions[] = Action::make('syncItems')
            ->label('Pull from SAP')
            ->icon('heroicon-o-arrow-down-tray')
            ->extraAttributes([
                'wire:loading.attr' => 'disabled',
                'wire:loading.class' => 'opacity-70',
            ])
            ->action('queueItemSync');

        $actions[] = Action::make('pushItems')
            ->label('Push to Omniful')
            ->icon('heroicon-o-cloud-arrow-up')
            ->color('warning')
            ->extraAttributes([
                'wire:loading.attr' => 'disabled',
                'wire:loading.class' => 'opacity-70',
            ])
            ->action('queueItemPush');

        $actions[] = Action::make('clearItems')
            ->label('Clear list')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Clear local SAP items')
            ->modalDescription('Deletes all rows from the local SAP items mirror on this page. SAP and Omniful are not touched. You can re-pull anytime.')
            ->modalSubmitActionLabel('Clear')
            ->action(function (): void {
                $count = SapItem::query()->count();
                SapItem::query()->delete();
                Notification::make()
                    ->title('Local items cleared')
                    ->body($count . ' row(s) removed from the local mirror.')
                    ->success()
                    ->send();
            });

        return $actions;
    }

    public function queueItemPush(SapItemBackgroundPushService $dispatcher): void
    {
        $result = $dispatcher->dispatch('sap_items_page');
        $event = $result['event'];

        if ((bool) $result['already_running']) {
            Notification::make()
                ->title('Item push already queued')
                ->body('Current event: ' . $event->event_key)
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Item push to Omniful queued')
            ->body('Classifying SKU/KIT and pushing pending items. Event: ' . $event->event_key)
            ->success()
            ->send();
    }

    public function queueItemSync(SapItemBackgroundSyncService $dispatcher): void
    {
        $result = $dispatcher->dispatch('sap_items_page');

        if ((bool) ($result['disabled'] ?? false)) {
            Notification::make()
                ->title('Item sync is disabled')
                ->body('Only the Warehouse sync is active. Item sync is turned off.')
                ->warning()
                ->send();

            return;
        }

        $event = $result['event'];

        if ((bool) $result['already_running']) {
            Notification::make()
                ->title('Item sync already queued')
                ->body('Current event: ' . $event->event_key)
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Item pull queued')
            ->body('Pulling not-yet-integrated SAP items (U_omInt=N) into the local list. Event: ' . $event->event_key)
            ->success()
            ->send();
    }

    public function getItemSyncPanel(): array
    {
        return $this->buildPanel('sap_items');
    }

    public function getItemPushPanel(): array
    {
        return $this->buildPanel('omniful_items_push');
    }

    public function cancelItemPush(): void
    {
        $event = SapSyncEvent::query()
            ->where('source_type', 'omniful_items_push')
            ->whereIn('sap_status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($event === null) {
            Notification::make()
                ->title('No running item push')
                ->body('There is no active background item push to stop.')
                ->warning()
                ->send();

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
            ->body('The current item push will stop after the current record finishes.')
            ->success()
            ->send();
    }

    private function buildPanel(string $sourceType): array
    {
        $event = SapSyncEvent::query()
            ->where('source_type', $sourceType)
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
