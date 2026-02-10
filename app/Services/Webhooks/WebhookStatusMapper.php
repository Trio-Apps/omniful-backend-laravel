<?php

namespace App\Services\Webhooks;

class WebhookStatusMapper
{
    public function mapPurchaseOrderStatus(string $eventName, string $status, ?string $fallback = null): string
    {
        $eventName = $this->normalize($eventName);
        $status = $this->normalize($status);
        $rules = (array) config('omniful.status_mapping.purchase_order.rules', []);

        foreach ($rules as $rule) {
            $eventContains = array_map([$this, 'normalize'], (array) ($rule['event_contains'] ?? []));
            $statuses = array_map([$this, 'normalize'], (array) ($rule['statuses'] ?? []));

            $eventMatch = $eventContains === [] || $this->containsAny($eventName, $eventContains);
            $statusMatch = $statuses === [] || in_array($status, $statuses, true);

            if ($eventMatch || $statusMatch) {
                return (string) ($rule['sap_status'] ?? $this->defaultPurchaseOrderStatus($fallback));
            }
        }

        return $this->defaultPurchaseOrderStatus($fallback);
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
            'mapped' => false,
            'sap_action' => null,
            'key' => $key,
            'reason' => 'Unmapped inventory route',
        ];
    }

    public function canProcessReturnOrder(string $eventName, string $status): bool
    {
        $eventName = $this->normalize($eventName);
        $status = $this->normalize($status);

        $allowedStatuses = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.return_order.allowed_statuses', []));
        $allowedEventContains = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.return_order.allowed_event_contains', []));

        $statusAllowed = $allowedStatuses === [] || in_array($status, $allowedStatuses, true);
        $eventAllowed = $allowedEventContains === [] || $this->containsAny($eventName, $allowedEventContains);

        return $statusAllowed && $eventAllowed;
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

