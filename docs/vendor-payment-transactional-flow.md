# Vendor Payment Transactional Flow

Updated: 2026-03-01

This project now includes a direct manual transactional SAP posting path for `VendorPayments`.

Current trigger mode:
- Manual command-based trigger
- Not webhook-driven

Command:

```bash
php artisan sap:create-vendor-payment CARD_CODE INVOICE_DOC_ENTRY AMOUNT --transfer-account=ACCOUNT --invoice-type=18 --transfer-date=2026-03-01 --remarks="Manual vendor payment"
```

What it does:
- Creates a vendor payment directly in SAP B1 using `/VendorPayments`.
- Applies the payment to an existing A/P document `DocEntry`.
- Stores the created payment in the local `sap_finance_documents` table as `document_type=vendor_payment`.

Important:
- `invoice_type` stays explicit on the command so the correct SAP document type can be chosen per tenant/process.
- This avoids hardcoding a tenant-specific assumption into the code path.
