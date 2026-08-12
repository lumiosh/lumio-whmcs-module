# Lumio WHMCS v1.1.7 Troubleshooting

[简体中文](TROUBLESHOOTING.zh-CN.md) | [English](TROUBLESHOOTING.en.md)

Before troubleshooting, record the module version, operation, stable error code, and Request-Id. Never post a complete API Key, service password, customer information, database content, or complete log in a GitHub Issue.

## Test Connection fails

| Symptom or error | Resolution |
| --- | --- |
| `AUTH_INVALID` / `KEY_REVOKED` | Create or rotate the Lumio Key, then update the WHMCS Server's `API Key` field. |
| `SCOPE_DENIED` | Create a new API Key for WHMCS on the Lumio API Integration page. |
| Connection or HTTPS failure | Copy the complete `API Base URL` from the Lumio API Integration page again and make sure it uses HTTPS. |
| Account cannot purchase through API v1 | Check the Lumio account status, API integration status, and purchasing permission. |

Run `Test Connection` again after correcting the configuration. Do not ignore a failed test and treat the Server as ready.

## Product Mapping does not appear

1. Confirm that Module Name is `Lumio`.
2. Confirm that a Server Group is selected and contains exactly one enabled Lumio Server.
3. Confirm that the Server passes `Test Connection`.
4. If the product used the module before a newer `hooks.php` was installed, save Module Settings once.
5. Review browser requests and Module Log for catalog errors.

If the catalog is temporarily unavailable, the module keeps the raw fields and existing mapping. It does not erase the saved configuration after a single failure.

## A paid order remains Pending

| Error code or state | Meaning and next action |
| --- | --- |
| `OUT_OF_STOCK` | No Lumio inventory is currently sellable. Restock, then retry explicitly. |
| `WALLET_INSUFFICIENT` | The Lumio wallet balance is insufficient. Add funds, then retry explicitly. |
| `PRICE_CHANGED` | The Lumio cost exceeds the cap saved on the WHMCS product. Reopen Product Mapping, review the cost, save, and retry. |
| `PRODUCT_NOT_FOUND` | The product is unavailable, cannot be purchased, or is not visible to the account. Select the mapping again. |
| `INVALID_SELECTION` | The billing cycle, fixed configuration, or add-on combination is invalid. Save a valid mapping. |
| `CREDENTIALS_NOT_READY` | Provisioning has started but credentials are not ready. Keep cron running every five minutes and let the original operation reconcile. |
| `TRANSPORT_ERROR` | The network result is unknown. The module preserves the original reference and idempotency key; do not create another purchase. |

Deterministic errors require an administrator to resolve the cause and retry explicitly. Cron does not purchase again every five minutes.

## Suspend, resume, or terminate does not complete

- After a suspend request is safely accepted, WHMCS may report success while cron confirms the final state.
- If Lumio ultimately fails to suspend, the module restores the WHMCS service to Active.
- Resume and terminate complete only after Lumio reaches the final target state.
- `OTHER_HOLDS_REMAIN` means another billing, administrator, or system restriction must be resolved first.
- Immediate termination releases resources and forfeits the remaining paid period. Lumio does not issue an automatic refund.

## Where to check

- `Utilities → Module Queue`
- `Configuration → System Logs → Module Log`
- WHMCS Activity Log
- `Utilities → Automation Status`

For support, provide only the WHMCS/PHP/module versions, operation, redacted complete error text, stable error code, Request-Id, and steps already attempted.

Return to the [English Quick Start](QUICKSTART.en.md).
