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
     * @return array{event?:\Illuminate\Database\Eloquent\Model,response?:\Illuminate\Http\JsonResponse,duplicate?:bool}
     */
    protected function storeEvent(Request $request, string $eventType, string $eventModel, bool $updateOrders): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            Log::warning('Omniful webhook ignored: empty payload', [
                'event_type' => $eventType,
            ]);

            return ['response' => $this->acknowledgeIgnored('Empty payload')];
        }

        $payload = $request->json()->all();
        if (!is_array($payload) || $payload === []) {
            Log::warning('Omniful webhook ignored: invalid JSON payload', [
                'event_type' => $eventType,
            ]);

            return ['response' => $this->acknowledgeIgnored('Invalid JSON payload')];
        }

        $payloadHash = hash('sha256', $raw);
        $existing = $eventModel::where('payload_hash', $payloadHash)->first();
        if ($existing) {
            return ['event' => $existing, 'duplicate' => true];
        }

        $settings = IntegrationSetting::first();
        $tenantSecret = $settings?->omniful_webhook_secret;
        $sellerSecret = $settings?->omniful_seller_webhook_secret;
        $signatureHeader = config('omniful.webhook_signature_header', 'X-Omniful-Signature');
        $tokenHeader = config('omniful.webhook_token_header', 'X-Omniful-Token');
        $staticHeader = config('omniful.webhook_static_header', 'X-Omniful-Auth');
        $staticToken = config('omniful.webhook_static_token');
        $signature = $signatureHeader ? $request->headers->get($signatureHeader) : null;
        $token = $tokenHeader ? $request->headers->get($tokenHeader) : null;
        $staticValue = $staticHeader ? $request->headers->get($staticHeader) : null;

        $signatureValid = null;
        if ($staticToken) {
            if (!$staticValue || !hash_equals((string) $staticToken, trim((string) $staticValue))) {
                Log::warning('Omniful webhook ignored: invalid static token', [
                    'event_type' => $eventType,
                    'header' => $staticHeader,
                ]);

                return ['response' => $this->acknowledgeIgnored('Invalid static token')];
            }
        } else {
            $hasAnySecret = (bool) ($tenantSecret || $sellerSecret);
            if ($hasAnySecret) {
            if ($signature) {
                $signatureValid = $this->verifySignatureAgainstSecrets($raw, $signature, $tenantSecret, $sellerSecret);
                if (!$signatureValid) {
                    Log::warning('Omniful webhook ignored: invalid signature', [
                        'event_type' => $eventType,
                        'header' => $signatureHeader,
                    ]);

                    return ['response' => $this->acknowledgeIgnored('Invalid signature')];
                }
            } elseif ($token) {
                $signatureValid = $this->verifyTokenAgainstSecrets($token, $tenantSecret, $sellerSecret);
                if (!$signatureValid) {
                    Log::warning('Omniful webhook ignored: invalid token', [
                        'event_type' => $eventType,
                        'header' => $tokenHeader,
                    ]);

                    return ['response' => $this->acknowledgeIgnored('Invalid token')];
                }
            } else {
                Log::warning('Omniful webhook missing signature header', [
                    'header' => $signatureHeader,
                    'token_header' => $tokenHeader,
                    'event_type' => $eventType,
                ]);
                return ['response' => $this->acknowledgeIgnored('Missing signature')];
            }
            }
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

        return ['event' => $event, 'duplicate' => false];
    }

    protected function acknowledgeIgnored(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'ignored' => true,
            'message' => $message,
        ]);
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

    private function verifySignatureAgainstSecrets(string $raw, string $signature, ?string $tenantSecret, ?string $sellerSecret): bool
    {
        if ($tenantSecret && $this->verifySignature($raw, $tenantSecret, $signature)) {
            return true;
        }

        if ($sellerSecret && $this->verifySignature($raw, $sellerSecret, $signature)) {
            return true;
        }

        return false;
    }

    private function verifyTokenAgainstSecrets(string $token, ?string $tenantSecret, ?string $sellerSecret): bool
    {
        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }

        if ($tenantSecret && hash_equals($tenantSecret, $token)) {
            return true;
        }

        if ($sellerSecret && hash_equals($sellerSecret, $token)) {
            return true;
        }

        return false;
    }

    protected function extractExternalId(array $payload): ?string
    {
        $candidates = [
            Arr::get($payload, 'order_id'),
            Arr::get($payload, 'orderId'),
            Arr::get($payload, 'order_number'),
            Arr::get($payload, 'orderNumber'),
            Arr::get($payload, 'return_order_id'),
            Arr::get($payload, 'entity_identifier'),
            Arr::get($payload, 'entity_id'),
            Arr::get($payload, 'display_id'),
            Arr::get($payload, 'reference_id'),
            Arr::get($payload, 'id'),
            Arr::get($payload, 'data.display_id'),
            Arr::get($payload, 'data.order_id'),
            Arr::get($payload, 'data.return_order_id'),
            Arr::get($payload, 'data.order_reference_id'),
            Arr::get($payload, 'data.reference_id'),
            Arr::get($payload, 'data.sto_request_id'),
            Arr::get($payload, 'data.status_reference_id'),
            Arr::get($payload, 'data.entity_identifier'),
            Arr::get($payload, 'data.entity_id'),
            Arr::get($payload, 'data.grn_id'),
            Arr::get($payload, 'data.grn_details.grn_id'),
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
            Arr::get($payload, 'status_code'),
            Arr::get($payload, 'order_status'),
            Arr::get($payload, 'orderStatus'),
            Arr::get($payload, 'state'),
            Arr::get($payload, 'order.status'),
            Arr::get($payload, 'order.state'),
            Arr::get($payload, 'data.status'),
            Arr::get($payload, 'data.status_code'),
            Arr::get($payload, 'data.order_status'),
            Arr::get($payload, 'data.delivery_status'),
            Arr::get($payload, 'data.shipment.delivery_status'),
            Arr::get($payload, 'data.shipment.status'),
            Arr::get($payload, 'data.shipment.shipping_partner_status'),
            Arr::get($payload, 'data.purchase_order_status'),
            Arr::get($payload, 'data.po_status'),
            Arr::get($payload, 'data.return_status'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
