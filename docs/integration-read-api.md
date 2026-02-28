# Integration Read API

Updated: 2026-03-01

This API exposes selected SAP snapshot/master-data tables and SAP catalog sync history as read-only endpoints for external consumers.

Authentication:
- Set `INTEGRATION_READ_API_TOKEN` in the application environment.
- Send the token using either:
  - `X-Integration-Token: <token>`
  - `Authorization: Bearer <token>`

Base path:
- `/api/integration/sap`

## Endpoints

### 1. List Available Resources

- `GET /api/integration/sap/resources`

Returns:
- Available resource keys
- Human-readable labels
- Read-only mode information

### 2. Read A Resource Collection

- `GET /api/integration/sap/resources/{resource}`

Supported resources:
- `chart-of-accounts`
- `account-categories`
- `financial-periods`
- `banks`
- `bank-accounts`
- `payment-terms`
- `currencies`
- `exchange-rates`
- `profit-centers`
- `branches`
- `customer-finance`
- `finance-documents`
- `sales-documents`
- `item-groups`
- `inventory-documents`
- `banking-documents`

Supported query parameters:
- `per_page`
- `search`
- `status`
- `document_type`
- `doc_entry`
- `doc_num`
- `from_date`
- `to_date`
- `include_payload=true`

Notes:
- The API is read-only by design.
- `payload` is hidden by default unless `include_payload=true` is provided.
- Not all filters apply to all resources; unsupported filters are ignored.

### 3. Read Background Sync Status

- `GET /api/integration/sap/sync-status`

Returns:
- Current active `sap_catalog` background sync if one is `queued` or `running`
- The latest 10 `sap_catalog` sync events

## Scope Decision

Current decision in this project:
- These endpoints are intentionally `read-only`.
- They are meant for:
  - external visibility,
  - reporting,
  - data consumption,
  - and sync monitoring.
- They are not intended to replace the webhook-driven transactional posting flows.
- Write/trigger APIs remain deferred unless explicitly required later.
