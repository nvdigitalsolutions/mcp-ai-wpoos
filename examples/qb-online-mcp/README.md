# QuickBooks Online MCP server

A Streamable HTTP MCP server over Intuit's **accounting API**, for assistants
that need QuickBooks Online `create_*` tools.

## Why this exists

Intuit publishes a hosted MCP endpoint at
`https://ai-inc.quickbooks.intuit.com/v1/mcp`, but it sits behind Akamai bot
protection: every path — including `/.well-known/oauth-protected-resource` —
returns `403` with an HTML body and **no `WWW-Authenticate` header**. A
WordPress host is refused at the edge before any credential is evaluated, so
neither OAuth discovery nor a bearer token can work against it.

The accounting API is not gated that way. It answers properly:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="Intuit"
<Error code="3200">message=AuthenticationFailed…
```

So this server terminates MCP locally, holds the Intuit grant, and calls the
accounting API:

```
NV oOS (WordPress)  ──Bearer──▶  this server  ──OAuth 2.0──▶  quickbooks.api.intuit.com
```

Two side benefits: inbound auth is a token you control (no MCP OAuth discovery
needed at all), and refresh-token rotation happens in one place.

## Tools

| Tool | Family | Notes |
|---|---|---|
| `get_company_info` | read | Company profile for the connected realm. |
| `get_report` | read | ProfitAndLoss, BalanceSheet, CashFlow, SalesByCustomer, AgedReceivables, TrialBalance… |
| `search_invoices` | read | By date range and/or customer; newest first. |
| `get_invoice` | read | One invoice by ID. |
| `search_customers` | read | Partial DisplayName + active filter. Resolves a customer name to the ID that `create_invoice` / `create_payment` need. |
| `search_vendors` | read | Same, for the `vendor_id` that `create_bill` needs. |
| `search_accounts` | read | Name + `account_type` (`Expense`, `Cost of Goods Sold`, …) + active. Resolves the `account_id` every `create_bill` line requires. |
| `search_items` | read | Name + `item_type` (`Service`, `NonInventory`, `Inventory`, `Category`) + active. Resolves the `item_id` for itemised invoice and estimate lines. |
| `search_payments` | read | By date range and/or customer, newest first. Confirms the `payment_id` before deleting. |
| `create_customer` | create | `DisplayName` required, must be unique. |
| `create_invoice` | create | Lines need an `amount`; add `item_id` for itemised lines. |
| `update_invoice` | update | Sparse update. Supplying `lines` **replaces** the whole line set. SyncToken is read automatically when omitted. |
| `delete_invoice` | delete | **Voids by default** (keeps the record, zeroes the balance). `mode: "purge"` hard-deletes and fails when the invoice has linked payments. |
| `create_payment` | create | With `invoice_id` it settles that invoice; without it, an unapplied credit that closes nothing. |
| `delete_payment` | delete | **Permanent** — QuickBooks has no void for payments. Deleting un-applies it and restores the invoice balance. |
| `create_estimate` | create | Same line model as invoices; supports `expiration_date`. |
| `create_bill` | create | Lines need `account_id` (expense account) or `item_id` — QuickBooks rejects otherwise. |

Names use Intuit's reference-server verb prefixes, so NV oOS's connection-level
**Tool Categories** switches (`QUICKBOOKS_DISABLE_WRITE` / `UPDATE` / `DELETE`)
suppress the right families.

`delete_invoice` deliberately defaults to **void** rather than hard delete, and
lives in the `delete_` family, so the `QUICKBOOKS_DISABLE_DELETE` switch turns
off both operations. Purging requires `mode: "purge"` explicitly.

`delete_payment` has no `mode` — QuickBooks does not support voiding a payment,
so the only operation is the irreversible one. Both destructive tools carry
`destructiveHint: true` and sit behind the same switch.

## Setup

```bash
npm install
cp .env.example .env      # then fill it in
npm start                 # :8787
```

Required environment:

| Variable | Purpose |
|---|---|
| `QBO_CLIENT_ID` / `QBO_CLIENT_SECRET` | Intuit app credentials. |
| `QBO_REALM_ID` | Company ID. Tokens are realm-scoped. |
| `QBO_REFRESH_TOKEN` | Bootstraps the grant. After the first refresh the **rotated** token is read from `QBO_STATE_FILE`, which wins over this value. |
| `QBO_ENVIRONMENT` | `sandbox` or `production`. Must match where the token was issued. |
| `QBO_MCP_TOKEN` | Bearer token this server accepts. Required — boot fails without it. |

`QBO_MCP_ALLOW_ANONYMOUS=1` exists for local experiments only; it logs a
warning at boot.

### Verify

```bash
curl -s localhost:8787/health

curl -s localhost:8787/mcp \
  -H "Authorization: Bearer $QBO_MCP_TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## Wire it into NV oOS

**Pro → Remote Sites → Add New Connection**

| Field | Value |
|---|---|
| Connection Type | `QuickBooks Online (MCP)` |
| Site URL | `https://your-host/mcp` |
| Authentication Type | `Custom Header` |
| Header Name | `Authorization` |
| Token | `Bearer <QBO_MCP_TOKEN>` |
| Intuit Environment | match `QBO_ENVIRONMENT` |

Leave **Intuit Client ID / Secret** blank — this server owns the Intuit grant;
the plugin never talks to Intuit directly. Save, then **Test Connection**.

> Header Name is the header's *name*. Entering `Bearer` there sends a literal
> `Bearer: <token>` header and no `Authorization` at all — the save is now
> rejected by `validate_connection_data()` with an explanatory error.

## Operational rules

- **Run exactly one instance per grant.** The refresh token rotates; a second
  replica refreshing concurrently gets `invalid_grant` and strands the grant.
  Scale up only by giving each replica its own Intuit app.
- **`QBO_STATE_FILE` must be durable.** Rotating tokens are written there with
  mode `0600`. If the write fails, the process logs `CRITICAL` — the grant will
  not survive a restart.
- **Never commit** `.env` or the state file (both are gitignored).
- **Sandbox first.** Point `QBO_ENVIRONMENT` at sandbox, verify the report
  numbers, then switch to production.

## Tests

```bash
npm test
```

`tests/smoke.test.js` — real socket: 401 gate, `/health`, legacy handshake,
tool registry, annotations. `tests/oauth.test.js` — rotation persisted, live
access token reused, restart resumes from the rotated token, `invalid_grant`
surfaced. No Intuit credentials or network access needed.
