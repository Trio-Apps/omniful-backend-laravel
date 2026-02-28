# Webhook Execution Checklist

Updated: 2026-03-01

This file is the execution-focused checklist for all Omniful webhooks currently connected in this project.

It is organized based on the official Omniful webhook model, not on a one-endpoint-per-SAP-document assumption.

Sources:
- Omniful docs: <https://docs.omniful.tech/#webhooks-get-real-time-updates>
- Omniful metadata: <https://docs.omniful.tech/view/metadata/2sA35K1Kqo>
- Omniful public collection: <https://docs.omniful.tech/api/collections/34031863/2sA35K1Kqo?segregateAuth=true&versionTag=latest>

## Global Rules From Omniful Docs

- Webhooks are the primary real-time trigger for operational events.
- Omniful sends webhook requests as `POST`.
- Webhook payloads are `application/json`.
- The receiver must return `HTTP 200`.
- The webhook URL must stay reachable.
- Feature toggles in Omniful control whether related API pull endpoints return data or an error.

Operational meaning for this project:
- Transactional SAP actions should be webhook-driven first.
- GET APIs are for lookup, sync, validation, or fallback, not the primary source of truth for live document creation.
- The shared `inventory` sync direction now gates `Inventory`, `Stock Transfer Request`, and `Inwarding` webhook pushes into SAP.

## Active Webhooks In This Project

| Webhook | Route | Current State | Primary Purpose |
| --- | --- | --- | --- |
| Order | `/api/webhooks/omniful/order/whk_7d4c91b2f6e84a9bb3d12c7e` | ACTIVE | Sales + finance transaction trigger |
| Return Order | `/api/webhooks/omniful/return-order/whk_3a6e2d9f4c7841b8a5d0e33f` | ACTIVE | Credit memo / return trigger |
| Purchase Order | `/api/webhooks/omniful/purchase-order/whk_8c1f5e27b94d4a6f9e2c301d` | ACTIVE | Procurement trigger |
| Inventory | `/api/webhooks/omniful/inventory/whk_4b8e1c7d29f34a6ab5d203ef` | ACTIVE | Receiving, adjustments, counting |
| Stock Transfer Request | `/api/webhooks/omniful/stock-transfer/whk_9a2d4f1c6b834e7da0c35b8f` | ACTIVE | Warehouse transfer trigger |
| Product | `/api/webhooks/omniful/product/whk_5e2a7c19d8434fb6a0c21d9e` | ACTIVE | Item / bundle sync trigger |
| Inwarding | `/api/webhooks/omniful/inwarding/whk_1f9b3d6e24c8475aa2e0b91c` | ACTIVE | GRN QC receiving trigger |

## Current Priority

1. Lock down live payload field names for active webhook flows.
2. Confirm real tenant status names for the active lifecycle webhooks.
3. Reduce broad fallbacks only after real tenant payloads confirm the exact fields.

## By Webhook

### 1. Order

Current coverage:
- Prepaid creation flow creates A/R Reserve Invoice.
- Incoming Payment can be created from the same webhook.
- Delivery can be created from the same webhook.
- Delivery now supports line-driven quantity allocation when the webhook carries shipped / delivered / packed / picked quantities.
- COGS journal can be created after delivery.
- Canceled order can create a Credit Note.
- Cancel COGS reversal can be created after the cancel credit note.
- Existing SAP order metadata is updated on later order events.

Already aligned to official docs:
- Reads `status_code` and fallback status fields.
- Reads `shipment.delivery_status`, `shipment.status`, and `shipment.shipping_partner_status` for delivery decisions.
- Reads `payment_method`, `invoice.payment_mode`, and related payment fields.
- Reads `is_cash_on_delivery` as an explicit COD signal.
- Delivery status mapping now explicitly includes documented shipment lifecycle values such as `dispatched`, `out_for_delivery`, `in_transit`, and `partially_delivered`.
- Delivery eligibility now accepts either a delivery-flavored event signal or a documented delivery status, which matches tenants that keep using `order.update.event`.

Still needed:
- One real `shipped` or `delivered` payload from your tenant.
- One real `canceled` or `cancelled` payload from your tenant.
- Confirm whether your tenant needs partial delivery support.
- Confirm whether your tenant needs partial cancel logic on the order webhook.

Real remaining implementation gap:
- No major structural gap is left on `Order` unless your tenant uses a custom field shape outside the documented/default quantity fields.
- The remaining work is mainly live payload confirmation.

Payloads needed now:
- `Order shipped`
- `Order delivered`
- `Order canceled`

Priority:
- Highest

### 2. Return Order

Current coverage:
- Creates A/R Credit Memo from return webhook.
- Can create return COGS reversal journal.
- Supports the official docs sample state `return_shipment_created`.
- The explicit return lifecycle table from the official docs is now included in strict status mapping.
- Aggregates duplicate SKU lines before sending quantities into SAP.
- Credit memo line building now also aggregates duplicate return SKUs inside the SAP client path, so the webhook and posting layers stay consistent.

Still needed:
- One real return-order payload from your tenant.

What we need to confirm:
- Which quantity field is the real source of truth:
  - `return_quantity`
  - `returned_quantity`
  - `delivered_quantity`
- Which field consistently links back to the original order:
  - `order_reference_id`
  - `order_id`
  - `reference_id`

Payloads needed now:
- One real `return_order.update.event` or `return_order.shipment.event`

Priority:
- High

### 3. Purchase Order

Current coverage:
- Purchase Order webhook creates and updates SAP Purchase Orders.
- Status mapping supports create, update, receive, and cancel variants.
- Status extraction now checks `status`, `status_code`, `purchase_order_status`, and `po_status`.
- Rule matching now requires all defined rule conditions instead of broad `event OR status` matching.
- SAP PO creation now accepts documented supplier identifiers beyond `supplier.code`, including `supplier.id`, and derives a deterministic vendor code when needed.
- PO line mapping now supports quantity/price fallbacks such as `ordered_quantity`, `approved_quantity`, `buying_price`, and `cost`.
- PO line item-code extraction now accepts broader Omniful item shapes, and zero-quantity lines are skipped before posting to SAP.

Still needed:
- Validate exact tenant status names for non-create events.

What we need to confirm:
- Whether the tenant uses `status`, `status_code`, or another field consistently.
- Whether `receive` and `cancel` are actually sent as distinct webhook states in your tenant.

Payloads needed now:
- One real create payload if available
- One real receive payload if used
- One real cancel payload if used

Priority:
- Medium

### 4. Inventory

Current coverage:
- `receiving + purchase_order` -> GRPO
- `manual_edit + hub_inventory` -> Goods Receipt / Goods Issue via SAP delta check
- `dispose + inventory_adjustment` -> Goods Issue
- counting-related routes -> Inventory Counting
- The webhook is ignored safely when `inventory` direction is set to `SAP -> Omniful`.
- The documented Omniful shape with `data` as a direct line array is now supported.

Already aligned to official docs:
- The official documented `dispose + inventory_adjustment` path is now mapped.

Still needed:
- Lock the exact quantity field names used in the real tenant.

What we need to confirm:
- Receiving payload line quantities
- Manual edit payload line quantities
- Disposal payload quantity field (`adjusted_quantity` vs another field)
- Counting payload line quantity field

Payloads needed now:
- One real receiving payload
- One real manual edit payload
- One real dispose payload
- One real cycle count / inventory counting payload

Priority:
- High

### 5. Stock Transfer Request

Current coverage:
- Creates normal Stock Transfer.
- Creates two-step in-transit Stock Transfer when enabled.
- Accepts the official docs shape:
  - `sto_request_id`
  - `status=accepted`
  - `order_items[].approved_quantity`
- Prevents duplicate SAP stock transfers for the same stock-transfer request when later lifecycle events arrive.
- The webhook is ignored safely when `inventory` direction is set to `SAP -> Omniful`.
- Duplicate SKU lines are now aggregated before posting to SAP.
- Status extraction now also checks `status_code` variants.

Already aligned to official docs:
- Supports nested warehouse fields like:
  - `source_hub.code`
  - `destination_hub.code`
- Supports `order_items` as a transfer line source.

Still needed:
- Confirm the tenant's actual item and quantity field names.
- Confirm whether in-transit transfer is used in practice.

Payloads needed now:
- One real normal stock transfer request payload
- One real in-transit transfer payload if used

Priority:
- High

### 6. Product

Current coverage:
- Standard product webhook syncs SAP items.
- Bundle / BOM / kit webhook syncs bundle structures.

Still needed:
- Confirm the exact bundle discriminator fields used by your tenant.

What we need to confirm:
- Whether bundles are identified through:
  - `bundle_items`
  - `components`
  - `bom_items`
  - `is_bundle`
  - another tenant-specific field

Payloads needed now:
- One real standard SKU webhook payload
- One real bundle / kit / BOM webhook payload

Priority:
- Medium

### 7. Inwarding

Current coverage:
- `grn.qc.event` with `entity_type=po` creates SAP GRPO.
- Inwarding rows now track SAP status, DocEntry, DocNum, and error.
- Retry from monitoring is supported.
- The webhook is ignored safely when `inventory` direction is set to `SAP -> Omniful`.
- Documented GRN lines using `code` and `qc_passed_items` are now supported.

Already aligned to official docs:
- Uses documented `grn_details.skus[]`
- Uses documented `grn_details.destination_hub_code`
- Uses documented `entity_type=po`

Current limitation:
- The official docs also show `grn.qc.event` samples for `entity_type=sto`.
- This project now acknowledges that shape as documented, but intentionally ignores it because stock transfers are handled by the `Stock Transfer Request` webhook path.

Still needed:
- Confirm the exact quantity field the tenant sends inside `grn_details.skus[]`.

Payloads needed now:
- One real `grn.qc.event` payload

Priority:
- High

## What Is Actually Left

If we focus only on connected webhooks and practical next work, the remaining work is mostly:

1. Payload confirmation
- The biggest remaining task is collecting real tenant payloads and tightening field mapping.

2. Tenant-specific status locking
- Mainly for `Order`, `Return Order`, and `Purchase Order`.

This means the current state is:
- Core webhook flows are connected.
- The main remaining work is payload hardening, not broad new architecture.

## Immediate Collection List

Collect these first, in this exact order:

1. `Order shipped` or `Order delivered`
2. `Order canceled`
3. `Return Order`
4. `Inventory dispose`
5. `Inventory receiving`
6. `Inventory counting`
7. `Stock Transfer Request`
8. `Inwarding grn.qc.event`

Once these are available, the next coding pass should be:

1. Remove unnecessary order-status fallbacks.
2. Tighten quantity-field selection for `Inventory`, `Return Order`, and `Inwarding`.
3. Lock tenant-specific lifecycle statuses for `Purchase Order` and `Stock Transfer Request`.
