<?php

namespace App\Services\Webhooks;

use App\Models\OmnifulPurchaseOrderEvent;
use App\Services\SapServiceLayerClient;
use Illuminate\Support\Facades\Log;

class PurchaseOrderWebhookService
{
    public function process(OmnifulPurchaseOrderEvent $event): void
    {
        $traceId = 'po-' . $event->id . '-' . now()->format('Hisv');
        $startedAt = microtime(true);
        $steps = [];
        $mark = function (string $step) use (&$steps): void {
            $steps[] = now()->format('H:i:s') . ' ' . $step;
        };

        $mark('start');

        try {
            $mapper = app(WebhookStatusMapper::class);
            $payload = $event->payload ?? [];
            $data = data_get($payload, 'data', []);
            $eventName = (string) data_get($payload, 'event_name', '');
            $status = $this->extractStatus($data);
            $mark('mapping status');
            $mapping = $mapper->resolvePurchaseOrderStatus($eventName, $status, $event->sap_status);

            if (!($mapping['mapped'] ?? false)) {
                $mark('status not mapped -> ignored');
                $event->sap_status = 'ignored';
                $event->sap_error = (string) ($mapping['reason'] ?? 'Ignored: purchase-order status/event not allowed by mapping');
                $event->save();
                return;
            }

            if ($event->external_id) {
                $mark('check existing sap_doc_entry by external_id');
                $existing = OmnifulPurchaseOrderEvent::where('external_id', $event->external_id)
                    ->whereNotNull('sap_doc_entry')
                    ->first();
                if ($existing) {
                    $mark('existing found -> skipped');
                    $event->sap_status = 'skipped';
                    $event->sap_doc_entry = $existing->sap_doc_entry;
                    $event->sap_doc_num = $existing->sap_doc_num;
                    $event->save();
                }
            }

            $client = app(SapServiceLayerClient::class);

            if (!$event->sap_doc_entry) {
                $mark('createPurchaseOrderFromOmniful:start');
                $result = $client->createPurchaseOrderFromOmniful($data);
                $mark('createPurchaseOrderFromOmniful:done');
                $event->sap_status = 'created';
                $event->sap_doc_entry = $result['DocEntry'] ?? null;
                $event->sap_doc_num = $result['DocNum'] ?? null;
                $event->save();
            }

            if ($event->sap_doc_entry) {
                $mark('appendPurchaseOrderComment:start');
                $comment = trim(sprintf(
                    '[%s] %s %s',
                    now()->format('Y-m-d H:i:s'),
                    $eventName ?: 'purchase_order.event',
                    $status ?: ''
                ));
                $client->appendPurchaseOrderComment((int) $event->sap_doc_entry, $comment);
                $mark('appendPurchaseOrderComment:done');
                $event->sap_status = (string) ($mapping['sap_status'] ?? $event->sap_status ?? 'logged');

                $event->save();
            }
        } catch (\Throwable $e) {
            $stepsLine = implode(' | ', $steps);
            Log::error('PO webhook processing failed', [
                'trace_id' => $traceId,
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'message' => $e->getMessage(),
                'steps' => $steps,
            ]);

            throw new \RuntimeException($e->getMessage() . ' | trace_id=' . $traceId . ' | steps=' . $stepsLine, 0, $e);
        } finally {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            Log::info('PO webhook processing summary', [
                'trace_id' => $traceId,
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'duration_ms' => $durationMs,
                'steps' => $steps,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractStatus(array $data): string
    {
        $candidates = [
            data_get($data, 'status'),
            data_get($data, 'status_code'),
            data_get($data, 'purchase_order_status'),
            data_get($data, 'po_status'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
