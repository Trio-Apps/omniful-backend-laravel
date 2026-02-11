<?php

namespace App\Services\MasterData;

use App\Models\SapCostCenter;
use App\Models\SapCostCenterSetting;
use App\Services\SapServiceLayerClient;

class SapCostCenterSyncService
{
    public function syncFromSap(SapServiceLayerClient $client): array
    {
        $distributionRules = (array) $client->fetchCostCenters();
        $projects = (array) $client->fetchProjects();
        $now = now();

        $upsertRows = [];
        foreach ($distributionRules as $row) {
            $code = trim((string) ($row['FactorCode'] ?? $row['OcrCode'] ?? $row['Code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $dimension = $this->parseDimension($row['InWhichDimension'] ?? $row['DimCode'] ?? null);
            $upsertRows[] = [
                'source' => 'distribution_rule',
                'dimension' => $dimension,
                'code' => $code,
                'name' => trim((string) ($row['FactorName'] ?? $row['OcrName'] ?? $row['Name'] ?? $code)),
                'is_active' => $this->parseActive($row['Active'] ?? $row['Locked'] ?? null, true),
                'synced_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        foreach ($projects as $row) {
            $code = trim((string) ($row['Code'] ?? $row['PrjCode'] ?? ''));
            if ($code === '') {
                continue;
            }

            $upsertRows[] = [
                'source' => 'project',
                'dimension' => null,
                'code' => $code,
                'name' => trim((string) ($row['Name'] ?? $row['PrjName'] ?? $code)),
                'is_active' => $this->parseActive($row['Active'] ?? $row['Locked'] ?? null, true),
                'synced_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        if ($upsertRows !== []) {
            SapCostCenter::upsert(
                $upsertRows,
                ['source', 'dimension', 'code'],
                ['name', 'is_active', 'synced_at', 'updated_at']
            );
        }

        SapCostCenterSetting::query()->updateOrCreate(
            ['id' => 1],
            ['last_synced_at' => $now]
        );

        return [
            'distribution_rules' => count(array_filter($upsertRows, fn ($r) => $r['source'] === 'distribution_rule')),
            'projects' => count(array_filter($upsertRows, fn ($r) => $r['source'] === 'project')),
            'total' => count($upsertRows),
            'synced_at' => $now->toDateTimeString(),
        ];
    }

    private function parseDimension(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $dim = (int) $value;
            return $dim > 0 ? $dim : null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\d+/', $normalized, $m) === 1) {
            $dim = (int) $m[0];
            return $dim > 0 ? $dim : null;
        }

        return null;
    }

    private function parseActive(mixed $value, bool $default = true): bool
    {
        if ($value === null) {
            return $default;
        }

        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return $default;
        }

        if (in_array($v, ['tyes', 'yes', 'y', 'true', '1', 'unlocked', 'active'], true)) {
            return true;
        }

        if (in_array($v, ['tno', 'no', 'n', 'false', '0', 'locked', 'inactive'], true)) {
            return false;
        }

        return $default;
    }
}

