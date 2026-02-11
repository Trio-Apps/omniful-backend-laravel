<?php

namespace App\Services\Sap\Concerns;

trait HandlesSapSeries
{
    private function getDefaultSeries(string $documentCode): ?int
    {
        $response = $this->post('/SeriesService_GetDefaultSeries', [
            'DocumentTypeParams' => [
                'Document' => $documentCode,
            ],
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP series lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $series = $payload['Series'] ?? null;

        return is_numeric($series) ? (int) $series : null;
    }


    private function getDocumentSeries(string $documentCode): array
    {
        $response = $this->post('/SeriesService_GetDocumentSeries', [
            'DocumentTypeParams' => [
                'Document' => $documentCode,
            ],
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP series list failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json()['value'] ?? [];
    }

    /**
     * @return array<int,array>
     */

    /**
     * @return array{series:?int,docDate:string,indicator:?string}
     */
    private function resolveSeriesForDocument(string $documentCode, string $docDate): array
    {
        $year = substr($docDate, 0, 4);
        $seriesList = $this->getDocumentSeries($documentCode);

        $pick = $this->pickSeriesByIndicator($seriesList, $year, true)
            ?? $this->pickSeriesByIndicator($seriesList, 'Default', true)
            ?? $this->pickFirstUnlockedSeries($seriesList, true);

        if (!$pick) {
            return ['series' => $this->getDefaultSeries($documentCode), 'docDate' => $docDate, 'indicator' => null];
        }

        $indicator = (string) ($pick['PeriodIndicator'] ?? '');
        $seriesId = isset($pick['Series']) ? (int) $pick['Series'] : null;

        if ($indicator !== '' && $indicator !== 'Default' && $indicator !== $year && preg_match('/^\\d{4}$/', $indicator)) {
            $docDate = $indicator . '-01-01';
        }

        return ['series' => $seriesId, 'docDate' => $docDate, 'indicator' => $indicator ?: null];
    }


    private function pickSeriesByIndicator(array $seriesList, string $indicator, bool $requireUsable): ?array
    {
        foreach ($seriesList as $series) {
            if (($series['PeriodIndicator'] ?? null) === $indicator && ($series['Locked'] ?? 'tNO') !== 'tYES') {
                if ($requireUsable && !$this->isSeriesUsable($series)) {
                    continue;
                }
                return $series;
            }
        }

        return null;
    }


    private function pickFirstUnlockedSeries(array $seriesList, bool $requireUsable): ?array
    {
        foreach ($seriesList as $series) {
            if (($series['Locked'] ?? 'tNO') !== 'tYES') {
                if ($requireUsable && !$this->isSeriesUsable($series)) {
                    continue;
                }
                return $series;
            }
        }

        return null;
    }


    private function isSeriesUsable(array $series): bool
    {
        $last = $series['LastNumber'] ?? null;
        $next = $series['NextNumber'] ?? null;

        if ($last === null || $last === '') {
            return true;
        }

        if ($next === null || $next === '') {
            return false;
        }

        return (int) $next <= (int) $last;
    }

}

