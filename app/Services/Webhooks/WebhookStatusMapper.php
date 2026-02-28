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

            if ($eventMatch && $statusMatch) {
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

        if ($this->isInventoryCountingRoute($eventName, $action, $entity)) {
            return [
                'mapped' => true,
                'sap_action' => 'inventory_counting',
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

        // Omniful officially documents statuses like `return_shipment_created` on return-order webhooks.
        // The webhook topic itself is specific enough to allow broader return lifecycle statuses.
        if ($eventAllowed && $this->isReturnLifecycleStatus($status)) {
            return ['allowed' => true, 'reason' => null];
        }

        if ($strict) {
            return ['allowed' => false, 'reason' => 'Unmapped return-order status/event'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * @param array<int,string> $paymentSignals
     * @return array{eligible:bool,reason:?string}
     */
    public function resolveOrderInvoiceEligibility(string $eventName, string $status, array $paymentSignals): array
    {
        $eventName = $this->normalize($eventName);
        $status = $this->normalizeOrderStatus($status);
        $strict = (bool) config('omniful.status_mapping.order.strict', false);

        $eventRules = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.order.invoice_event_contains', []));
        $statusRules = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.order.invoice_statuses', []));

        $eventOk = $eventRules === [] || $this->containsAny($eventName, $eventRules);
        $statusOk = $statusRules === [] || in_array($status, $statusRules, true);
        $isPrepaid = $this->isPrepaid($paymentSignals);

        if ($eventOk && $statusOk && $isPrepaid) {
            return ['eligible' => true, 'reason' => null];
        }

        // Some Omniful flows send creation as order.update.event with status_code like new_order.
        if ($isPrepaid && $this->isOrderInitialStatus($status)) {
            return ['eligible' => true, 'reason' => null];
        }

        if ($strict) {
            return ['eligible' => false, 'reason' => 'Unmapped order event/status/payment for AR reserve invoice'];
        }

        return ['eligible' => $isPrepaid, 'reason' => $isPrepaid ? null : 'Order is not prepaid'];
    }

    /**
     * @return array{eligible:bool,reason:?string}
     */
    public function resolveOrderDeliveryEligibility(string $eventName, string $status): array
    {
        $eventName = $this->normalize($eventName);
        $status = $this->normalizeOrderStatus($status);
        $strict = (bool) config('omniful.status_mapping.order.strict', false);

        $eventRules = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.order.delivery_event_contains', []));
        $statusRules = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.order.delivery_statuses', []));

        $eventOk = $eventRules === [] || $this->containsAny($eventName, $eventRules);
        $statusOk = $statusRules === [] || in_array($status, $statusRules, true);

        if ($eventOk || $statusOk) {
            return ['eligible' => true, 'reason' => null];
        }

        if ($strict) {
            return ['eligible' => false, 'reason' => 'Unmapped order event/status for delivery creation'];
        }

        return ['eligible' => false, 'reason' => null];
    }

    /**
     * @return array{eligible:bool,reason:?string}
     */
    public function resolveOrderCreditEligibility(string $eventName, string $status): array
    {
        $eventName = $this->normalize($eventName);
        $status = $this->normalizeOrderStatus($status);
        $strict = (bool) config('omniful.status_mapping.order.strict', false);

        $eventRules = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.order.credit_note_event_contains', []));
        $statusRules = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.order.credit_note_statuses', []));

        $eventSignal = $eventRules !== [] && $this->containsAny($eventName, $eventRules);
        $statusOk = $statusRules !== [] && in_array($status, $statusRules, true);

        if ($statusOk || $eventSignal) {
            return ['eligible' => true, 'reason' => null];
        }

        if ($strict) {
            return ['eligible' => false, 'reason' => 'Unmapped order event/status for credit note creation'];
        }

        return ['eligible' => false, 'reason' => null];
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

    private function isInventoryCountingRoute(string $eventName, string $action, string $entity): bool
    {
        $supportedEntities = ['hub_inventory', 'inventory', 'cycle_count', 'inventory_counting'];
        $entityMatches = $entity === ''
            || in_array($entity, $supportedEntities, true)
            || str_contains($entity, 'inventory');

        if (!$entityMatches) {
            return false;
        }

        $signals = [$action, $eventName];
        foreach ($signals as $signal) {
            if (
                str_contains($signal, 'cycle_count')
                || str_contains($signal, 'inventory_count')
                || str_contains($signal, 'stock_take')
                || str_contains($signal, 'stocktake')
                || str_contains($signal, 'counting')
                || str_contains($signal, 'count')
            ) {
                return true;
            }
        }

        return false;
    }

    private function normalizeOrderStatus(string $value): string
    {
        $normalized = $this->normalize($value);
        if (str_ends_with($normalized, '_order')) {
            return substr($normalized, 0, -6);
        }

        if (str_ends_with($normalized, '-order')) {
            return substr($normalized, 0, -6);
        }

        return $normalized;
    }

    private function isReturnLifecycleStatus(string $status): bool
    {
        if ($status === '') {
            return true;
        }

        return str_contains($status, 'return')
            || str_contains($status, 'refund');
    }

    private function isOrderInitialStatus(string $status): bool
    {
        $initialStatuses = array_map(
            [$this, 'normalize'],
            (array) config('omniful.status_mapping.order.initial_statuses', ['new', 'created', 'pending', 'confirmed', 'on_hold'])
        );

        return in_array($status, $initialStatuses, true);
    }

    /**
     * @param array<int,string> $signals
     */
    private function isPrepaid(array $signals): bool
    {
        $normalized = array_filter(array_map([$this, 'normalize'], $signals), fn ($v) => $v !== '');
        if ($normalized === []) {
            return false;
        }

        $codIndicators = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.order.cod_indicators', []));
        foreach ($normalized as $value) {
            if ($this->containsAny($value, $codIndicators)) {
                return false;
            }
        }

        $prepaidIndicators = array_map([$this, 'normalize'], (array) config('omniful.status_mapping.order.prepaid_indicators', []));
        foreach ($normalized as $value) {
            if ($this->containsAny($value, $prepaidIndicators)) {
                return true;
            }
        }

        return false;
    }
}
