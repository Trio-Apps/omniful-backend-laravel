# Ahmed Review Checklist

Source: review notes shared for discussion and implementation tracking.

## General

- [ ] Schedule a call to discuss the review notes.
- [ ] Receive the remaining counting mapping when shared.

## Business Partners

### POST `/BusinessPartners` to create a customer

- [ ] Integrate customer creation for local customers only where applicable.
- [ ] Integrate customer creation for foreign customers only where applicable.
- [ ] Confirm customer creation scope is limited to local and foreign customer types only.

### PATCH `/BusinessPartners('{{cardCode}}')` to convert an existing BP to customer or supplier

- [ ] Support converting an existing BP to customer when required.
- [ ] Support converting an existing BP to supplier when required.
- [ ] Confirm new suppliers will be added separately for purchasing use cases.

### POST `/BusinessPartners` to create a supplier

- [ ] Add supplier creation flow in SAP.
- [ ] Integrate created suppliers to Omniful.
- [ ] Ensure supplier updates are maintained in SAP.
- [ ] Ensure updated supplier data is integrated to Omniful.

## Sales Flow

### POST `/Orders` for AR Reserve Invoice

- [x] Map `OINV."TaxDate"` to Document Date.
- [ ] Confirm Document Date source in the request payload.
- [ ] Use local customer code `C00046` where applicable.
- [ ] Use foreign customer code `C00047` where applicable.
- [x] Use warehouse `CEN11`.
- [ ] Use costing code `CEN011` for Department.
- [x] Treat missing line/header discount as no discount.
- [x] Exclude freight from the scope.

### POST `/IncomingPayments`

- [x] Support payment method mapping for `Visa`.
- [x] Support payment method mapping for `Master`.
- [x] Support payment method mapping for `Tamara`.
- [x] Support payment method mapping for `Tabby`.
- [x] Support payment method mapping for `Tab`.
- [ ] Pass Omnifull number in the required payment reference field.

### POST `/JournalEntries` for card fee journal and COGS

- [x] Put Omnifull order number in Reference Number 2 for commission JE.
- [x] Put Omnifull order number in Reference Number 3 for COGS JE.
- [ ] Use profit code / cost center department `CEN011`.

### POST `/DeliveryNotes`

- [x] Use warehouse `CEN11`.
- [ ] Use costing code `CEN011` for Department.

### POST `/CreditNotes` for AR credit memo

- [x] Map `ORIN."TaxDate"` to Document Date.
- [ ] Confirm Document Date source in the request payload.
- [ ] Use local customer code `C00046` where applicable.
- [ ] Use foreign customer code `C00047` where applicable.
- [x] Use warehouse `CEN11`.
- [ ] Use costing code `CEN011` for Department.
- [x] Treat missing line/header discount as no discount.
- [x] Exclude freight from the scope.

## Purchasing Flow

### POST `/PurchaseOrders`

- [x] Confirm `OPOR."TaxDate"` mapping because it is not mentioned in the request.
- [ ] Use warehouse as Omnifull warehouse.
- [x] Treat missing line/header discount as no discount.
- [x] Exclude freight from the scope.

### POST `/PurchaseDeliveryNotes`

- [ ] Confirm whether `"DocDate": "{{today}}"` is the expected behavior.
- [x] Confirm `OPCH."TaxDate"` mapping because it is not mentioned.
- [ ] Use warehouse as Omnifull warehouse.

## Out of Scope

- [x] Manual AP is out of scope.
- [x] Outgoing payment is out of scope.

## Open Clarifications

- [ ] Confirm exact request fields for all missing `TaxDate` mappings.
- [ ] Confirm exact Omnifull warehouse mapping where the note says "Warehouse as Omnifull".
- [ ] Confirm the exact target field for "Omnifull number" in incoming payments.
