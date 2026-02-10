<?php

namespace App\Services\Webhooks;

class WebhookStatusMapper
{
    /**
     * @return array{mapped:bool,sap_status:string,reason:?string}
     */
    public function resolvePurchaseOrderStatus(string $eventName, string $status, ?string $fallback = null): array
    {
        $eventName = $this->normalize($eventName);
        $status = $this->normalize($status);
        $rules = (array) config('omniful.status_mapping.purchase_order.rules', []);
        $strict = (bool) config('omniful.status_mapping.purchase_order.strict', false);

        foreach ($rules as $rule) {
            $eventContains = array_map([$this, 'normalize'], (array) ($rule['event_contains'] ?? []));
            $statuses = array_map([$this, 'normalize'], (array) ($rule['statuses'] ?? []));

            $eventMatch = $eventContains === [] || $this->containsAny($eventName, $eventContains);
            $statusMatch = $statuses === [] || in_array($status, $statuses, true);

            if ($eventMatch || $statusMatch) {
                return [
                    'mapped' => true,
                    'sap_status' => (string) ($rule['sap_status'] ?? $this->defaultPurchaseOrderStatus($fallback)),
                    'reason' => null,
                ];
            }
        }

        if ($strict) {
            return [
                'mapped' => false,
                'sap_status' => $this->defaultPurchaseOrderStatus($fallback),
                'reason' => 'Unmapped purchase-order status/event',
            ];
        }

        return [
            'mapped' => true,
            'sap_status' => $this->defaultPurchaseOrderStatus($fallback),
            'reason' => null,
        ];
    }

    /**
     * @return array{mapped:bool,sap_action:?string,key:string,reason:?string}
     */
    public function mapInventoryRoute(string $eventName, string $action, string $entity): array
    {
        $eventName = $this->normalize($eventName);
        $action = $this->normalize($action);
        $entity = $this->normalize($entity);
        $key = $eventName . '|' . $action . '|' . $entity;

        $routes = (array) config('omniful.status_mapping.inventory.routes', []);
        $strict = (bool) config('omniful.status_mapping.inventory.strict', false);
        $sapAction = $routes[$key] ?? null;
        if ($sapAction) {
            return [
                'mapped' => true,
                'sap_action' => (string) $sapAction,
                'key' => $key,
                'reason' => null,
            ];
        }

        return [
            'mapped' => !$strict ? true : false,
            'sap_action' => null,
            'key' => $key,
            'reason' => $strict ? 'Unmapped inventory route' : null,
        ];
    }

    /**
     * @return array{allowed:bool,reason:?string}
     */
    public function validateReturnOrder(string $eventName, string $status): array
    {
        $eventName = $this->normalize($eventName);
        $status = $this->normalize($status);
        $strict = (bool) config('omniful.status_mapping.return_order.strict', false);

        $allowedStatuses = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.return_order.allowed_statuses', []));
        $allowedEventContains = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.return_order.allowed_event_contains', []));

        $statusAllowed = $allowedStatuses === [] || in_array($status, $allowedStatuses, true);
        $eventAllowed = $allowedEventContains === [] || $this->containsAny($eventName, $allowedEventContains);

        if ($statusAllowed && $eventAllowed) {
            return ['allowed' => true, 'reason' => null];
        }

        if ($strict) {
            return ['allowed' => false, 'reason' => 'Unmapped return-order status/event'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    private function defaultPurchaseOrderStatus(?string $fallback): string
    {
        if ($fallback && $fallback !== '') {
            return $fallback;
        }

        return (string) config('omniful.status_mapping.purchase_order.default_sap_status', 'logged');
    }

    /**
     * @param array<int,string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
