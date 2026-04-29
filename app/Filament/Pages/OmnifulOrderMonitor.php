<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrder;
use App\Filament\Pages\OmnifulOrderView;
use App\Filament\Pages\OmnifulOrderErrorMonitor;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OmnifulOrderMonitor extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Omniful Orders';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.omniful-order-monitor';

    public function getQueuedOrdersCount(): int
    {
        return (int) OmnifulOrder::query()
            ->whereIn('sap_status', ['pending', 'running', 'retrying'])
            ->count();
    }

    public function getQueuedTransactionsCount(): int
    {
        return (int) DB::table('jobs')
            ->where('queue', 'omniful-orders')
            ->count();
    }

    public function getFailedOrdersCount(): int
    {
        return (int) OmnifulOrder::query()
            ->where('sap_status', 'failed')
            ->count();
    }

    protected function getDefaultTableSortColumn(): ?string
    {
        return 'created_at';
    }

    protected function getDefaultTableSortDirection(): ?string
    {
        return 'desc';
    }

    protected function getTableQuery(): Builder
    {
        $query = OmnifulOrder::query();

        $sapStatusFilter = data_get($this, 'tableFilters.sap_status.values');
        if (!is_array($sapStatusFilter)) {
            $sapStatusFilter = (array) (data_get($this, 'tableFilters.sap_status.value')
                ?? data_get($this, 'tableFilters.sap_status')
                ?? []);
        }

        $normalizedStatuses = array_values(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $sapStatusFilter
        ), fn ($value) => $value !== ''));

        if (!in_array('ignored', $normalizedStatuses, true)) {
            $query->where(function (Builder $query) {
                $query->whereNull('sap_status')
                    ->orWhere('sap_status', '!=', 'ignored');
            });
        }

        return $query;
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('external_id')
                ->label('Order ID')
                ->searchable(),
            TextColumn::make('omniful_status')
                ->label('Omniful Status')
                ->badge()
                ->toggleable(),
            TextColumn::make('sap_status')
                ->label('SAP Status')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'created', 'updated', 'logged', 'created_mixed' => 'success',
                    'failed' => 'danger',
                    'ignored', 'blocked', 'pending', 'retrying', 'running' => 'warning',
                    default => 'gray',
                })
                ->toggleable(),
            TextColumn::make('sap_doc_num')
                ->label('SAP Order')
                ->toggleable(),
            TextColumn::make('sap_payment_status')
                ->label('Payment')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'created', 'updated', 'logged' => 'success',
                    'failed' => 'danger',
                    'ignored', 'blocked', 'pending', 'retrying' => 'warning',
                    default => 'gray',
                })
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('sap_delivery_status')
                ->label('Delivery')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'created', 'updated', 'logged' => 'success',
                    'failed' => 'danger',
                    'ignored', 'blocked', 'pending', 'retrying' => 'warning',
                    default => 'gray',
                })
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('last_event_type')
                ->label('Last Event')
                ->toggleable(),
            TextColumn::make('created_at')
                ->label('Created At')
                ->dateTime()
                ->sortable(),
            TextColumn::make('last_event_at')
                ->label('Last Event At')
                ->dateTime()
                ->sortable(),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            SelectFilter::make('omniful_status')
                ->label('Omniful Status')
                ->options(fn () => OmnifulOrder::query()
                    ->whereNotNull('omniful_status')
                    ->where('omniful_status', '!=', '')
                    ->distinct()
                    ->orderBy('omniful_status')
                    ->pluck('omniful_status', 'omniful_status')
                    ->all())
                ->multiple()
                ->searchable(),
            SelectFilter::make('sap_status')
                ->label('SAP Status')
                ->options(fn () => OmnifulOrder::query()
                    ->whereNotNull('sap_status')
                    ->where('sap_status', '!=', '')
                    ->distinct()
                    ->orderBy('sap_status')
                    ->pluck('sap_status', 'sap_status')
                    ->all())
                ->multiple()
                ->searchable(),
            Filter::make('stuck')
                ->label('SAP Pending')
                ->query(fn (Builder $query) => $query->where(function (Builder $query) {
                    $query->whereNull('sap_status')
                        ->orWhere('sap_status', '')
                        ->orWhere('sap_status', 'pending');
                })),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('view')
                ->label('View')
                ->icon('heroicon-o-eye')
                ->url(fn ($record) => OmnifulOrderView::getUrl(['record' => $record])),
            Action::make('retrySap')
                ->label('Retry SAP')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->sap_error || empty($record->sap_status) || in_array((string) $record->sap_status, ['failed', 'retrying'], true))
                ->action(function ($record) {
                    $result = app(WebhookRetryService::class)->retryLatestOrderEventForOrder($record);
                    Notification::make()
                        ->title($result['ok'] ? 'Retry queued' : 'Retry failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
            Action::make('sapError')
                ->label('SAP Error')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn ($record) => (bool) $record->sap_error)
                ->modalHeading('SAP Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.sap-sync-error', [
                    'error' => $record->sap_error,
                ])),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('errorMonitoring')
                ->label('Error Monitoring')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('gray')
                ->url(OmnifulOrderErrorMonitor::getUrl()),
            Action::make('retryFailedOrders')
                ->label('Retry Failed Orders')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => $this->getFailedOrdersCount() > 0)
                ->requiresConfirmation()
                ->modalHeading('Retry Failed Orders')
                ->modalDescription(fn () => 'This will queue retry for ' . number_format($this->getFailedOrdersCount()) . ' failed order(s).')
                ->modalSubmitActionLabel('Retry Failed Orders')
                ->action(function () {
                    $retryService = app(WebhookRetryService::class);
                    $queued = 0;
                    $failed = 0;

                    OmnifulOrder::query()
                        ->where('sap_status', 'failed')
                        ->orderBy('id')
                        ->chunkById(100, function ($orders) use ($retryService, &$queued, &$failed) {
                            foreach ($orders as $order) {
                                $result = $retryService->retryLatestOrderEventForOrder($order);
                                if ($result['ok']) {
                                    $queued++;
                                } else {
                                    $failed++;
                                }
                            }
                        });

                    Notification::make()
                        ->title('Failed order retry finished')
                        ->body('Queued: ' . number_format($queued) . ($failed > 0 ? ' | Skipped: ' . number_format($failed) : ''))
                        ->success()
                        ->send();
                }),
            Action::make('resetOrdersAndQueue')
                ->label('Reset Orders & Queue')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete All Orders And Reset Queue')
                ->modalDescription('This will stop queue workers gracefully, delete all Omniful orders/events, clear pending and failed jobs for queue "omniful-orders", then trigger workers restart.')
                ->modalSubmitActionLabel('Delete And Reset')
                ->form([
                    TextInput::make('confirm_text')
                        ->label('Type RESET to confirm')
                        ->required()
                        ->rules(['in:RESET']),
                ])
                ->action(function () {
                    try {
                        // Ask active workers to stop gracefully before cleanup.
                        Artisan::call('queue:restart');

                        DB::transaction(function () {
                            DB::table('omniful_orders')->delete();
                            DB::table('omniful_order_events')->delete();
                            DB::table('jobs')->where('queue', 'omniful-orders')->delete();
                            if (Schema::hasTable('failed_jobs')) {
                                DB::table('failed_jobs')->where('queue', 'omniful-orders')->delete();
                            }
                        });

                        // Trigger workers restart after cleanup (for supervisor-managed workers).
                        Artisan::call('queue:restart');

                        Notification::make()
                            ->title('Orders and queue reset completed')
                            ->body('All Omniful orders/events were deleted and queue "omniful-orders" was cleared. Workers received restart signal.')
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Reset failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
