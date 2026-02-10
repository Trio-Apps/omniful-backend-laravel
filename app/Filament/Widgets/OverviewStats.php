<?php

namespace App\Filament\Widgets;

use App\Models\OmnifulOrder;
use App\Models\OmnifulReturnOrderEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $today = now()->startOfDay();
        $currentStart = $today->copy()->subDays(29);
        $previousStart = $today->copy()->subDays(59);
        $previousEnd = $today->copy()->subDays(30)->endOfDay();

        $ordersCurrent = OmnifulOrder::query()
            ->whereBetween('last_event_at', [$currentStart, now()])
            ->count();
        $ordersPrevious = OmnifulOrder::query()
            ->whereBetween('last_event_at', [$previousStart, $previousEnd])
            ->count();

        $orderRowsCurrent = OmnifulOrder::query()
            ->whereBetween('last_event_at', [$currentStart, now()])
            ->get(['last_payload', 'last_event_at', 'sap_status', 'sap_error']);
        $orderRowsPrevious = OmnifulOrder::query()
            ->whereBetween('last_event_at', [$previousStart, $previousEnd])
            ->get(['last_payload', 'last_event_at']);

        $revenueCurrent = $this->sumRevenue($orderRowsCurrent);
        $revenuePrevious = $this->sumRevenue($orderRowsPrevious);

        $returnsCurrent = OmnifulReturnOrderEvent::query()
            ->whereBetween('received_at', [$currentStart, now()])
            ->count();
        $returnsPrevious = OmnifulReturnOrderEvent::query()
            ->whereBetween('received_at', [$previousStart, $previousEnd])
            ->count();

        $activeCustomers = $this->countActiveCustomers($orderRowsCurrent);
        $activeCustomersPrevious = $this->countActiveCustomers($orderRowsPrevious);

        $ordersTrend = $this->buildDailyOrdersTrend($orderRowsCurrent, $currentStart);
        $revenueTrend = $this->buildDailyRevenueTrend($orderRowsCurrent, $currentStart);
        $returnsTrend = $this->buildDailyReturnsTrend($currentStart);
        $customersTrend = $this->buildDailyCustomersTrend($orderRowsCurrent, $currentStart);

        return [
            Stat::make('Orders (30d)', number_format($ordersCurrent))
                ->description($this->describeDelta($ordersCurrent, $ordersPrevious))
                ->descriptionIcon($this->deltaIcon($ordersCurrent, $ordersPrevious))
                ->chart($ordersTrend)
                ->color($this->deltaColor($ordersCurrent, $ordersPrevious)),
            Stat::make('Revenue (30d)', number_format($revenueCurrent, 2))
                ->description($this->describeDelta($revenueCurrent, $revenuePrevious))
                ->descriptionIcon($this->deltaIcon($revenueCurrent, $revenuePrevious))
                ->chart($revenueTrend)
                ->color($this->deltaColor($revenueCurrent, $revenuePrevious)),
            Stat::make('Return Events (30d)', number_format($returnsCurrent))
                ->description($this->describeDelta($returnsCurrent, $returnsPrevious))
                ->descriptionIcon($this->deltaIcon($returnsCurrent, $returnsPrevious))
                ->chart($returnsTrend)
                ->color($this->deltaColor($returnsPrevious, $returnsCurrent)),
            Stat::make('Active Customers (30d)', number_format($activeCustomers))
                ->description($this->describeDelta($activeCustomers, $activeCustomersPrevious))
                ->descriptionIcon($this->deltaIcon($activeCustomers, $activeCustomersPrevious))
                ->chart($customersTrend)
                ->color($this->deltaColor($activeCustomers, $activeCustomersPrevious)),
        ];
    }

    /**
     * @param Collection<int,OmnifulOrder> $rows
     */
    private function sumRevenue(Collection $rows): float
    {
        $sum = 0.0;

        foreach ($rows as $row) {
            $payload = (array) ($row->last_payload ?? []);
            $candidates = [
                data_get($payload, 'data.invoice.total'),
                data_get($payload, 'data.invoice.grand_total'),
                data_get($payload, 'data.total_amount'),
                data_get($payload, 'data.total'),
            ];

            foreach ($candidates as $value) {
                if (is_numeric($value)) {
                    $sum += (float) $value;
                    break;
                }
            }
        }

        return round($sum, 2);
    }

    /**
     * @param Collection<int,OmnifulOrder> $rows
     */
    private function countActiveCustomers(Collection $rows): int
    {
        $emails = [];

        foreach ($rows as $row) {
            $payload = (array) ($row->last_payload ?? []);
            $email = strtolower(trim((string) (
                data_get($payload, 'data.customer.email')
                ?? data_get($payload, 'data.shipping_address.email')
                ?? data_get($payload, 'data.billing_address.email')
            )));

            if ($email !== '') {
                $emails[$email] = true;
            }
        }

        return count($emails);
    }

    /**
     * @param Collection<int,OmnifulOrder> $rows
     * @return array<int,int>
     */
    private function buildDailyOrdersTrend(Collection $rows, Carbon $start): array
    {
        $series = array_fill(0, 7, 0);
        $cut = $start->copy()->addDays(23);

        foreach ($rows as $row) {
            if (!$row->last_event_at || $row->last_event_at->lt($cut)) {
                continue;
            }

            $index = max(0, min(6, $row->last_event_at->dayOfWeekIso - 1));
            $series[$index]++;
        }

        return $series;
    }

    /**
     * @param Collection<int,OmnifulOrder> $rows
     * @return array<int,int>
     */
    private function buildDailyRevenueTrend(Collection $rows, Carbon $start): array
    {
        $series = array_fill(0, 7, 0);
        $cut = $start->copy()->addDays(23);

        foreach ($rows as $row) {
            if (!$row->last_event_at || $row->last_event_at->lt($cut)) {
                continue;
            }

            $payload = (array) ($row->last_payload ?? []);
            $value = data_get($payload, 'data.invoice.total')
                ?? data_get($payload, 'data.invoice.grand_total')
                ?? data_get($payload, 'data.total_amount')
                ?? data_get($payload, 'data.total');
            if (!is_numeric($value)) {
                continue;
            }

            $index = max(0, min(6, $row->last_event_at->dayOfWeekIso - 1));
            $series[$index] += (int) round((float) $value);
        }

        return $series;
    }

    /**
     * @return array<int,int>
     */
    private function buildDailyReturnsTrend(Carbon $start): array
    {
        $series = array_fill(0, 7, 0);
        $cut = $start->copy()->addDays(23);

        $rows = OmnifulReturnOrderEvent::query()
            ->whereBetween('received_at', [$cut, now()])
            ->get(['received_at']);

        foreach ($rows as $row) {
            if (!$row->received_at) {
                continue;
            }
            $index = max(0, min(6, $row->received_at->dayOfWeekIso - 1));
            $series[$index]++;
        }

        return $series;
    }

    /**
     * @param Collection<int,OmnifulOrder> $rows
     * @return array<int,int>
     */
    private function buildDailyCustomersTrend(Collection $rows, Carbon $start): array
    {
        $series = array_fill(0, 7, 0);
        $cut = $start->copy()->addDays(23);
        $byDay = [];

        foreach ($rows as $row) {
            if (!$row->last_event_at || $row->last_event_at->lt($cut)) {
                continue;
            }

            $payload = (array) ($row->last_payload ?? []);
            $email = strtolower(trim((string) (
                data_get($payload, 'data.customer.email')
                ?? data_get($payload, 'data.shipping_address.email')
                ?? data_get($payload, 'data.billing_address.email')
            )));
            if ($email === '') {
                continue;
            }

            $day = $row->last_event_at->toDateString();
            $byDay[$day][$email] = true;
        }

        foreach ($byDay as $day => $emails) {
            $dt = Carbon::parse($day);
            $index = max(0, min(6, $dt->dayOfWeekIso - 1));
            $series[$index] = max($series[$index], count($emails));
        }

        return $series;
    }

    private function describeDelta(float|int $current, float|int $previous): string
    {
        if ($previous <= 0 && $current <= 0) {
            return 'No change vs previous 30 days';
        }

        if ($previous <= 0 && $current > 0) {
            return 'Up 100.0% vs previous 30 days';
        }

        $delta = (($current - $previous) / $previous) * 100;
        $direction = $delta > 0 ? 'Up' : ($delta < 0 ? 'Down' : 'No change');
        $value = number_format(abs($delta), 1);

        return $direction . ' ' . $value . '% vs previous 30 days';
    }

    private function deltaIcon(float|int $current, float|int $previous): string
    {
        if ($current > $previous) {
            return 'heroicon-m-arrow-trending-up';
        }
        if ($current < $previous) {
            return 'heroicon-m-arrow-trending-down';
        }

        return 'heroicon-m-minus';
    }

    private function deltaColor(float|int $current, float|int $previous): string
    {
        if ($current > $previous) {
            return 'success';
        }
        if ($current < $previous) {
            return 'danger';
        }

        return 'gray';
    }
}
