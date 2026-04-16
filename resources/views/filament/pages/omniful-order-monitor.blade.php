<x-filament::page>
    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:16px;">
        <div style="border:1px solid #e5e7eb;border-radius:16px;background:#ffffff;padding:18px 20px;box-shadow:0 1px 2px rgba(0,0,0,0.04);">
            <div style="font-size:12px;line-height:16px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;">In Queue Transactions</div>
            <div style="margin-top:8px;font-size:32px;line-height:1;font-weight:700;color:#111827;">{{ number_format($this->getQueuedTransactionsCount()) }}</div>
        </div>

        <div style="border:1px solid #e5e7eb;border-radius:16px;background:#ffffff;padding:18px 20px;box-shadow:0 1px 2px rgba(0,0,0,0.04);">
            <div style="font-size:12px;line-height:16px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;">In Queue Orders</div>
            <div style="margin-top:8px;font-size:32px;line-height:1;font-weight:700;color:#111827;">{{ number_format($this->getQueuedOrdersCount()) }}</div>
        </div>

        <div style="border:1px solid #e5e7eb;border-radius:16px;background:#ffffff;padding:18px 20px;box-shadow:0 1px 2px rgba(0,0,0,0.04);">
            <div style="font-size:12px;line-height:16px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;">Failed Orders</div>
            <div style="margin-top:8px;font-size:32px;line-height:1;font-weight:700;color:#111827;">{{ number_format($this->getFailedOrdersCount()) }}</div>
        </div>
    </div>

    {{ $this->table }}
</x-filament::page>
