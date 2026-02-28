# SAP B1 Module Task Checklist

Updated: 2026-02-28

Legend:
- `[x]` Ready in the current project
- `[ ]` Not ready yet, partial, or still needs SAP-side validation

Note:
- A generic basic connection scaffold for all listed SAP resources now exists in `config/sap_catalog.php`, but unchecked items below are not counted as "ready" until they are validated against the target SAP tenant and/or wired into dedicated flows.

## Finance

- [x] JournalEntries - Manual journal creation exists for card fees, COGS, and return COGS reversal.
- [ ] ChartOfAccounts - Basic catalog path configured; no dedicated validated flow yet.
- [ ] AccountCategories - Basic catalog path configured; needs SAP validation.
- [ ] FinancialPeriods - Basic catalog path configured; needs SAP validation.
- [ ] AR / Customer finance - Covered indirectly through order/customer flows, not as a dedicated finance module.
- [ ] Invoices - AR reserve invoice is created through `/Orders` with `ReserveInvoice`, not as a dedicated `/Invoices` business flow.
- [x] CreditNotes - AR credit memo creation is implemented.
- [ ] DownPayments - Basic catalog path configured; no dedicated flow yet.
- [x] IncomingPayments - Customer receipt creation is implemented.
- [ ] PurchaseInvoices - Basic catalog path configured; no dedicated flow yet.
- [ ] PurchaseCreditNotes - Basic catalog path configured; no dedicated flow yet.
- [ ] PurchaseDownPayments - Basic catalog path configured; no dedicated flow yet.
- [ ] VendorPayments - Basic catalog path configured; no dedicated flow yet.
- [ ] Banks / BankAccounts - Basic catalog paths configured; no dedicated validated flow yet.
- [ ] Currencies / ExchangeRates - Currency validation exists; full dedicated sync/use is not complete.
- [ ] PaymentTermsTypes - Basic catalog path configured; no dedicated flow yet.
- [ ] ProfitCenters - No dedicated `ProfitCenters` integration yet.
- [x] DistributionRules - Cost center sync reads distribution rules.
- [ ] Branches - Basic catalog path configured; no dedicated flow yet.

## Sales

- [ ] Quotations - Basic catalog path configured; no dedicated business flow yet.
- [x] SalesOrders - Sales order / AR reserve order flow is implemented.
- [x] DeliveryNotes - Delivery creation is implemented.
- [ ] Invoices - No dedicated `/Invoices` create/update flow is implemented yet.
- [x] CreditNotes - AR credit memo flow is implemented.
- [ ] Returns - Return processing currently maps to AR credit memo, not a dedicated `/Returns` document flow.
- [x] IncomingPayments - Customer collection flow is implemented.
- [x] BusinessPartners - Customer create/ensure logic is implemented.
- [x] Items - Item create/update sync is implemented.
- [ ] ItemGroups - Basic catalog path configured; no dedicated flow yet.
- [x] Warehouses - Warehouse sync is implemented.

## Purchasing

- [x] PurchaseOrders - Purchase order creation/update flow is implemented.
- [x] GRPO / PurchaseDeliveryNotes - Goods receipt PO flow is implemented.
- [x] Suppliers - Supplier create/update sync is implemented.
- [ ] PurchaseInvoices - Basic catalog path configured; no dedicated business flow yet.
- [ ] PurchaseCreditNotes - Basic catalog path configured; no dedicated business flow yet.
- [ ] PurchaseDownPayments - Basic catalog path configured; no dedicated business flow yet.
- [ ] VendorPayments - Basic catalog path configured; no dedicated business flow yet.

## Inventory

- [x] Items - Item sync is implemented.
- [ ] ItemGroups - Basic catalog path configured; no dedicated flow yet.
- [x] Warehouses - Warehouse sync is implemented.
- [x] BinLocations - Bin-aware inventory logic is implemented.
- [x] ItemWarehouseInfoCollection - Item/warehouse reads and assignment logic are implemented.
- [x] InventoryGenEntries - Goods receipt inventory increase is implemented.
- [x] InventoryGenExits - Goods issue inventory decrease is implemented.
- [x] InventoryTransfers - Implemented through `StockTransfers`.
- [ ] InventoryTransferRequests - Basic catalog path configured; no business flow yet.
- [ ] InventoryCounting - Basic catalog path configured; no business flow yet.
- [ ] InventoryPosting - Basic catalog path configured; no business flow yet.
- [ ] ProductionOrders - Basic catalog path configured; no business flow yet.

## Banking

- [x] IncomingPayments - Customer receipt creation is implemented.
- [ ] VendorPayments - Basic catalog path configured; no dedicated business flow yet.
- [ ] DepositsService / Deposits - Basic catalog path configured; no dedicated business flow yet.
- [ ] ChecksforPayment - Basic catalog path configured; no dedicated business flow yet.

## Current Ready Count

- Finance ready: 4
- Sales ready: 7
- Purchasing ready: 3
- Inventory ready: 7
- Banking ready: 1

## Next Recommended Build Order

- [ ] Finance master data: ChartOfAccounts, FinancialPeriods, Banks / BankAccounts, PaymentTermsTypes
- [ ] Direct finance docs: PurchaseInvoices, VendorPayments, DownPayments
- [ ] Sales gaps: Quotations, direct Invoices, Returns, ItemGroups
- [ ] Inventory gaps: InventoryTransferRequests, InventoryCounting, InventoryPosting, ProductionOrders
- [ ] Banking gaps: Deposits, ChecksforPayment
