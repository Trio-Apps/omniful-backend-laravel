<?php

namespace App\Services\MasterData;

use App\Models\SapItem;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;

class SapItemSyncService
{
    public function syncFromSap(SapServiceLayerClient $client): void
    {
        $rows = $client->fetchItems();
        foreach ($rows as $row) {
            $code = $row['ItemCode'] ?? null;
            if (!$code) {
                continue;
            }
            $record = SapItem::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $row['ItemName'] ?? null,
                    'uom_group_entry' => $row['UoMGroupEntry'] ?? null,
                    'payload' => $row,
                    'synced_at' => now(),
                    'status' => 'synced',
                    'error' => null,
                ]
            );

            if (!$record->omniful_status) {
                $record->omniful_status = 'pending';
                $record->save();
            }
        }
    }

    public function pushToOmniful(OmnifulApiClient $client): array
    {
        $records = SapItem::query()
            ->whereNull('omniful_status')
            ->orWhere('omniful_status', '!=', 'synced')
            ->orderBy('code')
            ->get();

        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($records as $record) {
            $record->omniful_status = 'syncing';
            $record->omniful_error = null;
            $record->save();

            try {
                $payload = [
                    'code' => $record->code,
                    'name' => $record->name,
                    'uom_group_entry' => $record->uom_group_entry,
                ];

                $response = $client->upsert('items', $record->code, $payload);
                if (!$response['ok']) {
                    throw new \RuntimeException('HTTP ' . $response['status'] . ' ' . $response['body']);
                }

                $record->omniful_status = 'synced';
                $record->omniful_error = null;
                $record->omniful_synced_at = now();
                $record->save();
                $ok++;
            } catch (\Throwable $e) {
                $record->omniful_status = 'failed';
                $record->omniful_error = $e->getMessage();
                $record->save();
                $failed++;
                $errors[] = $record->code . ': ' . $e->getMessage();
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors];
    }
}

