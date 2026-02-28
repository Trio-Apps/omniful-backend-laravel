# Production Order Transactional Flow

Updated: 2026-03-01

This project now includes a direct transactional production-order posting path into SAP B1.

Current trigger mode:
- Manual command-based trigger
- Not webhook-driven

Command:

```bash
php artisan sap:create-production-order ITEM_CODE QUANTITY --warehouse=WHS --due-date=2026-03-01 --remarks="Manual production order"
```

What it does:
- Creates a SAP production order using the SAP Service Layer `/ProductionOrders` endpoint.
- Ensures the item exists in SAP before posting.
- Ensures the item is assigned to the selected warehouse when a warehouse is provided.
- Stores the created production order into the local `sap_inventory_documents` table as `document_type=production_order`.

Why this mode:
- `ProductionOrders` are part of the Maaz high-level scope.
- There is no confirmed Omniful webhook topic in the current connected set that can be safely used as the production-order trigger without live tenant payload confirmation.
- The current implementation therefore closes the transactional gap without inventing an undocumented webhook dependency.
