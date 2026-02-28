# Final BRS + Maaz Closure List

Updated: 2026-03-01

This file is the final execution list for closing the remaining work across the two active scopes:

1. `BRS Scope`
   Goal: operational closure of the signed Dkhoon integration requirements.
2. `Maaz Scope`
   Goal: final decision and execution path for the broader SAP B1 high-level module coverage.

Important:
- The project is now broadly implemented at code level.
- The main remaining work is no longer broad architecture.
- The remaining work is mainly:
  - production validation,
  - live payload confirmation,
  - configuration enablement,
  - and scope decisions for which Maaz items must become full transactional flows.

Support commands now available:
- `php artisan webhooks:audit-payloads`
- `php artisan integration:check-readiness`

## 1. Must Do To Close The BRS

These items are the true remaining tasks for BRS closure.

- [ ] Collect real tenant payloads for all active operational webhooks and lock the field mapping to the live schema.
- [ ] Run `php artisan webhooks:audit-payloads` on the target environment and export the current live webhook summary.
- [ ] Validate the live tenant status values used by:
  - `Order`
  - `Return Order`
  - `Purchase Order`
  - `Inventory`
  - `Stock Transfer Request`
  - `Inwarding`
- [ ] Enable and validate production accounting configuration for:
  - `Card Fees JE`
  - `COGS JE`
  - `COGS Cancellation / Reversal JE`
- [ ] Run `php artisan integration:check-readiness` on the target environment and resolve all `warning` / `missing` results.
- [ ] Run end-to-end validation on the live SAP Service Layer tenant for all BRS-critical flows.
- [ ] Confirm that the production queue worker and webhook endpoints are active and stable.
- [ ] Confirm that all required migrations are applied in production.
- [ ] Clear application caches after deploy and restart queue workers after release.

## 2. Payloads Required To Lock The BRS

These payloads are the immediate inputs still needed from the live tenant.

- [ ] `Order shipped`
- [ ] `Order delivered`
- [ ] `Order canceled`
- [ ] `Return Order`
- [ ] `Purchase Order` receive event
- [ ] `Purchase Order` cancel event
- [ ] `Inventory receiving`
- [ ] `Inventory manual_edit`
- [ ] `Inventory dispose`
- [ ] `Inventory counting`
- [ ] `Stock Transfer Request`
- [ ] `Inwarding grn.qc.event`
- [ ] `Product bundle / BOM / kit`

## 3. BRS Close-Out Criteria

The BRS can be treated as operationally closed when all of the following are true:

- [ ] Live webhook payloads have been confirmed and field mappings narrowed where needed.
- [ ] Accounting automations are enabled and tested in production configuration.
- [ ] Live SAP posting succeeds for the BRS critical scenarios.
- [ ] No required BRS flow remains dependent on unverified fallback logic.

## 4. Maaz Decision Items

These are not broad code gaps anymore. These are scope decisions that must be made explicitly.

- [ ] Decide which Maaz items remain `Basic Connection` only.
- [ ] Decide which Maaz items must be upgraded to full `Transactional Posting` flows.
- [x] Decide whether other systems need public Laravel APIs for the new snapshot/master-data tables. Current decision: expose selected token-protected read-only APIs.
- [x] Decide whether external systems need sync status/history APIs for background SAP sync jobs. Current decision: expose token-protected read-only sync status endpoints.
- [x] Define the current external API scope. Snapshot/master-data and sync monitoring are read-only; write/trigger APIs remain deferred.

## 5. Maaz Items That Are Still Snapshot-Only

These items are connected and visible, but still snapshot-oriented unless explicitly upgraded.

### Finance

- [x] Direct transactional `PurchaseInvoices`
- [x] Direct transactional `PurchaseCreditNotes`
- [x] Direct transactional `PurchaseDownPayments`
- [x] Direct transactional `VendorPayments`

### Sales

- [x] Standalone direct transactional `Invoices`
- [x] Standalone direct transactional `Returns`

### Inventory

- [x] Direct transactional `InventoryPosting`
- [x] Direct transactional `ProductionOrders`

### Banking

- [x] Direct transactional `VendorPayments`
- [ ] Direct transactional `Deposits`
- [ ] Direct transactional `ChecksforPayment`

## 6. Phase 2 Build Tasks (Only If Maaz Requires Full Posting)

Build these only after the scope decision is explicit.

- [x] Implement transactional `InventoryPosting` workflow.
- [x] Implement transactional `ProductionOrders` workflow.
- [x] Implement transactional A/P finance posting workflows.
- [ ] Implement transactional banking posting workflows.
- [x] Implement standalone direct sales posting for native SAP `Invoices`.
- [x] Implement standalone direct sales posting for native SAP `Returns`.
- [x] Add REST API endpoints for selected snapshot/master-data tables if they must be consumed by another project. Implemented under `/api/integration/sap/resources`.
- [x] Add lightweight monitoring APIs for background sync jobs if external systems need status visibility. Implemented under `/api/integration/sap/sync-status`.

## 7. Deployment / Operations Checklist

- [ ] Pull the latest code in production.
- [ ] Run `php artisan migrate --force`.
- [ ] Run `php artisan optimize:clear`.
- [ ] Run `php artisan queue:restart` if a long-running worker is active.
- [ ] Confirm the queue cron is active for background jobs.
- [ ] Confirm the optional scheduled SAP sync cron is active if periodic sync is required.

## 8. Recommended Execution Order

1. Collect the missing live webhook payloads.
2. Validate and enable production accounting configuration.
3. Run live end-to-end BRS validation on the tenant.
4. Mark the BRS as operationally closed once the live checks pass.
5. Make explicit scope decisions for Maaz items.
6. Build only the transactional upgrades that are actually required after that decision.

## 9. Practical Final Status

- `BRS`: code coverage is effectively in place; the remaining work is production validation and payload confirmation.
- `Maaz`: basic connection coverage is in place; the remaining work is deciding which items must move from snapshot/basic mode into full transactional automation.
