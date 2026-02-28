# A/R Transactional Flows

Updated: 2026-03-01

This project now includes direct manual transactional SAP posting for:

- native `Invoices`
- native `Returns`

Current trigger mode:
- Manual command-based trigger
- Not webhook-driven

Command:

```bash
php artisan sap:create-ar-document TYPE CARD_CODE ITEM_CODE QUANTITY --price=10 --warehouse=WHS --currency=SAR --doc-date=2026-03-01 --remarks="Manual A/R document"
```

Supported `TYPE` values:
- `invoice`
- `return`

What it does:
- Posts the selected A/R document directly into SAP B1.
- Ensures the customer exists in SAP.
- Ensures the item exists in SAP and is sellable.
- Ensures warehouse assignment when a warehouse is supplied.
- Stores the created document into the local `sap_sales_documents` table.

Why this mode:
- These APIs are part of the Maaz high-level scope.
- The current connected Omniful webhook set already covers reserve-invoice and return-derived flows, but not a confirmed native webhook trigger for standalone SAP `Invoices` and `Returns`.
- The current implementation closes the direct transactional gap without inventing an undocumented webhook dependency.
