<?php

namespace App\Services;

use App\Models\SapCatalogRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SapCatalogSyncService
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function definitions(): array
    {
        return (array) config('sap_catalog.resources', []);
    }

    /**
     * @param array<int,string> $resourceKeys
     * @return array{resources:int,records:int,failed:int,errors:array<int,string>}
     */
    public function sync(SapServiceLayerClient $client, array $resourceKeys = []): array
    {
        $definitions = $this->selectDefinitions($resourceKeys);
        $summary = [
            'resources' => 0,
            'records' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($definitions as $resource => $definition) {
            try {
                $count = $this->syncResource($client, $resource, $definition);
                $summary['resources']++;
                $summary['records'] += $count;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = $resource . ': ' . $e->getMessage();
            }
        }

        return $summary;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function selectDefinitions(array $resourceKeys): array
    {
        $definitions = $this->definitions();
        if ($resourceKeys === []) {
            return $definitions;
        }

        $selected = [];
        foreach ($resourceKeys as $resourceKey) {
            $key = trim($resourceKey);
            if ($key === '') {
                continue;
            }

            if (!array_key_exists($key, $definitions)) {
                throw new \InvalidArgumentException('Unknown SAP catalog resource: ' . $key);
            }

            $selected[$key] = $definitions[$key];
        }

        return $selected;
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function syncResource(SapServiceLayerClient $client, string $resource, array $definition): int
    {
        $rows = $this->fetchRows($client, $definition);
        $module = (string) ($definition['module'] ?? Str::before($resource, '.'));
        $path = (string) ($definition['path'] ?? '');
        $now = now();
        $count = 0;

        foreach ($rows as $index => $row) {
            $key = $this->resolveExternalKey($row, $definition, $index + 1);
            $name = $this->resolveName($row, $definition);

            SapCatalogRecord::updateOrCreate(
                [
                    'resource' => $resource,
                    'external_key' => $key,
                ],
                [
                    'module' => $module,
                    'sap_path' => $path,
                    'name' => $name,
                    'payload' => $row,
                    'synced_at' => $now,
                    'status' => 'synced',
                    'error' => null,
                ]
            );

            $count++;
        }

        if ($count === 0) {
            SapCatalogRecord::updateOrCreate(
                [
                    'resource' => $resource,
                    'external_key' => 'empty',
                ],
                [
                    'module' => $module,
                    'sap_path' => $path,
                    'name' => 'Empty result',
                    'payload' => [],
                    'synced_at' => $now,
                    'status' => 'synced',
                    'error' => null,
                ]
            );
        }

        return $count;
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<int,array<string,mixed>>
     */
    private function fetchRows(SapServiceLayerClient $client, array $definition): array
    {
        $path = (string) ($definition['path'] ?? '');
        $mode = strtolower(trim((string) ($definition['mode'] ?? 'collection')));

        if ($path === '') {
            throw new \InvalidArgumentException('SAP catalog resource path is required');
        }

        if ($mode === 'raw') {
            $payload = $client->fetchRawResource($path);
            return $this->extractRowsFromPayload($payload, $definition);
        }

        return $this->normalizeRows($client->fetchCollectionByPath($path));
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $definition
     * @return array<int,array<string,mixed>>
     */
    private function extractRowsFromPayload(array $payload, array $definition): array
    {
        $dataKey = trim((string) ($definition['data_key'] ?? ''));
        if ($dataKey !== '') {
            return $this->normalizeRows(data_get($payload, $dataKey));
        }

        if (isset($payload['value']) && is_array($payload['value'])) {
            return $this->normalizeRows($payload['value']);
        }

        foreach ($payload as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $this->normalizeRows($value);
            }
        }

        return $this->normalizeRows($payload);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        if (array_is_list($rows)) {
            return array_values(array_map(
                fn ($row) => is_array($row) ? $row : ['value' => $row],
                $rows
            ));
        }

        return [$rows];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $definition
     */
    private function resolveExternalKey(array $row, array $definition, int $index): string
    {
        $candidates = (array) ($definition['key_candidates'] ?? []);
        foreach ($candidates as $candidate) {
            $value = data_get($row, (string) $candidate);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $fallbackPrefix = trim((string) ($definition['fallback_key_prefix'] ?? 'row'));
        return $fallbackPrefix . '-' . $index;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $definition
     */
    private function resolveName(array $row, array $definition): ?string
    {
        $candidates = (array) ($definition['name_candidates'] ?? []);
        foreach ($candidates as $candidate) {
            $value = data_get($row, (string) $candidate);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $fallbackName = Arr::get($row, 'DocNum') ?? Arr::get($row, 'DocEntry');
        if (is_scalar($fallbackName) && trim((string) $fallbackName) !== '') {
            return trim((string) $fallbackName);
        }

        return null;
    }
}
