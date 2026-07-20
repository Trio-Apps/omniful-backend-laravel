<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrder;
use App\Filament\Pages\OmnifulOrderView;
use App\Filament\Pages\OmnifulOrderErrorMonitor;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
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
                    $search = trim($search);
                    // Order ids / SAP doc numbers are the search targets. NEVER
                    // search last_payload — LIKE over the ~4KB JSON blob on every
                    // row is a full ~376MB scan per keystroke (search was ~10x
                    // slower). A leading-anchored LIKE on external_id also lets
                    // the unique index help for the common "starts-with" case.
                    return $query->where(function (Builder $query) use ($search) {
                        $like = '%' . $search . '%';
                        $query->where('external_id', 'like', $search . '%')
                            ->orWhere('external_id', 'like', $like)
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
                            ->orWhere('sap_cancel_cogs_journal_num', 'like', $like);
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
                ->modalHeading('Resend Orders to SAP')
                ->modalDescription(
                    'Force re-runs the full SAP flow (invoice → payment → COGS → delivery). '
                    . 'Pick a mode: a single Order ID, a list, an Order ID range, or a created-at '
                    . 'date/time range. Existing SAP docs are re-bound (no duplicate); a stale '
                    . 'invoice with no payment/delivery is cancelled & recreated. '
                    . 'WARNING: confirm ONE order completes end-to-end (payment succeeds) before '
                    . 'bulk-resending, or stale-rate invoices get repeatedly cancelled + recreated.'
                )
                ->modalSubmitActionLabel('Resend')
                ->form([
                    Select::make('mode')
                        ->label('Mode')
                        ->required()
                        ->default('single')
                        ->live()
                        ->options([
                            'single' => 'Single Order ID',
                            'list' => 'Multiple Order IDs (list)',
                            'range' => 'Order ID range',
                            'dates' => 'Created-at date/time range',
                        ]),
                    TextInput::make('order_id')
                        ->label('Omniful Order ID')
                        ->placeholder('e.g. 70882676')
                        ->helperText('The Order ID shown in the table.')
                        ->visible(fn (Get $get) => $get('mode') === 'single')
                        ->required(fn (Get $get) => $get('mode') === 'single'),
                    Textarea::make('order_ids')
                        ->label('Order IDs')
                        ->rows(4)
                        ->placeholder("70868015, 70879904\n70931496")
                        ->helperText('Separate by comma, space, semicolon, or new line.')
                        ->visible(fn (Get $get) => $get('mode') === 'list')
                        ->required(fn (Get $get) => $get('mode') === 'list'),
                    TextInput::make('from_id')
                        ->label('From Order ID')
                        ->numeric()
                        ->placeholder('e.g. 100001')
                        ->visible(fn (Get $get) => $get('mode') === 'range')
                        ->required(fn (Get $get) => $get('mode') === 'range'),
                    TextInput::make('to_id')
                        ->label('To Order ID')
                        ->numeric()
                        ->placeholder('e.g. 100010')
                        ->visible(fn (Get $get) => $get('mode') === 'range')
                        ->required(fn (Get $get) => $get('mode') === 'range'),
                    DateTimePicker::make('from_date')
                        ->label('From (created at)')
                        ->seconds(false)
                        ->visible(fn (Get $get) => $get('mode') === 'dates')
                        ->required(fn (Get $get) => $get('mode') === 'dates'),
                    DateTimePicker::make('to_date')
                        ->label('To (created at)')
                        ->seconds(false)
                        ->visible(fn (Get $get) => $get('mode') === 'dates')
                        ->required(fn (Get $get) => $get('mode') === 'dates'),
                    Checkbox::make('cancel_old')
                        ->label('Cancel & reverse the existing SAP invoice')
                        ->helperText('Reverses the old invoice (credit memo + renames its order ref to '
                            . '"<id>-reversed") then creates a fresh one — use when the existing invoice '
                            . 'has the wrong rate. Skipped automatically for orders that already have a '
                            . 'successful payment/delivery. Leave OFF to only rebind & complete missing steps.')
                        ->default(false),
                ])
                ->action(function (array $data) {
                    $mode = (string) ($data['mode'] ?? 'single');
                    $cancelOld = (bool) ($data['cancel_old'] ?? false);
                    $retry = app(WebhookRetryService::class);

                    $queued = 0;
                    $skipped = 0;
                    $notFound = 0;

                    $process = function (OmnifulOrder $order) use ($retry, $cancelOld, &$queued, &$skipped) {
                        $result = $retry->forceResendOrder($order, $cancelOld);
                        if ($result['ok'] ?? false) {
                            $queued++;
                        } else {
                            $skipped++;
                        }
                    };

                    // Explicit-id modes (single / list) resolve each id directly.
                    $explicitIds = null;
                    if ($mode === 'single') {
                        $id = trim((string) ($data['order_id'] ?? ''));
                        if ($id === '') {
                            Notification::make()->title('Order ID is required')->danger()->send();

                            return;
                        }
                        $explicitIds = [$id];
                    } elseif ($mode === 'list') {
                        $explicitIds = array_values(array_unique(array_filter(
                            array_map('trim', preg_split('/[\s,;]+/', (string) ($data['order_ids'] ?? ''))),
                            fn ($v) => $v !== '',
                        )));
                        if ($explicitIds === []) {
                            Notification::make()->title('No order IDs provided')->danger()->send();

                            return;
                        }
                    }

                    if ($explicitIds !== null) {
                        foreach ($explicitIds as $id) {
                            $order = OmnifulOrder::where('external_id', $id)->first();
                            if (!$order) {
                                $notFound++;
                                continue;
                            }
                            $process($order);
                        }

                        $body = 'Queued: ' . number_format($queued)
                            . ($skipped > 0 ? ' | Skipped: ' . number_format($skipped) : '')
                            . ($notFound > 0 ? ' | Not found: ' . number_format($notFound) : '');
                        Notification::make()->title('Resend queued')->body($body)->success()->send();

                        return;
                    }

                    // Query modes (range / dates) — force-resend EVERY matching order.
                    $query = OmnifulOrder::query();
                    if ($mode === 'range') {
                        $from = trim((string) ($data['from_id'] ?? ''));
                        $to = trim((string) ($data['to_id'] ?? ''));
                        if (!ctype_digit($from) || !ctype_digit($to)) {
                            Notification::make()->title('Range must be numeric')->danger()->send();

                            return;
                        }
                        $low = min((int) $from, (int) $to);
                        $high = max((int) $from, (int) $to);
                        $query->whereRaw("external_id REGEXP '^[0-9]+$'")
                            ->whereRaw('CAST(external_id AS UNSIGNED) BETWEEN ? AND ?', [$low, $high]);
                    } elseif ($mode === 'dates') {
                        $from = $data['from_date'] ?? null;
                        $to = $data['to_date'] ?? null;
                        if (!$from || !$to) {
                            Notification::make()->title('Both dates are required')->danger()->send();

                            return;
                        }
                        $query->whereBetween('created_at', [$from, $to]);
                    } else {
                        Notification::make()->title('Unknown resend mode')->danger()->send();

                        return;
                    }

                    $query->orderBy('id')->chunkById(200, function ($orders) use ($process) {
                        foreach ($orders as $order) {
                            $process($order);
                        }
                    });

                    if ($queued === 0 && $skipped === 0) {
                        Notification::make()->title('No matching orders found')->warning()->send();

                        return;
                    }

                    $body = 'Queued: ' . number_format($queued)
                        . ($skipped > 0 ? ' | Skipped (no stored event): ' . number_format($skipped) : '');
                    Notification::make()->title('Resend queued')->body($body)->success()->send();
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
