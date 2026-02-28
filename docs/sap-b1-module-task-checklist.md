# SAP B1 Module Task Checklist

Updated: 2026-02-28

Legend:
- `[x]` Ready in the current project
- `[ ]` Not ready yet, partial, or still needs SAP-side validation

Note:
- A generic basic connection scaffold for all listed SAP resources now exists in `config/sap_catalog.php`, but unchecked items below are not counted as "ready" until they are validated against the target SAP tenant and/or wired into dedicated flows.

## Finance

- [x] JournalEntries - Manual journal creation exists for card fees, COGS, and return COGS reversal.
- [x] ChartOfAccounts - Dedicated sync method, model, and table are implemented.
- [x] AccountCategories - Dedicated sync method, model, and table are implemented.
- [x] FinancialPeriods - Dedicated sync method, model, and table are implemented.
- [ ] AR / Customer finance - Covered indirectly through order/customer flows, not as a dedicated finance module.
- [x] Invoices - Dedicated `/Invoices` sync snapshot is implemented, and AR reserve invoice flow already exists.
- [x] CreditNotes - AR credit memo creation is implemented.
- [x] DownPayments - Dedicated `/DownPayments` sync snapshot is implemented.
- [x] IncomingPayments - Customer receipt creation exists and dedicated snapshot sync is implemented.
- [x] PurchaseInvoices - Dedicated `/PurchaseInvoices` sync snapshot is implemented.
- [x] PurchaseCreditNotes - Dedicated `/PurchaseCreditNotes` sync snapshot is implemented.
- [x] PurchaseDownPayments - Dedicated `/PurchaseDownPayments` sync snapshot is implemented.
- [x] VendorPayments - Dedicated `/VendorPayments` sync snapshot is implemented.
- [x] Banks / BankAccounts - Dedicated sync methods, models, and tables are implemented.
- [ ] Currencies / ExchangeRates - Currency validation exists; full dedicated sync/use is not complete.
- [x] PaymentTermsTypes - Dedicated sync method, model, and table are implemented.
- [ ] ProfitCenters - No dedicated `ProfitCenters` integration yet.
- [x] DistributionRules - Cost center sync reads distribution rules.
- [ ] Branches - Basic catalog path configured; no dedicated flow yet.

## Sales

- [x] Quotations - Dedicated `/Quotations` sync snapshot is implemented.
- [x] SalesOrders - Sales order / AR reserve order flow is implemented.
- [x] DeliveryNotes - Delivery creation is implemented.
- [x] Invoices - Dedicated `/Invoices` sync snapshot is implemented.
- [x] CreditNotes - AR credit memo flow is implemented.
- [x] Returns - Dedicated `/Returns` sync snapshot is implemented, while business processing still maps returns to AR credit memo.
- [x] IncomingPayments - Customer collection flow is implemented.
- [x] BusinessPartners - Customer create/ensure logic is implemented.
- [x] Items - Item create/update sync is implemented.
- [x] ItemGroups - Dedicated item group sync table and service are implemented.
- [x] Warehouses - Warehouse sync is implemented.

## Purchasing

- [x] PurchaseOrders - Purchase order creation/update flow is implemented.
- [x] GRPO / PurchaseDeliveryNotes - Goods receipt PO flow is implemented.
- [x] Suppliers - Supplier create/update sync is implemented.
- [x] PurchaseInvoices - Dedicated `/PurchaseInvoices` sync snapshot is implemented.
- [x] PurchaseCreditNotes - Dedicated `/PurchaseCreditNotes` sync snapshot is implemented.
- [x] PurchaseDownPayments - Dedicated `/PurchaseDownPayments` sync snapshot is implemented.
- [x] VendorPayments - Dedicated `/VendorPayments` sync snapshot is implemented.

## Inventory

- [x] Items - Item sync is implemented.
- [x] ItemGroups - Dedicated item group sync table and service are implemented.
- [x] Warehouses - Warehouse sync is implemented.
- [x] BinLocations - Bin-aware inventory logic is implemented.
- [x] ItemWarehouseInfoCollection - Item/warehouse reads and assignment logic are implemented.
- [x] InventoryGenEntries - Goods receipt inventory increase is implemented.
- [x] InventoryGenExits - Goods issue inventory decrease is implemented.
- [x] InventoryTransfers - Implemented through `StockTransfers`.
- [x] InventoryTransferRequests - Dedicated `InventoryTransferRequests` sync snapshot is implemented.
- [x] InventoryCounting - Dedicated `InventoryCountings` sync snapshot is implemented.
- [x] InventoryPosting - Dedicated `InventoryPostings` sync snapshot is implemented.
- [x] ProductionOrders - Dedicated `ProductionOrders` sync snapshot is implemented.

## Banking

- [x] IncomingPayments - Customer receipt creation is implemented.
- [x] VendorPayments - Dedicated `/VendorPayments` sync snapshot is implemented.
- [x] DepositsService / Deposits - Dedicated `/Deposits` sync snapshot is implemented.
- [x] ChecksforPayment - Dedicated `/ChecksforPayment` sync snapshot is implemented.

## Current Ready Count

- Finance ready: 15
- Sales ready: 11
- Purchasing ready: 7
- Inventory ready: 12
- Banking ready: 4

## Next Recommended Build Order

- [x] Finance master data: ChartOfAccounts, FinancialPeriods, Banks / BankAccounts, PaymentTermsTypes
- [x] Direct finance docs: PurchaseInvoices, VendorPayments, DownPayments
- [x] Sales gaps: Quotations, direct Invoices, Returns, ItemGroups
- [x] Inventory gaps: InventoryTransferRequests, InventoryCounting, InventoryPosting, ProductionOrders
- [x] Banking gaps: Deposits, ChecksforPayment
