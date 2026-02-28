<?php

namespace App\Filament\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;

trait InteractsWithSapCatalogPage
{
    protected function getCatalogTableActions(): array
    {
        return [
            Action::make('payload')
                ->label('Payload')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->visible(fn ($record) => !empty($record->payload))
                ->modalHeading('SAP Payload')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.sap-sync-error', [
                    'error' => $this->encodePayload($record->payload),
                ])),
            Action::make('error')
                ->label('Reason')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->error)
                ->modalHeading('Sync Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.sap-sync-error', [
                    'error' => (string) $record->error,
                ])),
        ];
    }

    protected function sendSyncSuccessNotification(string $title, array $summary): void
    {
        Notification::make()
            ->title($title)
            ->body($this->formatSyncSummary($summary))
            ->success()
            ->send();
    }

    protected function sendSyncFailureNotification(\Throwable $exception): void
    {
        Notification::make()
            ->title('SAP sync failed')
            ->body($exception->getMessage())
            ->danger()
            ->send();
    }

    private function encodePayload(mixed $payload): string
    {
        if ($payload === null) {
            return 'No payload available.';
        }

        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return is_string($encoded) ? $encoded : 'Unable to render payload.';
    }

    private function formatSyncSummary(array $summary): string
    {
        $lines = [];

        foreach ($summary as $key => $value) {
            $lines[] = ucwords(str_replace('_', ' ', (string) $key)) . ': ' . $value;
        }

        return implode("\n", $lines);
    }
}
