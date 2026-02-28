# Omniful Webhook Coverage Matrix

Updated: 2026-02-28

This file maps the actual Omniful webhook topics configured in this project to the SAP capabilities they drive.

Important:
- Omniful is not modeled here as `one webhook per SAP API`.
- A single webhook topic can drive multiple SAP actions depending on `event_name`, `status`, `action`, and `entity`.
- Anything not listed as `webhook-driven` below should be treated as `snapshot/manual sync driven` unless a dedicated transactional flow is added later.

## What the Omniful Docs Support

Across current Omniful integration docs, the stable model is:
- `Orders`, `Catalog`, and `Inventory` are the main data domains.
- `Hub Mapping` and `Order Status Mapping` determine which records and statuses flow into Omniful.
- The docs describe operational topics and mapping rules, not a public one-to-one webhook schema for each downstream SAP document.

This matches the implementation in this repository.

## Implemented Webhook Topics in This Project

| Topic | Route | Current Role | Status |
| --- | --- | --- | --- |
| Order | `/api/webhooks/omniful/order/whk_7d4c91b2f6e84a9bb3d12c7e` | Main sales and finance trigger | ACTIVE |
| Return Order | `/api/webhooks/omniful/return-order/whk_3a6e2d9f4c7841b8a5d0e33f` | Return and credit-note trigger | ACTIVE |
| Purchase Order | `/api/webhooks/omniful/purchase-order/whk_8c1f5e27b94d4a6f9e2c301d` | Procurement trigger | ACTIVE |
| Inventory | `/api/webhooks/omniful/inventory/whk_4b8e1c7d29f34a6ab5d203ef` | Receiving, adjustments, counting | ACTIVE |
| Stock Transfer Request | `/api/webhooks/omniful/stock-transfer/whk_9a2d4f1c6b834e7da0c35b8f` | Warehouse transfer trigger | ACTIVE |
| Product | `/api/webhooks/omniful/product/whk_5e2a7c19d8434fb6a0c21d9e` | Items and bundles trigger | ACTIVE |
| Inwarding | `/api/webhooks/omniful/inwarding/whk_1f9b3d6e24c8475aa2e0b91c` | Currently only stores/logs events | PARTIAL |

## Webhook-Driven Coverage

### Order

| Omniful Signals | SAP Outcomes | Scope Coverage | Notes |
| --- | --- | --- | --- |
| `order.create` / `order.update` + prepaid + initial status (`new`, `created`, `pending`, `confirmed`, `on_hold`) | A/R Reserve Invoice via `/Orders` | BRS + Maaz | Same webhook drives sales and finance outputs |
| Same event after reserve invoice exists | Incoming Payment | BRS + Maaz | Depends on payment config |
| `ship` / `deliver` event or shipped/delivered/completed status | Delivery | BRS + Maaz | Uses existing reserve order as base |
| Same event after delivery exists | COGS JE | BRS | Config dependent |
| `cancel` event or canceled/cancelled status | Credit Note | BRS + Maaz | New direct `order canceled -> Credit Note` path |
| Same canceled flow after credit note exists | Cancel COGS reversal JE | BRS | Config dependent |
| Any order event where SAP order already exists | Sales order metadata sync | Operational | Keeps SAP comments/UDF metadata updated |

### Return Order

| Omniful Signals | SAP Outcomes | Scope Coverage | Notes |
| --- | --- | --- | --- |
| Allowed `return-order` statuses/events | Credit Note | BRS + Maaz | Existing return-based credit memo flow |
| Same event after credit note exists | Return COGS reversal JE | BRS | Config dependent |

### Purchase Order

| Omniful Signals | SAP Outcomes | Scope Coverage | Notes |
| --- | --- | --- | --- |
| Create/update/receive/cancel variants mapped through status rules | Purchase Order logging / create-update flow | BRS + Maaz | Primary procurement webhook |

### Inventory

| Omniful Signals | SAP Outcomes | Scope Coverage | Notes |
| --- | --- | --- | --- |
| `inventory.update.event` + `receiving` + `purchase_order` | GRPO | BRS + Maaz | Uses PO matching and supports multiple GRPOs |
| `inventory.update.event` + `manual_edit` + `hub_inventory` | Goods Receipt / Goods Issue | BRS + Maaz | Delta is computed against SAP on-hand |
| `cycle_count`, `inventory_counting`, `counting` actions/entities | Inventory Counting | BRS + Maaz | New transactional SAP counting flow |

### Stock Transfer Request

| Omniful Signals | SAP Outcomes | Scope Coverage | Notes |
| --- | --- | --- | --- |
| Transfer request payload with source/destination + items | Stock Transfer | BRS + Maaz | Direct stock transfer |
| Same payload with in-transit flags/config | Two-step in-transit stock transfer | BRS | Uses transit warehouse logic |

### Product

| Omniful Signals | SAP Outcomes | Scope Coverage | Notes |
| --- | --- | --- | --- |
| Standard product payload | Item create/update | BRS + Maaz | Driven from product webhook |
| Bundle/BOM/kit payload | Bundle parent + product tree sync | BRS + Maaz | Same webhook, different payload shape |

### Inwarding

| Omniful Signals | SAP Outcomes | Scope Coverage | Notes |
| --- | --- | --- | --- |
| Any inwarding payload | None yet | Gap / future | Event is stored, but no SAP business flow runs yet |

## Snapshot / Manual Sync Driven Coverage

These are covered mainly by sync services, dashboard pages, commands, and background sync, not by webhooks:

- Finance master data:
  `ChartOfAccounts`, `AccountCategories`, `FinancialPeriods`, `Banks`, `BankAccounts`, `Currencies`, `ExchangeRates`, `PaymentTermsTypes`, `ProfitCenters`, `Branches`
- Finance snapshot documents:
  `Invoices`, `DownPayments`, `PurchaseInvoices`, `PurchaseCreditNotes`, `PurchaseDownPayments`, `VendorPayments`
- Sales/Inventory snapshots:
  `Quotations`, `ItemGroups`, `InventoryTransferRequests`, `InventoryPostings`, `ProductionOrders`
- Banking snapshots:
  `Deposits`, `ChecksforPayment`

These should be described as:
- connected
- synced
- visible in dashboard

Not as:
- webhook-driven transactional automation

## Payloads Still Needed From The Real Tenant

To harden the remaining webhook-driven logic against live tenant payload variance, the most useful payloads to collect are:

1. Order webhook payloads
   Already confirmed:
   - prepaid `new_order` as `order.update.event`
   - current tenant uses `status_code`, `payment_method`, `invoice.total`, `invoice.total_paid`, and line `selling_price` / `unit_price`
   Still needed:
   - `shipped` or `delivered`
   - `canceled` or `cancelled`
   Why:
   - confirms actual status names, event names, and payment fields used by your tenant

2. Return Order webhook payload
   Need one real sample with:
   - returned item quantities
   - order reference
   Why:
   - confirms how tenant sends return quantities and which ID links back to the original order

3. Inventory webhook payloads
   Need one real sample for each:
   - receiving against PO
   - manual inventory edit
   - cycle count / inventory counting
   Why:
   - confirms actual `action`, `entity`, and quantity field names used in production

4. Stock Transfer Request payload
   Need one real sample for:
   - normal transfer
   - in-transit transfer if used
   Why:
   - confirms source/destination warehouse fields and transfer item structure

5. Product webhook payloads
   Need one real sample for:
   - standard SKU
   - bundle / kit / BOM
   Why:
   - confirms bundle discriminator fields in the tenant payload

6. Inwarding webhook payload
   Need one real sample
   Why:
   - this is still only logged today; the payload will decide whether it should drive GRPO, inventory posting, or another SAP document in the next phase

## Recommended Next Work

1. Use the real payloads above to lock down field names and remove broad fallbacks where possible.
2. Keep webhook-driven business flows focused on the topics already active in Omniful.
3. Treat all other Maaz-scope APIs as snapshot/manual-sync until a real webhook or direct API trigger is confirmed.
