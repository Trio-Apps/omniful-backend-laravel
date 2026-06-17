<?php

namespace App\Services\MasterData;

use App\Models\SapSupplier;
use App\Models\SapSyncEvent;
use App\Services\IntegrationDirectionService;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;

class SapSupplierSyncService
{
    private function syncDisabled(): bool
    {
        return !app(IntegrationDirectionService::class)->isDomainEnabled('suppliers');
    }

    /**
     * Pick the supplier phone from SAP, preferring the mobile (Cellular) field
     * — that is what suppliers are actually reached on here — and falling back
     * to the landline Phone1/Phone2 only when no mobile is set.
     *
     * @param array<string,mixed> $row
     */
    private function extractSupplierPhone(array $row): ?string
    {
        foreach (['MobilePhoneNumber', 'Cellular', 'Phone1', 'Phone2'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function syncFromSap(SapServiceLayerClient $client): array
    {
        if ($this->syncDisabled()) {
            return ['total' => 0, 'synced' => 0, 'pending' => 0, 'skipped' => 0, 'disabled' => true];
        }

        $rows = $client->fetchSuppliers();
        $synced = 0;
        $pending = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $code = $row['CardCode'] ?? null;
            if (!$code) {
                continue;
            }
            $enabled = $client->isSupplierIntegrationEnabled((string) $code);
            SapSupplier::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $row['CardName'] ?? null,
                    'email' => $row['EmailAddress'] ?? null,
                    'phone' => $this->extractSupplierPhone($row),
                    'payload' => $row,
                    'synced_at' => now(),
                    'status' => 'synced',
                    'error' => null,
                ]
            );
            $synced++;
            $record = SapSupplier::where('code', $code)->first();
            if ($record) {
                if ($enabled) {
                    if (!$record->omniful_status || $record->omniful_status === 'skipped') {
                        $record->omniful_status = 'pending';
                        $record->omniful_error = null;
                        $record->save();
                        $pending++;
                    }
                } else {
                    $record->omniful_status = 'skipped';
                    $record->omniful_error = 'Skipped by supplier integration UDF control';
                    $record->save();
                    $skipped++;
                }
            }
        }

        return [
            'total' => count($rows),
            'synced' => $synced,
            'pending' => $pending,
            'skipped' => $skipped,
        ];
    }

    public function pushToOmniful(OmnifulApiClient $client, ?int $limit = null, ?SapSyncEvent $event = null): array
    {
        if ($this->syncDisabled()) {
            return ['ok' => 0, 'failed' => 0, 'errors' => [], 'cancelled' => false, 'disabled' => true];
        }

        $query = SapSupplier::query()
            ->where(function ($q): void {
                $q->whereNull('omniful_status')
                    ->orWhereIn('omniful_status', ['pending', 'failed']);
            })
            ->orderBy('code')
            ;

        $batchLimit = (int) ($limit ?? config('omniful.push_batch.suppliers', 50));
        if ($batchLimit > 0) {
            $query->limit($batchLimit);
        }

        $records = $query->get();

        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($records as $record) {
            if ($event?->fresh()?->sap_status === 'cancel_requested') {
                $remaining = SapSupplier::query()
                    ->where(function ($q): void {
                        $q->whereNull('omniful_status')
                            ->orWhereIn('omniful_status', ['pending', 'failed']);
                    })
                    ->count();

                return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors, 'remaining' => $remaining, 'cancelled' => true];
            }

            $record->omniful_status = 'syncing';
            $record->omniful_error = null;
            $record->save();

            $result = $this->pushRecord($record, $client);

            if ($result['ok']) {
                $ok++;
            } elseif ($result['outcome'] !== 'skipped') {
                $failed++;
                $errors[] = $record->code . ': ' . ($result['error'] ?? 'push failed');
            }
        }

        $remaining = SapSupplier::query()
            ->where(function ($q): void {
                $q->whereNull('omniful_status')
                    ->orWhereIn('omniful_status', ['pending', 'failed']);
            })
            ->count();

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors, 'remaining' => $remaining, 'cancelled' => false];
    }

    /**
     * Push a single supplier to Omniful, persisting the exact payload sent plus
     * Omniful's raw response and HTTP status on the record (regardless of
     * outcome) for per-supplier debugging. Unlike the bulk push, this always
     * sends — even for an already-synced supplier — so it doubles as a
     * "re-push / debug" action from the SAP Suppliers page.
     *
     * @return array{outcome:string,ok:bool,error:?string}
     */
    public function pushRecord(SapSupplier $record, ?OmnifulApiClient $client = null): array
    {
        $client ??= app(OmnifulApiClient::class);
        $sapClient = app(SapServiceLayerClient::class);

        [$payload] = $this->buildOmnifulPayload($record);

        try {
            // Respect the SAP UDF control: a supplier flagged not-integrated is
            // skipped (nothing is sent to Omniful).
            if (!$sapClient->isSupplierIntegrationEnabled((string) $record->code)) {
                $record->update([
                    'omniful_status' => 'skipped',
                    'omniful_error' => 'Skipped by supplier integration UDF control',
                    'omniful_payload' => $payload,
                    'omniful_response' => null,
                    'omniful_response_code' => null,
                ]);

                return ['outcome' => 'skipped', 'ok' => false, 'error' => null];
            }

            $response = $client->upsert('suppliers', (string) $record->code, $payload);

            // Always persist the exact payload sent and Omniful's raw response
            // (plus HTTP status), regardless of outcome, for debugging.
            $captured = [
                'omniful_payload' => $payload,
                'omniful_response' => $response['body'] ?? null,
                'omniful_response_code' => $response['status'] ?? null,
            ];

            if (!($response['ok'] ?? false)) {
                $record->update($captured + [
                    'omniful_status' => 'failed',
                    'omniful_error' => 'HTTP ' . ($response['status'] ?? 0) . ' ' . ($response['body'] ?? ''),
                ]);

                return ['outcome' => 'supplier', 'ok' => false, 'error' => 'HTTP ' . ($response['status'] ?? 0)];
            }

            // Omniful accepted — persist the captured payload/response and mark
            // synced FIRST, so the debug modal always reflects what was sent
            // even if the subsequent SAP flag stamp fails.
            $record->update($captured + [
                'omniful_status' => 'synced',
                'omniful_error' => null,
                'omniful_synced_at' => now(),
            ]);

            // Stamp the SAP integration flag (U_OmBPInt = Y) so the supplier is
            // not pulled again. If this fails we keep the captured response but
            // flip to failed (so it is retried) and surface the SAP error.
            try {
                $sapClient->markSupplierIntegrated((string) $record->code);
            } catch (\Throwable $e) {
                $record->update([
                    'omniful_status' => 'failed',
                    'omniful_error' => 'Omniful accepted but SAP flag stamp failed: ' . $e->getMessage(),
                ]);

                return ['outcome' => 'supplier', 'ok' => false, 'error' => $e->getMessage()];
            }

            return ['outcome' => 'supplier', 'ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            $record->update([
                'omniful_status' => 'failed',
                'omniful_error' => $e->getMessage(),
            ]);

            return ['outcome' => 'error', 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build the exact Omniful payload that would be pushed for a supplier,
     * filling the same name/email/phone defaults the push uses. Returns the
     * payload plus the list of fields that fell back to a default.
     *
     * @return array{0:array<string,mixed>,1:array<int,string>}
     */
    public function buildOmnifulPayload(SapSupplier $record): array
    {
        $usedFallbacks = [];

        $name = $record->name ?: $record->code;
        if (!$record->name) {
            $usedFallbacks[] = 'name';
        }

        $email = $record->email ?: ($record->code . '@sap.local');
        if (!$record->email) {
            $usedFallbacks[] = 'email';
        }

        $phone = $record->phone ?: '0000000000';
        if (!$record->phone) {
            $usedFallbacks[] = 'phone';
        }

        return [[
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'code' => $record->code,
        ], $usedFallbacks];
    }

    /**
     * Build the exact Omniful payload that would be pushed for a supplier,
     * WITHOUT sending anything. Used by the SAP Suppliers page to preview/debug
     * per-supplier payloads.
     *
     * @return array{type:string,resource:string,payload:array<string,mixed>,note:?string}
     */
    public function previewPayload(SapSupplier $record): array
    {
        [$payload, $usedFallbacks] = $this->buildOmnifulPayload($record);

        return [
            'type' => 'supplier',
            'resource' => 'suppliers',
            'payload' => $payload,
            'note' => $usedFallbacks
                ? ('Defaults will be filled for: ' . implode(', ', $usedFallbacks))
                : null,
        ];
    }

    public function syncFromOmniful(OmnifulApiClient $omnifulClient, SapServiceLayerClient $sapClient): array
    {
        if ($this->syncDisabled()) {
            return ['ok' => 0, 'failed' => 0, 'errors' => [], 'disabled' => true];
        }

        $rows = $omnifulClient->fetchList('suppliers');

        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $row) {
            try {
                $payload = [
                    'code' => data_get($row, 'code') ?? data_get($row, 'supplier_code') ?? data_get($row, 'id'),
                    'name' => data_get($row, 'name'),
                    'email' => data_get($row, 'email'),
                    'phone' => data_get($row, 'phone') ?? data_get($row, 'phone_number'),
                ];

                $result = $sapClient->syncSupplierFromOmniful($payload);
                if (($result['status'] ?? '') === 'skipped_by_udf') {
                    continue;
                }
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ($row['code'] ?? $row['id'] ?? 'unknown') . ': ' . $e->getMessage();
            }
        }

        $this->syncFromSap($sapClient);

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors];
    }
}
