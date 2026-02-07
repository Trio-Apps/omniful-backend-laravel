<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\IntegrationSetting;
use App\Models\OmnifulOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class OmnifulWebhookBase
{
    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $eventModel
     */
    protected function handle(Request $request, string $eventType, string $eventModel, bool $updateOrders): \Illuminate\Http\JsonResponse
    {
        $result = $this->storeEvent($request, $eventType, $eventModel, $updateOrders);

        if (isset($result['response'])) {
            return $result['response'];
        }

        return response()->json(['status' => 'ok', 'id' => $result['event']->id]);
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $eventModel
     * @return array{event?:\Illuminate\Database\Eloquent\Model,response?:\Illuminate\Http\JsonResponse}
     */
    protected function storeEvent(Request $request, string $eventType, string $eventModel, bool $updateOrders): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return ['response' => response()->json(['message' => 'Empty payload'], 400)];
        }

        $payload = $request->json()->all();
        if (!is_array($payload) || $payload === []) {
            return ['response' => response()->json(['message' => 'Invalid JSON payload'], 400)];
        }

        $payloadHash = hash('sha256', $raw);
        $existing = $eventModel::where('payload_hash', $payloadHash)->first();
        if ($existing) {
            return ['event' => $existing];
        }

        $settings = IntegrationSetting::first();
        $secret = $settings?->omniful_webhook_secret;
        $signatureHeader = config('omniful.webhook_signature_header', 'X-Omniful-Signature');
        $signature = $signatureHeader ? $request->headers->get($signatureHeader) : null;

        $signatureValid = null;
        if ($secret && $signature) {
            $signatureValid = $this->verifySignature($raw, $secret, $signature);
            if (!$signatureValid) {
                return ['response' => response()->json(['message' => 'Invalid signature'], 401)];
            }
        }

        if ($secret && !$signature) {
            Log::warning('Omniful webhook missing signature header', [
                'header' => $signatureHeader,
                'event_type' => $eventType,
            ]);
            return ['response' => response()->json(['message' => 'Missing signature'], 401)];
        }

        try {
            $event = $eventModel::create([
                'external_id' => $this->extractExternalId($payload),
                'payload' => $payload,
                'payload_hash' => $payloadHash,
                'headers' => $request->headers->all(),
                'signature_valid' => $signatureValid,
                'received_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $event = $eventModel::where('payload_hash', $payloadHash)->first();
            if (!$event) {
                throw $e;
            }
        }

        if ($updateOrders && $event->external_id) {
            OmnifulOrder::updateOrCreate(
                ['external_id' => $event->external_id],
                [
                    'omniful_status' => $this->extractStatus($payload),
                    'last_event_type' => $eventType,
                    'last_event_at' => now(),
                    'last_payload' => $payload,
                    'last_headers' => $request->headers->all(),
                ]
            );
        }

        return ['event' => $event];
    }

    protected function verifySignature(string $raw, string $secret, string $signature): bool
    {
        $algo = config('omniful.webhook_signature_algo', 'sha256');
        $expected = hash_hmac($algo, $raw, $secret);

        $normalized = trim($signature);
        if (Str::contains($normalized, '=')) {
            $parts = explode('=', $normalized, 2);
            $normalized = trim($parts[1]);
        }

        return hash_equals($expected, $normalized);
    }

    protected function extractExternalId(array $payload): ?string
    {
        $candidates = [
            Arr::get($payload, 'order_id'),
            Arr::get($payload, 'orderId'),
            Arr::get($payload, 'order_number'),
            Arr::get($payload, 'orderNumber'),
            Arr::get($payload, 'id'),
            Arr::get($payload, 'data.display_id'),
            Arr::get($payload, 'data.order_id'),
            Arr::get($payload, 'data.id'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    protected function extractStatus(array $payload): ?string
    {
        $candidates = [
            Arr::get($payload, 'status'),
            Arr::get($payload, 'order_status'),
            Arr::get($payload, 'orderStatus'),
            Arr::get($payload, 'state'),
            Arr::get($payload, 'order.status'),
            Arr::get($payload, 'order.state'),
            Arr::get($payload, 'data.status'),
            Arr::get($payload, 'data.order_status'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
