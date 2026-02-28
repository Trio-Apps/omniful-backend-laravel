<?php

namespace App\Console\Commands;

use App\Models\OmnifulInventoryEvent;
use App\Models\OmnifulInwardingEvent;
use App\Models\OmnifulOrderEvent;
use App\Models\OmnifulProductEvent;
use App\Models\OmnifulPurchaseOrderEvent;
use App\Models\OmnifulReturnOrderEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class AuditWebhookPayloads extends Command
{
    protected $signature = 'webhooks:audit-payloads
        {--limit=25 : Number of latest rows to inspect per webhook}
        {--export=docs/live-webhook-payload-audit.md : Markdown export path}';

    protected $description = 'Audit stored Omniful webhook payloads to lock live field names and statuses.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $summaries = collect($this->sources())
            ->map(fn (array $source) => $this->summarizeSource($source, $limit));

        $this->table(
            ['Webhook', 'Total', 'Latest', 'Events', 'Statuses'],
            $summaries->map(fn (array $row) => [
                $row['label'],
                $row['total'],
                $row['latest_at'] ?: '-',
                $this->truncateCell($this->joinValues($row['events'])),
                $this->truncateCell($this->joinValues($row['statuses'])),
            ])->all()
        );

        $export = trim((string) $this->option('export'));
        if ($export !== '') {
            $absolute = base_path($export);
            $dir = dirname($absolute);
            if (!is_dir($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            File::put($absolute, $this->toMarkdown($summaries, $limit));
            $this->info('Exported webhook payload audit to ' . $export);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,array{label:string,model:class-string<Model>}>
     */
    private function sources(): array
    {
        return [
            ['label' => 'Order', 'model' => OmnifulOrderEvent::class],
            ['label' => 'Return Order', 'model' => OmnifulReturnOrderEvent::class],
            ['label' => 'Purchase Order', 'model' => OmnifulPurchaseOrderEvent::class],
            ['label' => 'Inventory', 'model' => OmnifulInventoryEvent::class],
            ['label' => 'Stock Transfer Request', 'model' => OmnifulInventoryEvent::class],
            ['label' => 'Product', 'model' => OmnifulProductEvent::class],
            ['label' => 'Inwarding', 'model' => OmnifulInwardingEvent::class],
        ];
    }

    /**
     * @param array{label:string,model:class-string<Model>} $source
     * @return array<string,mixed>
     */
    private function summarizeSource(array $source, int $limit): array
    {
        $label = $source['label'];
        $model = $source['model'];

        try {
            $query = $model::query();
            $total = (int) (clone $query)->count();
            $recent = (clone $query)->orderByDesc('received_at')->limit($limit)->get();
        } catch (QueryException $e) {
            return [
                'label' => $label,
                'total' => 0,
                'latest_at' => null,
                'events' => [],
                'statuses' => [],
                'actions' => [],
                'entities' => [],
                'data_keys' => [],
                'line_keys' => [],
                'external_ids' => [],
                'note' => 'Table unavailable: ' . $e->getCode(),
            ];
        }

        if ($label === 'Stock Transfer Request') {
            $recent = $recent->filter(fn ($event) => $this->isStockTransferPayload((array) ($event->payload ?? [])))->values();
            $total = $model::query()
                ->get()
                ->filter(fn ($event) => $this->isStockTransferPayload((array) ($event->payload ?? [])))
                ->count();
        } elseif ($label === 'Inventory') {
            $recent = $recent->reject(fn ($event) => $this->isStockTransferPayload((array) ($event->payload ?? [])))->values();
            $total = $model::query()
                ->get()
                ->reject(fn ($event) => $this->isStockTransferPayload((array) ($event->payload ?? [])))
                ->count();
        }

        $events = [];
        $statuses = [];
        $actions = [];
        $entities = [];
        $dataKeys = [];
        $lineKeys = [];
        $externalIds = [];
        $latestAt = null;

        foreach ($recent as $event) {
            $payload = (array) ($event->payload ?? []);
            if ($latestAt === null && $event->received_at !== null) {
                $latestAt = $event->received_at->toDateTimeString();
            }

            $eventName = trim((string) data_get($payload, 'event_name', ''));
            if ($eventName !== '') {
                $events[] = $eventName;
            }

            $status = $this->extractStatusValue($payload);
            if ($status !== '') {
                $statuses[] = $status;
            }

            $action = trim((string) (
                data_get($payload, 'action')
                ?? data_get($payload, 'data.action')
                ?? ''
            ));
            if ($action !== '') {
                $actions[] = $action;
            }

            $entity = trim((string) (
                data_get($payload, 'entity')
                ?? data_get($payload, 'data.entity')
                ?? data_get($payload, 'data.entity_type')
                ?? ''
            ));
            if ($entity !== '') {
                $entities[] = $entity;
            }

            $dataKeys = array_values(array_unique(array_merge($dataKeys, $this->extractDataKeys($payload))));
            $lineKeys = array_values(array_unique(array_merge($lineKeys, $this->extractLineKeys($payload))));

            $externalId = trim((string) ($event->external_id ?? ''));
            if ($externalId !== '') {
                $externalIds[] = $externalId;
            }
        }

        return [
            'label' => $label,
            'total' => $total,
            'latest_at' => $latestAt,
            'events' => $this->uniqueValues($events),
            'statuses' => $this->uniqueValues($statuses),
            'actions' => $this->uniqueValues($actions),
            'entities' => $this->uniqueValues($entities),
            'data_keys' => $dataKeys,
            'line_keys' => $lineKeys,
            'external_ids' => array_slice($this->uniqueValues($externalIds), 0, 5),
            'note' => $total === 0 ? 'No rows stored yet' : '',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function isStockTransferPayload(array $payload): bool
    {
        $eventName = strtolower((string) data_get($payload, 'event_name', ''));
        $action = strtolower((string) data_get($payload, 'action', ''));
        $entity = strtolower((string) data_get($payload, 'entity', ''));

        return str_contains($eventName, 'stock_transfer')
            || str_contains($eventName, 'stock-transfer')
            || str_contains($action, 'stock_transfer')
            || str_contains($action, 'stock-transfer')
            || str_contains($entity, 'stock_transfer')
            || str_contains($entity, 'stock-transfer');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractStatusValue(array $payload): string
    {
        $candidates = [
            data_get($payload, 'status_code'),
            data_get($payload, 'status'),
            data_get($payload, 'refund_status'),
            data_get($payload, 'purchase_order_status'),
            data_get($payload, 'po_status'),
            data_get($payload, 'data.status_code'),
            data_get($payload, 'data.status'),
            data_get($payload, 'data.refund_status'),
            data_get($payload, 'data.purchase_order_status'),
            data_get($payload, 'data.po_status'),
            data_get($payload, 'data.delivery_status'),
            data_get($payload, 'data.shipment.delivery_status'),
            data_get($payload, 'data.shipment.status'),
            data_get($payload, 'data.shipment.shipping_partner_status'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function extractDataKeys(array $payload): array
    {
        $data = data_get($payload, 'data', []);
        if (!is_array($data) || $data === []) {
            return [];
        }

        if (array_is_list($data)) {
            foreach ($data as $row) {
                if (is_array($row) && $row !== []) {
                    return array_values(array_map('strval', array_keys($row)));
                }
            }

            return [];
        }

        return array_values(array_map('strval', array_keys($data)));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function extractLineKeys(array $payload): array
    {
        $candidates = [
            data_get($payload, 'data.order_items', []),
            data_get($payload, 'data.return_items', []),
            data_get($payload, 'data.stock_transfer_items', []),
            data_get($payload, 'data.transfer_items', []),
            data_get($payload, 'data.items', []),
            data_get($payload, 'data.hub_inventory_items', []),
            data_get($payload, 'data.skus', []),
            data_get($payload, 'data.grn_details.skus', []),
            data_get($payload, 'data.bundle_items', []),
            data_get($payload, 'data.components', []),
            data_get($payload, 'data.bom_items', []),
            data_get($payload, 'data.kit_items', []),
            data_get($payload, 'order_items', []),
            data_get($payload, 'items', []),
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || $candidate === []) {
                continue;
            }

            $keys = [];
            foreach (array_slice($candidate, 0, 3) as $row) {
                if (!is_array($row) || $row === []) {
                    continue;
                }

                $keys = array_values(array_unique(array_merge(
                    $keys,
                    array_values(array_map('strval', array_keys($row)))
                )));
            }

            if ($keys !== []) {
                return $keys;
            }
        }

        return [];
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    private function uniqueValues(array $values): array
    {
        $values = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $values
        ), fn ($value) => $value !== ''));

        return array_values(array_unique($values));
    }

    /**
     * @param Collection<int,array<string,mixed>> $summaries
     */
    private function toMarkdown(Collection $summaries, int $limit): string
    {
        $lines = [];
        $lines[] = '# Live Webhook Payload Audit';
        $lines[] = '';
        $lines[] = 'Updated: ' . now()->toDateTimeString();
        $lines[] = '';
        $lines[] = 'This file summarizes the latest stored Omniful webhook payloads so live tenant field names can be locked down without guessing.';
        $lines[] = '';
        $lines[] = 'Inspection window per webhook: latest ' . $limit . ' rows';
        $lines[] = '';

        foreach ($summaries as $summary) {
            $lines[] = '## ' . $summary['label'];
            $lines[] = '';
            $lines[] = '- Total stored rows: ' . (int) $summary['total'];
            $lines[] = '- Latest received at: ' . ($summary['latest_at'] ?: '-');
            $lines[] = '- Recent event names: ' . $this->joinValues($summary['events']);
            $lines[] = '- Recent statuses: ' . $this->joinValues($summary['statuses']);
            $lines[] = '- Recent actions: ' . $this->joinValues($summary['actions']);
            $lines[] = '- Recent entities: ' . $this->joinValues($summary['entities']);
            $lines[] = '- Observed `data` keys: ' . $this->joinValues($summary['data_keys']);
            $lines[] = '- Observed line-item keys: ' . $this->joinValues($summary['line_keys']);
            $lines[] = '- Sample external IDs: ' . $this->joinValues($summary['external_ids']);
            if (($summary['note'] ?? '') !== '') {
                $lines[] = '- Note: ' . $summary['note'];
            }
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<int,string> $values
     */
    private function joinValues(array $values): string
    {
        return $values === [] ? '-' : implode(', ', $values);
    }

    private function truncateCell(string $value): string
    {
        if (mb_strlen($value) <= 50) {
            return $value;
        }

        return mb_substr($value, 0, 47) . '...';
    }
}
