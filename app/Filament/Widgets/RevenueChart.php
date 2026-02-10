<?php

namespace App\Filament\Widgets;

use App\Models\OmnifulOrder;
use Filament\Widgets\ChartWidget;

class RevenueChart extends ChartWidget
{
    protected ?string $heading = 'Revenue by Payment Method (Last 14 Days)';

    protected ?string $description = 'Actual revenue extracted from Omniful order payloads';

    protected string $color = 'success';

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'md' => 6,
        'xl' => 6,
    ];

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $start = now()->startOfDay()->subDays(13);
        $rows = OmnifulOrder::query()
            ->whereBetween('last_event_at', [$start, now()])
            ->get(['last_payload']);

        $totals = [];

        foreach ($rows as $row) {
            $payload = (array) ($row->last_payload ?? []);
            $paymentMethod = trim((string) (
                data_get($payload, 'data.payment_method')
                ?? data_get($payload, 'data.payment.method')
                ?? data_get($payload, 'data.payment_type')
                ?? 'unknown'
            ));
            if ($paymentMethod === '') {
                $paymentMethod = 'unknown';
            }

            $amount = data_get($payload, 'data.invoice.total');
            if (!is_numeric($amount)) {
                $amount = data_get($payload, 'data.invoice.grand_total');
            }
            if (!is_numeric($amount)) {
                $amount = data_get($payload, 'data.total_amount');
            }
            if (!is_numeric($amount)) {
                $amount = data_get($payload, 'data.total');
            }

            if (!is_numeric($amount)) {
                continue;
            }

            $key = strtolower($paymentMethod);
            $totals[$key] = ($totals[$key] ?? 0) + (float) $amount;
        }

        arsort($totals);
        $top = array_slice($totals, 0, 7, true);
        if ($top === []) {
            $top = ['no_data' => 0];
        }

        return [
            'labels' => array_map(
                fn (string $k) => $k === 'no_data' ? 'No Data' : strtoupper($k),
                array_keys($top)
            ),
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => array_values(array_map(fn ($v) => round((float) $v, 2), $top)),
                    'borderRadius' => 6,
                ],
            ],
        ];
    }
}
