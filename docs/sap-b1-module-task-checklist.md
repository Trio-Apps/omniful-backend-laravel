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
- [x] AR / Customer finance - Dedicated customer finance snapshot sync is implemented.
- [x] Invoices - Dedicated `/Invoices` sync snapshot is implemented, AR reserve invoice flow exists, and a direct manual transactional posting command is implemented.
- [x] CreditNotes - AR credit memo creation is implemented.
- [x] DownPayments - Dedicated `/DownPayments` sync snapshot is implemented.
- [x] IncomingPayments - Customer receipt creation exists and dedicated snapshot sync is implemented.
- [x] PurchaseInvoices - Dedicated `/PurchaseInvoices` sync snapshot is implemented, and a direct manual transactional posting command is implemented.
- [x] PurchaseCreditNotes - Dedicated `/PurchaseCreditNotes` sync snapshot is implemented, and a direct manual transactional posting command is implemented.
- [x] PurchaseDownPayments - Dedicated `/PurchaseDownPayments` sync snapshot is implemented, and a direct manual transactional posting command is implemented.
- [x] VendorPayments - Dedicated `/VendorPayments` sync snapshot is implemented, and a direct manual transactional posting command is implemented.
- [x] Banks / BankAccounts - Dedicated sync methods, models, and tables are implemented.
- [x] Currencies / ExchangeRates - Dedicated currency and exchange rate sync is implemented.
- [x] PaymentTermsTypes - Dedicated sync method, model, and table are implemented.
- [x] ProfitCenters - Dedicated `ProfitCenters` sync is implemented.
- [x] DistributionRules - Cost center sync reads distribution rules.
- [x] Branches - Dedicated branch sync is implemented.

## Sales

- [x] Quotations - Dedicated `/Quotations` sync snapshot is implemented.
- [x] SalesOrders - Sales order / AR reserve order flow is implemented.
- [x] DeliveryNotes - Delivery creation is implemented.
- [x] Invoices - Dedicated `/Invoices` sync snapshot is implemented, and a direct manual transactional posting command is implemented.
- [x] CreditNotes - AR credit memo flow is implemented.
- [x] Returns - Dedicated `/Returns` sync snapshot is implemented, business processing maps returns to AR credit memo, and a direct manual transactional posting command is implemented.
- [x] IncomingPayments - Customer collection flow is implemented.
- [x] BusinessPartners - Customer create/ensure logic is implemented.
- [x] Items - Item create/update sync is implemented.
- [x] ItemGroups - Dedicated item group sync table and service are implemented.
- [x] Warehouses - Warehouse sync is implemented.

## Purchasing

- [x] PurchaseOrders - Purchase order creation/update flow is implemented.
- [x] GRPO / PurchaseDeliveryNotes - Goods receipt PO flow is implemented.
- [x] Suppliers - Supplier create/update sync is implemented.
- [x] PurchaseInvoices - Dedicated `/PurchaseInvoices` sync snapshot is implemented, and a direct manual transactional posting command is implemented.
- [x] PurchaseCreditNotes - Dedicated `/PurchaseCreditNotes` sync snapshot is implemented, and a direct manual transactional posting command is implemented.
- [x] PurchaseDownPayments - Dedicated `/PurchaseDownPayments` sync snapshot is implemented, and a direct manual transactional posting command is implemented.
- [x] VendorPayments - Dedicated `/VendorPayments` sync snapshot is implemented, and a direct manual transactional posting command is implemented.

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
- [x] InventoryCounting - Dedicated `InventoryCountings` snapshot sync exists, and webhook-driven transactional posting is implemented.
- [x] InventoryPosting - Dedicated `InventoryPostings` sync snapshot is implemented, and webhook-driven transactional posting is implemented.
- [x] ProductionOrders - Dedicated `ProductionOrders` sync snapshot is implemented, and a direct manual transactional posting command is implemented.

## Banking

- [x] IncomingPayments - Customer receipt creation is implemented.
- [x] VendorPayments - Dedicated `/VendorPayments` sync snapshot is implemented, and a direct manual transactional posting command is implemented.
- [x] DepositsService / Deposits - Dedicated `/Deposits` sync snapshot is implemented.
- [x] ChecksforPayment - Dedicated `/ChecksforPayment` sync snapshot is implemented.

## Current Ready Count

- Finance ready: 19
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
- [x] Finance leftovers: AR / Customer finance, Currencies / ExchangeRates, ProfitCenters, Branches

## Dashboard Tasks

- [x] Add `SAP Catalog Hub` page as the entry point for SAP dashboard navigation.
- [x] Add `SAP Finance Master` Filament page for finance master snapshots and sync.
- [x] Add `SAP Chart Of Accounts` drill-down page.
- [x] Add `SAP Account Categories` drill-down page.
- [x] Add `SAP Financial Periods` drill-down page.
- [x] Add `SAP Banks` drill-down page.
- [x] Add `SAP Finance Documents` Filament page for finance document snapshots and sync.
- [x] Add `SAP Sales Catalog` Filament page for sales snapshots and sync.
- [x] Add `SAP Inventory Catalog` Filament page for inventory snapshots and sync.
- [x] Add `SAP Banking Catalog` Filament page for banking snapshots and sync.
- [x] Add `SAP Bank Accounts` drill-down page.
- [x] Add `SAP Currencies` drill-down page.
- [x] Add `SAP Exchange Rates` drill-down page.
- [x] Add `SAP Payment Terms` drill-down page.
- [x] Add `SAP Profit Centers` drill-down page.
- [x] Add `SAP Branches` drill-down page.
- [x] Add `SAP Customer Finance` drill-down page.
- [x] Add `SAP Item Groups` drill-down page.
- [x] Add a shared SAP catalog dashboard view for cards + table rendering.
- [x] Add SAP widgets to the main Filament dashboard for snapshot counts and shortcuts.
- [x] Add quick links to the main SAP catalog module pages.
- [x] Add quick links inside `SAP Finance Master` to finance detail pages.
- [x] Reorder the sidebar groups to include `SAP Catalog`.
- [x] Add explicit sort order to older visible `Master Data` and `Monitoring` pages to prevent navigation conflicts.
- [x] Add table filters to the main SAP catalog document pages.
- [x] Add date-range and completeness filters to the main SAP catalog document pages.
- [x] Add `Open SAP Catalog` navigation action to legacy `SAP Items`, `SAP Suppliers`, and `SAP Warehouses` pages.
- [x] Add `Queue SAP Sync` action in `Connections` to run a full SAP catalog sync in the background.
- [x] Add background sync status panel on the `Connections` page.
- [x] Add an Artisan command for background SAP sync dispatch (`sap:queue-catalog-sync`).
