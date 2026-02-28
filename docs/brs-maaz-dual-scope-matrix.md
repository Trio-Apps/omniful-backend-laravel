# BRS + Maaz Dual Scope Matrix

Updated: 2026-02-28

This file tracks two parallel scopes that now exist in the project:

1. BRS Scope
   Source: `BEON-IT - BRS Document - Omniful.pdf` dated 2026-01-25
   Goal: production business workflows required for the Dkhoon Omniful integration

2. Maaz Scope
   Source: the later high-level API/module list shared after the call with Maaz
   Goal: basic SAP B1 module connection coverage across Finance, Sales, Purchasing, Inventory, and Banking

Important distinction:
- `READY (Business Flow)` means a live operational flow is implemented and tied to a real process, webhook, or direct transaction logic.
- `READY (Basic Connection)` means the project has a usable connection layer such as sync services, local tables, dashboard pages, commands, or snapshot sync, but not necessarily a full operational transaction workflow.
- `CONDITIONAL` means the flow exists but depends on configuration, webhook shape, or tenant-side SAP setup to be considered complete.
- `MISSING` means the requirement is still not covered at the required level.

## Scope Summary

| Scope | Intent | Current Position |
| --- | --- | --- |
| BRS | Close the signed business requirements for Dkhoon | Core workflow coverage is now in place, with operational/config validation still remaining |
| Maaz | Build broad SAP B1 basic module connection on a high level | Broadly covered at connection/snapshot level, but many items are not full transactional workflows |

## BRS Scope

### In Scope and Ready (Business Flow)

| Requirement | Status | Notes |
| --- | --- | --- |
| Bidirectional integration foundation | READY (Business Flow) | SAP -> Omniful master data and Omniful -> SAP transaction flows both exist in the codebase |
| External reference key handling / duplicate prevention | READY (Business Flow) | Payload hashing and external reference handling are implemented |
| New prepaid order -> AR Reserve Invoice | READY (Business Flow) | Implemented through SAP `/Orders` with `ReserveInvoice=tYES` and fallback handling |
| New prepaid order -> Incoming Payment | READY (Business Flow) | Incoming payment creation is implemented |
| Shipped order -> Delivery | READY (Business Flow) | Delivery is created from the reserve order |
| Canceled / Canceled after Delivery -> Credit Note | READY (Business Flow) | `order` webhook can now create a direct SAP credit memo when the order status is canceled/cancelled |
| Items sync (SAP -> Omniful) | READY (Business Flow) | Create and update flows exist |
| Bundles sync (SAP -> Omniful) | READY (Business Flow) | Bundle handling is implemented |
| Item integration control via UDF | READY (Business Flow) | UDF-based inclusion logic exists |
| Warehouses / Hubs sync (SAP -> Omniful) | READY (Business Flow) | Create and update flows exist |
| Warehouse integration control via UDF | READY (Business Flow) | UDF-based inclusion logic exists |
| Suppliers sync (SAP -> Omniful) | READY (Business Flow) | Create and update flows exist |
| Supplier integration control via UDF | READY (Business Flow) | UDF-based inclusion logic exists |
| Purchase Order (Omniful -> SAP) | READY (Business Flow) | Purchase order creation/update flow exists |
| GRN -> GRPO | READY (Business Flow) | GRPO flow exists |
| Support multiple GRPOs per PO | READY (Business Flow) | Explicitly supported in the current PO/GRPO logic |
| Inventory Goods Issue | READY (Business Flow) | Inventory goods issue flow exists |
| Inventory Goods Receipt | READY (Business Flow) | Inventory goods receipt flow exists |
| Stock Transfer | READY (Business Flow) | Stock transfer flow exists |
| In-Transit warehouse handling | READY (Business Flow) | In-transit stock transfer logic exists |

### In Scope and Conditional

| Requirement | Status | Notes |
| --- | --- | --- |
| Card Fees automatic JE | CONDITIONAL | Implemented, but depends on feature flag and account configuration |
| COGS automatic JE | CONDITIONAL | Implemented, but depends on feature flag and account configuration |
| Credit Note with automatic COGS cancellation JE | CONDITIONAL | Return COGS reversal logic exists, but depends on feature flag and account configuration |

### In Scope Code Gaps That Are Now Closed

| Requirement | Status | Notes |
| --- | --- | --- |
| Inventory Counting (transactional workflow) | READY (Business Flow) | `inventory` webhook can now create transactional SAP inventory-counting documents |

### Out of Scope in the BRS (Even if Built Later)

| Item | BRS Status | Current Project Position |
| --- | --- | --- |
| A/R Invoice | OUT OF SCOPE | Snapshot/basic connection exists, but this was not required by the BRS |
| Sales Order (standalone requirement) | OUT OF SCOPE | The project uses `/Orders` for reserve-invoice flow, but standalone Sales Order is not a BRS target |
| Standalone Delivery | OUT OF SCOPE | Delivery is handled only as part of the order flow |
| Without Quantity Return | OUT OF SCOPE | Not targeted |
| Warehouse to Warehouse international transfers | OUT OF SCOPE | Not targeted |

## Maaz Scope

### Finance

#### Ready as Business Flow

| API / Capability | Status | Notes |
| --- | --- | --- |
| JournalEntries | READY (Business Flow) | Manual JEs exist for card fees, COGS, and COGS reversal |
| Invoices | READY (Business Flow) | Implemented as AR Reserve Invoice via `/Orders`, not as a standalone `/Invoices` posting flow |
| CreditNotes | READY (Business Flow) | AR credit memo flow exists |
| IncomingPayments | READY (Business Flow) | Customer receipt creation exists |
| DistributionRules | READY (Business Flow) | Cost center / distribution rule support exists |

#### Ready as Basic Connection

| API / Capability | Status | Notes |
| --- | --- | --- |
| ChartOfAccounts | READY (Basic Connection) | Dedicated sync, table, command, and dashboard page exist |
| AccountCategories | READY (Basic Connection) | Dedicated sync, table, command, and dashboard page exist |
| FinancialPeriods | READY (Basic Connection) | Dedicated sync, table, command, and dashboard page exist |
| AR / Customer finance | READY (Basic Connection) | Snapshot sync exists in local table |
| Invoices (direct snapshot) | READY (Basic Connection) | Snapshot sync exists in local table |
| DownPayments | READY (Basic Connection) | Snapshot sync exists in local table |
| PurchaseInvoices | READY (Basic Connection) | Snapshot sync exists in local table |
| PurchaseCreditNotes | READY (Basic Connection) | Snapshot sync exists in local table |
| PurchaseDownPayments | READY (Basic Connection) | Snapshot sync exists in local table |
| VendorPayments | READY (Basic Connection) | Snapshot sync exists in local table |
| Banks / BankAccounts | READY (Basic Connection) | Dedicated sync, tables, and dashboard pages exist |
| Currencies / ExchangeRates | READY (Basic Connection) | Dedicated sync, tables, and dashboard pages exist |
| PaymentTermsTypes | READY (Basic Connection) | Dedicated sync, table, and dashboard page exist |
| ProfitCenters | READY (Basic Connection) | Dedicated sync, table, and dashboard page exist |
| Branches | READY (Basic Connection) | Dedicated sync, table, and dashboard page exist |

#### Remaining Finance Gaps

| Gap | Status | Notes |
| --- | --- | --- |
| Direct transactional create/update flows for most finance setup APIs | MISSING | Current implementation is mostly snapshot sync, not live write-back workflows |

### Sales

#### Ready as Business Flow

| API / Capability | Status | Notes |
| --- | --- | --- |
| SalesOrders | READY (Business Flow) | Handled through reserve-invoice order flow |
| DeliveryNotes | READY (Business Flow) | Delivery creation exists |
| CreditNotes | READY (Business Flow) | Credit memo flow exists |
| IncomingPayments | READY (Business Flow) | Customer collection flow exists |
| BusinessPartners | READY (Business Flow) | Customer create/ensure logic exists |
| Items | READY (Business Flow) | Item sync exists |
| Warehouses | READY (Business Flow) | Warehouse sync exists |

#### Ready as Basic Connection

| API / Capability | Status | Notes |
| --- | --- | --- |
| Quotations | READY (Basic Connection) | Snapshot sync exists |
| Invoices | READY (Basic Connection) | Snapshot sync exists |
| Returns | READY (Basic Connection) | Snapshot sync exists; business handling still maps returns through credit memo flow |
| ItemGroups | READY (Basic Connection) | Dedicated sync, table, and dashboard page exist |

#### Remaining Sales Gaps

| Gap | Status | Notes |
| --- | --- | --- |
| Standalone direct `/Invoices` posting flow | MISSING | Not implemented as a separate transactional flow |
| Standalone direct `/Returns` transactional posting flow | MISSING | Current business handling is based on return-order to credit memo |

### Purchasing

#### Ready as Business Flow

| API / Capability | Status | Notes |
| --- | --- | --- |
| PurchaseOrders | READY (Business Flow) | Implemented |
| GRPO / PurchaseDeliveryNotes | READY (Business Flow) | Implemented |
| Suppliers | READY (Business Flow) | Implemented as master data sync |

#### Ready as Basic Connection

| API / Capability | Status | Notes |
| --- | --- | --- |
| PurchaseInvoices | READY (Basic Connection) | Snapshot sync exists |
| PurchaseCreditNotes | READY (Basic Connection) | Snapshot sync exists |
| PurchaseDownPayments | READY (Basic Connection) | Snapshot sync exists |
| VendorPayments | READY (Basic Connection) | Snapshot sync exists |

#### Remaining Purchasing Gaps

| Gap | Status | Notes |
| --- | --- | --- |
| Direct transactional A/P finance posting flows | MISSING | Snapshot only at the moment |

### Inventory

#### Ready as Business Flow

| API / Capability | Status | Notes |
| --- | --- | --- |
| Items | READY (Business Flow) | Implemented |
| Warehouses | READY (Business Flow) | Implemented |
| BinLocations | READY (Business Flow) | Bin-aware logic exists |
| ItemWarehouseInfoCollection | READY (Business Flow) | Read/assignment logic exists |
| InventoryGenEntries | READY (Business Flow) | Implemented |
| InventoryGenExits | READY (Business Flow) | Implemented |
| InventoryTransfers | READY (Business Flow) | Implemented via `StockTransfers` |

#### Ready as Basic Connection

| API / Capability | Status | Notes |
| --- | --- | --- |
| ItemGroups | READY (Basic Connection) | Snapshot sync exists |
| InventoryTransferRequests | READY (Basic Connection) | Snapshot sync exists |
| InventoryCounting | READY (Business Flow + Basic Connection) | Snapshot sync exists, and the webhook flow can now create SAP inventory-counting documents |
| InventoryPosting | READY (Basic Connection) | Snapshot sync exists only |
| ProductionOrders | READY (Basic Connection) | Snapshot sync exists only |

#### Remaining Inventory Gaps

| Gap | Status | Notes |
| --- | --- | --- |
| InventoryCounting transactional flow | READY (Business Flow) | Webhook-driven SAP inventory-counting creation is now implemented |
| InventoryPosting transactional flow | MISSING | Snapshot only at the moment |
| ProductionOrders transactional flow | MISSING | Snapshot only at the moment |

### Banking

#### Ready as Business Flow

| API / Capability | Status | Notes |
| --- | --- | --- |
| IncomingPayments | READY (Business Flow) | Implemented |

#### Ready as Basic Connection

| API / Capability | Status | Notes |
| --- | --- | --- |
| VendorPayments | READY (Basic Connection) | Snapshot sync exists |
| DepositsService / Deposits | READY (Basic Connection) | Snapshot sync exists |
| ChecksforPayment | READY (Basic Connection) | Snapshot sync exists |

#### Remaining Banking Gaps

| Gap | Status | Notes |
| --- | --- | --- |
| Direct transactional VendorPayments / Deposits / Checks posting flows | MISSING | Current implementation is read/snapshot oriented |

## Combined Decision View

### Already Safe to Present as Covered

- BRS core transactional flows, with only configuration-dependent JE validation still needing deployment confirmation
- Maaz basic module connection for broad SAP module visibility, sync, local storage, and dashboard control
- Centralized SAP dashboard pages, background sync queue, and connection-level sync trigger

### Should Be Presented Carefully

- Anything marked `READY (Basic Connection)` should be described as `connected / synced / visible`, not as a full transactional automation
- Credit Note coverage for the BRS can now be described as `implemented through return-order and canceled-order webhook flows`
- Journal Entry automation should be described as `implemented but configuration-dependent`

### Real Next Priority if We Want to Satisfy Both Sides Cleanly

1. Validate and enable accounting automations in production config
2. Confirm live tenant payload variants against the real SAP endpoints after deployment
3. Decide which Maaz items must remain snapshot-only and which need full posting workflows

## Practical Conclusion

- If the target is the signed BRS for Dkhoon, the project is close, but not fully closed yet.
- If the target is the broader Maaz API checklist, the project is broadly covered at a basic connection level, but many items are not full operational workflows.
- The two scopes are compatible, but they are not the same scope and should not be reported with the same wording.

## Open Task List

### Priority 1: Shared Gaps (Affect BRS + Maaz)

- [x] Implement transactional `Inventory Counting` flow (`Omniful -> SAP`) instead of snapshot-only coverage.
- [x] Add direct `order canceled -> Credit Note` mapping on the `order` webhook path in addition to the existing `return-order` flow.
- [ ] Validate and enable accounting automations in production config:
  `Card Fees JE`, `COGS JE`, and `COGS Cancellation / Reversal JE`.
- [ ] Run full tenant validation against the live SAP Service Layer to confirm all endpoint names and payload assumptions used by the new snapshot connectors.

### Priority 2: Maaz Scope Transaction Upgrades

- [ ] Decide which Maaz APIs should stay `snapshot/basic connection only` and which must become full transactional posting flows.
- [ ] If required, implement transactional `InventoryPosting` flow (`Omniful -> SAP`) instead of snapshot-only coverage.
- [ ] If required, implement transactional `ProductionOrders` flow instead of snapshot-only coverage.
- [ ] If required, implement direct transactional finance posting flows for:
  `PurchaseInvoices`, `PurchaseCreditNotes`, `PurchaseDownPayments`, and `VendorPayments`.
- [ ] If required, implement direct transactional banking posting flows for:
  `VendorPayments`, `Deposits`, and `ChecksforPayment`.
- [ ] If required, implement standalone direct sales posting flows for:
  `Invoices` and `Returns` as native transactional posts, not only reserve-order / return-order derived behavior.

### Priority 3: API Surface and Cross-System Use

- [ ] Add explicit Laravel REST endpoints for the new snapshot/master-data tables if other projects need to consume these records through this application.
- [ ] Define which modules should expose read-only APIs and which should expose write/trigger APIs.
- [ ] Add lightweight status/history endpoints for background SAP sync jobs if external systems need sync monitoring.

### Priority 4: Operational Closure

- [ ] Ensure all 2026-02-28 migrations are applied in production.
- [ ] Clear application caches after deploy (`config`, `route`, `view`, and compiled files).
- [ ] Ensure the queue worker cron is active so `Queue SAP Sync` from the `Connections` page runs in the background.
- [ ] If scheduled synchronization is needed, enable periodic `sap:queue-catalog-sync` cron execution.

### Suggested Execution Order

1. Validate accounting configuration and enable JE automations.
2. Run live tenant validation for the new webhook-driven inventory counting and canceled-order credit note paths.
3. Decide which Maaz items remain snapshot-only versus full workflows.
4. Build only the transactional upgrades that are explicitly required after that decision.
