<?php

namespace App\Filament\Pages;

use App\Models\SapSyncEvent;
use App\Models\SapSupplier;
use App\Services\IntegrationDirectionService;
use App\Services\SapSupplierBackgroundPushService;
use App\Services\SapSupplierBackgroundSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class SapSuppliers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'SAP Suppliers';

    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.sap-suppliers';

    public static function shouldRegisterNavigation(): bool
    {
        // Supplier sync is stopped — hide the page from the menu. It reappears
        // if the supplier sync is re-enabled (OMNIFUL_SYNC_SUPPLIERS_ENABLED).
        return app(IntegrationDirectionService::class)->isDomainEnabled('suppliers');
    }

    protected function getTableQuery(): Builder
    {
        return SapSupplier::query()->orderBy('code');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('code')->label('Code')->searchable(),
            TextColumn::make('name')->label('Name')->searchable(),
            TextColumn::make('email')->label('Email')->searchable(),
            TextColumn::make('phone')->label('Phone')->searchable(),
            TextColumn::make('status')
                ->label('Status')
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

        $actions[] = Action::make('syncSuppliers')
            ->label('Pull from SAP')
            ->icon('heroicon-o-arrow-down-tray')
            ->extraAttributes([
                'wire:loading.attr' => 'disabled',
                'wire:loading.class' => 'opacity-70',
            ])
            ->action('queueSupplierSync');

        $actions[] = Action::make('pushSuppliers')
            ->label('Push to Omniful')
            ->icon('heroicon-o-cloud-arrow-up')
            ->color('warning')
            ->extraAttributes([
                'wire:loading.attr' => 'disabled',
                'wire:loading.class' => 'opacity-70',
            ])
            ->action('queueSupplierPush');

        $actions[] = Action::make('clearSuppliers')
            ->label('Clear list')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Clear local SAP suppliers')
            ->modalDescription('Deletes all rows from the local SAP suppliers mirror on this page. SAP and Omniful are not touched. You can re-pull anytime.')
            ->modalSubmitActionLabel('Clear')
            ->action(function (): void {
                $count = SapSupplier::query()->count();
                SapSupplier::query()->delete();
                Notification::make()
                    ->title('Local suppliers cleared')
                    ->body($count . ' row(s) removed from the local mirror.')
                    ->success()
                    ->send();
            });

        return $actions;
    }

    public function queueSupplierPush(SapSupplierBackgroundPushService $dispatcher): void
    {
        $result = $dispatcher->dispatch('sap_suppliers_page');
        $event = $result['event'];

        if ((bool) $result['already_running']) {
            Notification::make()
                ->title('Supplier push already queued')
                ->body('Current event: ' . $event->event_key)
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Supplier push to Omniful queued')
            ->body('Background job queued: ' . $event->event_key)
            ->success()
            ->send();
    }

    public function queueSupplierSync(SapSupplierBackgroundSyncService $dispatcher): void
    {
        $result = $dispatcher->dispatch('sap_suppliers_page');

        if ((bool) ($result['disabled'] ?? false)) {
            Notification::make()
                ->title('Supplier sync is disabled')
                ->body('Only the Warehouse sync is active. Supplier sync is turned off.')
                ->warning()
                ->send();

            return;
        }

        $event = $result['event'];

        if ((bool) $result['already_running']) {
            Notification::make()
                ->title('Supplier sync already queued')
                ->body('Current event: ' . $event->event_key)
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Supplier sync queued')
            ->body('Background job queued: ' . $event->event_key)
            ->success()
            ->send();
    }

    public function getSupplierSyncPanel(): array
    {
        return $this->buildPanel('sap_suppliers');
    }

    public function getSupplierPushPanel(): array
    {
        return $this->buildPanel('omniful_suppliers_push');
    }

    public function cancelSupplierPush(): void
    {
        $event = SapSyncEvent::query()
            ->where('source_type', 'omniful_suppliers_push')
            ->whereIn('sap_status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($event === null) {
            Notification::make()
                ->title('No running supplier push')
                ->body('There is no active background supplier push to stop.')
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
            ->body('The current supplier push will stop after the current record finishes.')
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
