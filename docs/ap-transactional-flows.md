# A/P Transactional Flows

Updated: 2026-03-01

This project now includes direct manual transactional SAP posting for:

- `PurchaseInvoices`
- `PurchaseCreditNotes`
- `PurchaseDownPayments`

Current trigger mode:
- Manual command-based trigger
- Not webhook-driven

Command:

```bash
php artisan sap:create-ap-document TYPE CARD_CODE ITEM_CODE QUANTITY --price=10 --warehouse=WHS --currency=SAR --doc-date=2026-03-01 --remarks="Manual A/P document"
```

Supported `TYPE` values:
- `invoice`
- `credit-note`
- `down-payment`

What it does:
- Posts the selected A/P document directly into SAP B1.
- Ensures the vendor exists in SAP.
- Ensures the item exists in SAP.
- Ensures warehouse assignment when a warehouse is supplied.
- Stores the created document into the local `sap_finance_documents` table.

Why this mode:
- These APIs are part of the Maaz high-level scope.
- There is no confirmed Omniful webhook topic in the current connected set that can safely be treated as the native trigger for these A/P finance documents.
- The current implementation closes the transactional gap without inventing an undocumented webhook dependency.
