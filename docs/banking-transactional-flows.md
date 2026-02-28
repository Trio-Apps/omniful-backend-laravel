# Banking Transactional Flows

Updated: 2026-03-01

This project now includes direct manual transactional SAP posting for:

- `Deposits`
- `ChecksforPayment`

Current trigger mode:
- Manual command-based trigger
- Not webhook-driven

## 1. Deposits

Command:

```bash
php artisan sap:create-deposit ABS_ID DEPOSIT_ACCOUNT VOUCHER_ACCOUNT --deposit-type=dtCredit
```

Important:
- `ABS_ID` is the existing SAP credit line `AbsId` that will be deposited.
- This flow is intentionally explicit because the deposit operation depends on an already existing SAP credit line.

## 2. Checks For Payment

Command:

```bash
php artisan sap:create-check-for-payment BANK_CODE CUSTOMER_ACCOUNT_CODE COUNTRY_CODE AMOUNT --vendor-code=VENDOR --account-number=ACC --branch=MAIN --details="Manual check"
```

Important:
- `card-or-account` stays configurable on the command and defaults to `cfp_Account`.
- Optional fields are passed only when provided.

## Why This Mode

- These banking APIs are part of the Maaz high-level scope.
- The current connected Omniful webhook set does not provide a confirmed native banking trigger for these documents.
- The current implementation closes the direct transactional gap without inventing an undocumented webhook dependency.
