<?php

namespace App\Filament\Pages;

use App\Models\OmnifulInwardingEvent;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class OmnifulInwardingEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Inwarding Events';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.omniful-inwarding-events';

    protected function getTableQuery(): Builder
    {
        return OmnifulInwardingEvent::query()->orderByDesc('received_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('external_id')
                ->label('Reference ID')
                ->searchable(),
            IconColumn::make('signature_valid')
                ->label('Signature')
                ->boolean()
                ->toggleable(),
            TextColumn::make('received_at')
                ->label('Received')
                ->dateTime()
                ->sortable(),
            TextColumn::make('sap_status')
                ->label('SAP')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'created' => 'success',
                    'failed' => 'danger',
                    'ignored' => 'gray',
                    'skipped' => 'warning',
                    default => 'gray',
                })
                ->toggleable(),
            TextColumn::make('sap_doc_num')
                ->label('SAP DocNum')
                ->toggleable(),
            TextColumn::make('payload')
                ->label('Payload')
                ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state) : (string) $state)
                ->limit(120)
                ->toggleable(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('view')
                ->label('View')
                ->icon('heroicon-o-eye')
                ->url(fn ($record) => OmnifulInwardingEventView::getUrl(['record' => $record])),
            Action::make('sapError')
                ->label('Reason')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->sap_error)
                ->modalHeading('SAP / Ignore Reason')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.omniful-inventory-sap-error', [
                    'error' => $record->sap_error,
                ])),
            Action::make('retrySap')
                ->label('Retry SAP')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->action(function ($record) {
                    $result = app(WebhookRetryService::class)->retryInwardingEvent($record);
                    Notification::make()
                        ->title($result['ok'] ? 'Retry completed' : 'Retry failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }
}
