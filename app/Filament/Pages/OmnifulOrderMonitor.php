<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrder;
use App\Filament\Pages\OmnifulOrderView;
use App\Filament\Pages\OmnifulOrderErrorMonitor;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
                ->searchable(query: function (Builder $query, string $search): Builder {
                    $like = '%' . $search . '%';

                    return $query->where(function (Builder $query) use ($like) {
                        $query->where('external_id', 'like', $like)
                            ->orWhere('sap_doc_num', 'like', $like)
                            ->orWhere('sap_doc_entry', 'like', $like)
                            ->orWhere('sap_payment_doc_num', 'like', $like)
                            ->orWhere('sap_payment_doc_entry', 'like', $like)
                            ->orWhere('sap_delivery_doc_num', 'like', $like)
                            ->orWhere('sap_delivery_doc_entry', 'like', $like)
                            ->orWhere('sap_credit_note_doc_num', 'like', $like)
                            ->orWhere('sap_credit_note_doc_entry', 'like', $like)
                            ->orWhere('sap_card_fee_journal_num', 'like', $like)
                            ->orWhere('sap_cogs_journal_num', 'like', $like)
                            ->orWhere('sap_cancel_cogs_journal_num', 'like', $like)
                            ->orWhere('last_payload', 'like', $like);
                    });
                }),
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
                ->searchable()
                ->toggleable(),
            TextColumn::make('sap_payment_doc_num')
                ->label('SAP Payment')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('sap_delivery_doc_num')
                ->label('SAP Delivery')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('sap_credit_note_doc_num')
                ->label('SAP Credit Note')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
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
            SelectFilter::make('last_event_type')
                ->label('Last Event')
                ->options(fn () => OmnifulOrder::query()
                    ->whereNotNull('last_event_type')
                    ->where('last_event_type', '!=', '')
                    ->distinct()
                    ->orderBy('last_event_type')
                    ->pluck('last_event_type', 'last_event_type')
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
            Filter::make('payload_search')
                ->label('Payload Search')
                ->form([
                    TextInput::make('invoice_number')
                        ->label('Invoice / Order Number')
                        ->placeholder('e.g. 69484010 or 5865815')
                        ->helperText('Searches SAP doc numbers and webhook payload.'),
                    TextInput::make('awb_number')
                        ->label('AWB / Tracking')
                        ->placeholder('e.g. 6051826258191'),
                    TextInput::make('hub_code')
                        ->label('Hub Code')
                        ->placeholder('e.g. CEN11'),
                    TextInput::make('seller_code')
                        ->label('Seller Code')
                        ->placeholder('e.g. PL-873'),
                    TextInput::make('payment_method')
                        ->label('Payment Method')
                        ->placeholder('e.g. zidpay, Tabby, prepaid, cod'),
                    TextInput::make('customer_contact')
                        ->label('Customer Email / Mobile')
                        ->placeholder('email or phone'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    $applyPayloadLike = function (Builder $query, ?string $value): void {
                        $value = trim((string) $value);
                        if ($value === '') {
                            return;
                        }
                        $query->where('last_payload', 'like', '%' . $value . '%');
                    };

                    $invoice = trim((string) ($data['invoice_number'] ?? ''));
                    if ($invoice !== '') {
                        $like = '%' . $invoice . '%';
                        $query->where(function (Builder $query) use ($like) {
                            $query->where('external_id', 'like', $like)
                                ->orWhere('sap_doc_num', 'like', $like)
                                ->orWhere('sap_doc_entry', 'like', $like)
                                ->orWhere('sap_payment_doc_num', 'like', $like)
                                ->orWhere('sap_payment_doc_entry', 'like', $like)
                                ->orWhere('sap_delivery_doc_num', 'like', $like)
                                ->orWhere('sap_delivery_doc_entry', 'like', $like)
                                ->orWhere('sap_credit_note_doc_num', 'like', $like)
                                ->orWhere('sap_credit_note_doc_entry', 'like', $like)
                                ->orWhere('sap_card_fee_journal_num', 'like', $like)
                                ->orWhere('sap_cogs_journal_num', 'like', $like)
                                ->orWhere('sap_cancel_cogs_journal_num', 'like', $like)
                                ->orWhere('sap_error', 'like', $like)
                                ->orWhere('last_payload', 'like', $like);
                        });
                    }

                    $applyPayloadLike($query, $data['awb_number'] ?? null);
                    $applyPayloadLike($query, $data['hub_code'] ?? null);
                    $applyPayloadLike($query, $data['seller_code'] ?? null);
                    $applyPayloadLike($query, $data['payment_method'] ?? null);
                    $applyPayloadLike($query, $data['customer_contact'] ?? null);

                    return $query;
                })
                ->indicateUsing(function (array $data): array {
                    $indicators = [];
                    foreach ([
                        'invoice_number' => 'Invoice',
                        'awb_number' => 'AWB',
                        'hub_code' => 'Hub',
                        'seller_code' => 'Seller',
                        'payment_method' => 'Payment',
                        'customer_contact' => 'Customer',
                    ] as $key => $label) {
                        $value = trim((string) ($data[$key] ?? ''));
                        if ($value !== '') {
                            $indicators[] = $label . ': ' . $value;
                        }
                    }

                    return $indicators;
                }),
            Filter::make('created_at_range')
                ->label('Created Date')
                ->form([
                    DatePicker::make('created_from')->label('From'),
                    DatePicker::make('created_until')->label('Until'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                })
                ->indicateUsing(function (array $data): array {
                    $indicators = [];
                    if (!empty($data['created_from'])) {
                        $indicators[] = 'From: ' . $data['created_from'];
                    }
                    if (!empty($data['created_until'])) {
                        $indicators[] = 'Until: ' . $data['created_until'];
                    }

                    return $indicators;
                }),
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
            Action::make('resendOrderById')
                ->label('Resend Order by ID')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading('Resend Full Order to SAP')
                ->modalDescription(
                    'Re-runs the full SAP flow for one order by its Omniful Order ID '
                    . '(the "Order ID" column value, not the internal DB id). If the order '
                    . 'already exists in SAP it is re-bound and only missing steps '
                    . '(payment / delivery / COGS) are completed — no duplicate invoice. '
                    . 'If its SAP invoice was removed, it is recreated from scratch.'
                )
                ->modalSubmitActionLabel('Resend Order')
                ->form([
                    TextInput::make('order_id')
                        ->label('Omniful Order ID')
                        ->required()
                        ->placeholder('e.g. 70882676')
                        ->helperText('The Order ID shown in the table.'),
                ])
                ->action(function (array $data) {
                    $orderId = trim((string) ($data['order_id'] ?? ''));
                    if ($orderId === '') {
                        Notification::make()->title('Order ID is required')->danger()->send();

                        return;
                    }

                    $order = OmnifulOrder::where('external_id', $orderId)->first();
                    if (!$order) {
                        Notification::make()
                            ->title('Order not found')
                            ->body('No order with Order ID ' . $orderId . ' exists in the dashboard.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $result = app(WebhookRetryService::class)->forceResendOrder($order);
                    Notification::make()
                        ->title($result['ok'] ? 'Resend queued' : 'Resend failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
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
            Action::make('resyncFailedOrders')
                ->label('Re-sync Failed Orders')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn () => $this->getFailedOrdersCount() > 0)
                ->requiresConfirmation()
                ->modalHeading('Re-sync Failed Orders to SAP')
                ->modalDescription(fn () => 'Force-resends ' . number_format($this->getFailedOrdersCount())
                    . ' failed order(s). For each: if its documents already exist in SAP they are re-bound '
                    . '(no duplicate) and the order is marked created; only missing steps are completed; '
                    . 'and it is recreated only if its SAP invoice was removed. Use this to clear false '
                    . 'failures left by a transient SAP connection error. Nothing is deleted.')
                ->modalSubmitActionLabel('Re-sync Failed Orders')
                ->action(function () {
                    $retryService = app(WebhookRetryService::class);
                    $queued = 0;
                    $skipped = 0;

                    OmnifulOrder::query()
                        ->where('sap_status', 'failed')
                        ->orderBy('id')
                        ->chunkById(100, function ($orders) use ($retryService, &$queued, &$skipped) {
                            foreach ($orders as $order) {
                                $result = $retryService->forceResendOrder($order);
                                if ($result['ok']) {
                                    $queued++;
                                } else {
                                    $skipped++;
                                }
                            }
                        });

                    Notification::make()
                        ->title('Failed order re-sync queued')
                        ->body('Queued: ' . number_format($queued) . ($skipped > 0 ? ' | Skipped (no stored event): ' . number_format($skipped) : ''))
                        ->success()
                        ->send();
                }),
        ];
    }
}
