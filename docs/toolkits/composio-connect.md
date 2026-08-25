# Composio Connect Integration

> 1,000+ third-party apps (Gmail, Slack, GitHub, Notion, Linear, ...) for NV oOS AI assistants via one remote connection — with per-user hosted authentication and provider tokens that never touch WordPress.

## What is Composio Connect?

Composio Connect is a tool aggregator: a single REST API (v3.1 at `https://backend.composio.dev`) that gives AI agents authenticated access to 1,000+ SaaS apps. Instead of building one integration per app, NV oOS Pro ships one `composio` connection type in the Remote Site Manager backed by a PHP API client, seven `composio_*` tools, a live account-health engine, and a signature-gated webhook receiver for trigger events.

- **Composio Connect Links** — hosted per-user OAuth pages (`POST /api/v3.1/connected_accounts/link`). End users authenticate their own accounts on Composio; OAuth tokens are stored and refreshed by Composio and never pass through WordPress.
- **Tool execution** — `POST /api/v3.1/tools/execute/{slug}` with a connected account ID.
- **Account health** — Composio has no verify route in v3.1, so NV oOS verifies a credential by making a harmless zero-argument read-only tool call and storing the verdict per connection. Reconnection re-authorises the same account (`POST /api/v3.1/connected_accounts/{id}/refresh`) rather than minting a duplicate.
- **Triggers** — `gmail.message.new`, `slack.message.received`, ... delivered to the site's webhook receiver (`/wp-json/mcp-ai/v1/webhooks/composio/{connection_id}`).

## Prerequisites

| Requirement | Details |
|---|---|
| **WordPress** | 6.0+ |
| **NV oOS Pro** | v1.4.0+ (Pro addon); v1.4.1+ for account-health verification and `composio_manage_accounts` |
| **Composio account** | Project API key (`ak_...`) from the [Composio dashboard](https://app.composio.dev) |
| **Public HTTPS site** | Required only for trigger webhooks |

## Installation & Activation

### Step 1: Create the connection

1. Go to **NV oOS Pro → Remote Sites → Add New Connection**
2. Choose **Composio Connect (AI Tool Aggregator)**
3. Paste your project API key (stored encrypted)
4. Pick an **Identity Mode**:
   - **Shared (site-wide)** — one identity for all assistants (default); everything runs as `nvoos-shared`
   - **Per user** — each WordPress user connects their own accounts (`wp-{user_id}`)
5. Optionally restrict the **Toolkit Allowlist** (e.g. `gmail, slack, github`)
6. Save, then click **Test Connection**

After saving, the **Identity Mode** row prints the resolved Composio identity so you can confirm the mode took effect.

### Step 2: Connect apps

On the connection edit screen under **Connect an App**, enter a toolkit slug (e.g. `gmail`) and click **Open Connect Link**. The user completes the hosted flow and the connected account is linked back automatically. If the Composio project has callback identity verification enabled, NV oOS redeems the single-use `session_uri` (anti session-fixation).

The **Connected Apps** table below lists each account with its **Identity** (owning Composio `user_id`), status and account ID, plus a **Remove** action that deletes the account at Composio and revokes its upstream provider credentials. Use **Refresh list** to bypass the 5-minute listing cache.

### Step 3: Assign the connection to assistants

On the assistant edit screen, the **Remote Site Connections** metabox shows every Composio connection with a purple badge: `● N connected apps` (cached count, no live API calls) plus a **Connect apps →** shortcut.

### Step 4 (optional): Enable triggers

1. Register a trigger via `composio_manage_triggers` or the Composio dashboard
2. Create a webhook subscription pointing at `https://yoursite.example/wp-json/mcp-ai/v1/webhooks/composio/{connection_id}`
3. Store the returned signing secret on the connection (`webhook_secret`, encrypted). Every delivery is HMAC-SHA256 verified and deduped by event ID.

## The AI Tools

| Tool | Capability | Purpose |
|---|---|---|
| `composio_list_tools` | `edit_posts` | Browse or search the 1,000+ tool catalog: `search` (natural language, re-ranked locally against slug/name/description), `toolkit`, `connected_only` (only toolkits with a connected account), `list_toolkits` (the app directory instead of tools), `limit` + `cursor`. Each entry carries `required_inputs`, so `composio_get_tool_schema` is often unnecessary (24h cached) |
| `composio_get_tool_schema` | `edit_posts` | Full input/output schema for a `TOOLKIT_ACTION` slug |
| `composio_list_connected_accounts` | `manage_options` | Connected accounts with **verified** health, not just Composio's stored status. `verify` defaults to `true` (live read-only probe per account; verdicts under 15min are reused, `force` re-probes). Returns `status`, `status_reason`, `auth_scheme`, `token_expires_at`, the owning identity, a `health` block, `reconnect_url` when broken, and a `summary` |
| `composio_create_connect_link` | `manage_options` | One-time hosted auth URL for a user |
| `composio_manage_accounts` | `manage_options` | Account lifecycle: `validate` (always a live probe), `reconnect` (re-authorises the same account in place), `disable`, `enable`, `delete`, `prune`. `risk_level: high`, flagged `destructive` |
| `composio_execute_tool` | `manage_options` | Execute a tool on a connected account; sends the owning identity with the account; health-aware auto-resolution skips accounts known to need reconnecting; write-class verbs flagged `destructive` |
| `composio_manage_triggers` | `manage_options` | List / upsert / enable / disable / delete trigger instances |

### Example Natural Language Prompts

- "What tools can I use to send emails?" → `composio_list_tools` with search "send an email"
- "Which apps can I actually use right now?" → `composio_list_tools` with `connected_only: true` (or `list_toolkits: true` to browse the app directory)
- "Connect this site's admin to Gmail" → `composio_create_connect_link` with toolkit `gmail`
- "Is my Gmail connection still working?" → `composio_list_connected_accounts` (verified by default) or `composio_manage_accounts` `validate` with toolkit `gmail` for a forced live probe
- "Reconnect my Gmail" → `composio_manage_accounts` `reconnect` — re-authorises the same account and returns a URL to finish the flow
- "Clean up the Slack connections that stopped working" → `composio_manage_accounts` `prune` with toolkit `slack`
- "Send a summary email to the client via my connected Gmail" → `composio_execute_tool` `GMAIL_SEND_EMAIL`
- "Notify me whenever a new Gmail message arrives" → `composio_manage_triggers` upsert `GMAIL_NEW_MESSAGE`

## Understanding the Architecture

```
WordPress (NV oOS Pro)
│
├── Remote Site Manager        connection_type = composio (API key encrypted)
├── WP_MCP_AI_Composio_Client  x-api-key → backend.composio.dev/api/v3.1
│   ├── connected_accounts     Connect Links + complete_auth (verifier mode)
│   ├── tools                  list / schema / execute (toolkit_versions=latest)
│   ├── triggers               types / instances / upsert
│   └── account_health         live read-only probe + per-connection verdict ledger
├── composio_* tools (7)       canonical envelope + two-gate sanitisation
├── Connect Link callback      admin.php?oauth_handler=composio_oauth_callback
└── Webhook receiver           /mcp-ai/v1/webhooks/composio/{id} (HMAC-gated)
```

**Key principles:**

- **Provider tokens never touch WordPress** — only the project API key + webhook secret are stored (AES-256-CBC encrypted, masked, never logged).
- **Health is verified, not assumed** — Composio's stored `ACTIVE` status is a lagging indicator and v3.1 has no verify route, so NV oOS probes each credential with a zero-argument read-only tool call and keeps the verdict in a per-connection ledger. Auto-resolution skips accounts with a recent "needs reconnect" verdict, and a successful execution counts as a verification.
- **No `@composio/core` TS package** — the integration is pure PHP against the REST API; admin JS only calls the site's own endpoints.
- **Cost control** — toolkit allowlists, 24h tool-catalog cache, 5min account cache, 15min health-verdict reuse, 429 cooldowns with `Retry-After`.
- **Webhooks are safe by default** — signature-gated (hex + base64, constant-time), event-ID dedup, unknown events acknowledged to prevent retry loops.

## Security Model

| Secret | Location |
|---|---|
| Composio project API key | WordPress (encrypted at rest) |
| Webhook signing secret | WordPress (encrypted at rest) |
| End-user OAuth tokens | Composio only |

Write-class tool executions are classified `destructive` in responses and require `manage_options`.

## Troubleshooting

- **Test Connection fails with 401** — regenerate the API key in the Composio dashboard and re-save (masked field preserves the old value; clear it first).
- **`Composio connection not found` when you passed a real ID** — you almost certainly swapped the two ID kinds. They are *not* interchangeable:

  | Parameter | Identifies | Form | Where to get it |
  |---|---|---|---|
  | `connection_id` | **this site's** Composio project integration | `conn_2ezeknzxsuzq` | Remote Sites → your Composio connection |
  | `connected_account_id` | **an end user's** authenticated Gmail/Slack/GitHub account | `ca_F0HEJBssnCXL` | `composio_list_connected_accounts` |

  Passing `ca_...` as `connection_id` used to fail before the request ever reached Composio, with no hint as to why. It now returns `wp_mcp_ai_composio_id_swapped`, naming which kind of ID you supplied and which was expected; the reverse swap (`conn_...` as `connected_account_id`) is caught the same way. The simplest fix is almost always to **omit `connection_id` entirely** — the enabled Composio connection is resolved automatically:

  ```json
  // Option A — let it auto-resolve (simplest)
  { "tool_slug": "GMAIL_LIST_MESSAGES" }

  // Option B — resolve the account, then pin it
  // 1) composio_list_connected_accounts { "toolkit": "gmail" }
  // 2) composio_execute_tool:
  { "tool_slug": "GMAIL_LIST_MESSAGES", "connected_account_id": "ca_F0HEJBssnCXL" }
  ```

  An unknown-but-plausible `conn_...` now lists the site's real Composio connection IDs in the error, so a retry does not need another guess.
- **`composio_list_connected_accounts` says `ACTIVE` but calls fail with 401** — `ACTIVE` is Composio's *stored* status, and it is a lagging indicator: when a user revokes the app in their Google / Slack / GitHub settings, Composio keeps reporting `ACTIVE` until its own background refresh fails or the `composio.connected_account.expired` webhook fires. There is no verify route in Composio's v3.1 API, so **verification is now on by default** (`verify: true`): each account is probed with a harmless read-only call and a revoked token is reported as broken instead of active. Verdicts newer than 15 minutes are reused, so repeat calls are cheap; pass `force: true` to re-probe anyway. For a forced probe that is never answered from cache, run `composio_manage_accounts` with `action: "validate"` and either `connected_account_id` or a `toolkit` (validates every account for that app), then `action: "reconnect"` on anything reporting `health.needs_reconnect`. `health.needs_reconnect` is authoritative — the legacy `expired` boolean is retained only for backwards compatibility. Probing is capped at 10 accounts per listing call; accounts past the cap show their previous verdict and are counted in `verifications_capped`.
- **`verification_method: status_only` means the status is unconfirmed** — the credential could **not** be probed, so `status_only` is never reported as `verified`. It is returned when no zero-argument read-only tool exists for the toolkit, or when probing was disabled for that toolkit via `wp_mcp_ai_composio_probe_tool`; the verdict then reflects only what Composio has stored, which may lag a revoked token. `probe_inconclusive` is weaker still: the probe ran but failed for a non-auth reason (the read tool wanted a mailbox, repo or workspace), so it proves nothing either way. Only `probe`, `execution` and `webhook` verdicts are decisive.
- **`composio_list_tools` returns nothing, or blank/slug-less entries** — an envelope-unwrapping bug fixed in 1.4.1. Composio v3.1 wraps `GET /api/v3.1/tools` in a pagination envelope (`{items, next_cursor, total_pages, current_page, total_items}`); the old code returned that envelope raw, so callers iterating it saw the `items` key itself as one slug-less "tool". The same release corrected the query params to what the endpoint actually accepts — `toolkit_slug` (singular, was wrongly `toolkits`), `query` (was the deprecated `search`), `limit` and `cursor`; `page` is not a supported Composio param and is no longer sent. For browsing, the entry points are `list_toolkits: true` (the app directory, to find the right toolkit slug) and `connected_only: true` (only toolkits with a connected account — the "what can I actually run right now?" view, fanned out over up to 8 toolkits because `GET /tools` accepts a single `toolkit_slug`).
- **`wp_mcp_ai_composio_ambiguous_account`** — several connected accounts could run the tool, none is a clearly better match, and the action is **write-class** (slug verb `DELETE`, `REMOVE`, `UPDATE`, `PATCH`, `CREATE`, `SEND`, `POST`, `UPLOAD`, `WRITE`, `INSERT` or `SET`), so `composio_execute_tool` refuses instead of silently picking one. The error data lists the `candidates`. Fix it by passing `connected_account_id` explicitly, or by running `composio_manage_accounts` `validate` with that `toolkit` first — probe-verified accounts are preferred over unverified ones, so a single validation pass usually makes the choice unambiguous. Then `prune` the dead accounts so it stays that way. Read-only actions do not fail; they proceed and report `ambiguous_accounts` in the response.
- **Duplicate / orphaned connected accounts** — creating a fresh Connect Link mints a *new* `ca_...` account and leaves the broken one behind, which is how orphans accumulate. Use `composio_manage_accounts` with `action: "reconnect"` instead: it calls `POST /api/v3.1/connected_accounts/{id}/refresh` to re-authorise the **same** account ID, preserving its alias and any triggers pinned to it, and returns a URL to finish the flow with `in_place: true` / `creates_new: false`. That route is marked Legacy upstream by Composio; when it is unavailable NV oOS falls back to a fresh Connect Link and says so plainly (`in_place: false`, `creates_new: true`) — complete that flow, then delete the old account. To clear out accumulated dead accounts, run `action: "prune"` with an explicit `toolkit` (required, so the blast radius is always stated): every account is probed immediately before deletion and only ones with a definitive "needs reconnect" verdict are removed, returning `deleted` / `kept` / `failed`.
- **"No active connected account found for toolkit X (identity Y)"** — no `ACTIVE` account exists for that toolkit. Connect the app from the connection edit screen (or `composio_create_connect_link`), then retry. The identity in the message tells you which Composio user was searched.
- **"Every X account on this connection (…) failed its last credential check"** — candidates existed but all were excluded by the health ledger. The error carries a `reconnect_url` and the offending `dead_accounts`; reconnect the one you want to keep, or `prune` the toolkit.
- **`HTTP 400: User ID is required with connected account`** — the execute request reached Composio without the identity that owns the account. NV oOS sends it automatically (connection identity, overridden by the account's own `user_id`); seeing this means the build predates that fix.
- **A tool was reported as "executed" but nothing happened** — fixed in 1.4.1. Composio answers tool *failures* with `HTTP 200` and `{"successful": false, "error": "..."}`; the old code returned that body as a success, so a revoked-token failure was reported to the assistant as "Composio tool X executed." The client now converts it to a `WP_Error` — `wp_mcp_ai_composio_account_auth_required` for auth-class failures (with a plain-language message naming the app, the provider's reason and a `reconnect_url`) or `wp_mcp_ai_composio_tool_failed` otherwise — carrying `log_id` and `upstream_error` in the error data. Auth failures are also written to the health ledger, so the dead account is skipped on the next call.
- **A `status` filter matches nothing** — Composio's status enum is SCREAMING_SNAKE (`ACTIVE`, `INACTIVE`, `INITIALIZING`, `INITIATED`, `FAILED`, `EXPIRED`, `REVOKED`). Earlier builds lowercased the filter, which silently matched nothing; it is now uppercased before being sent.
- **Stuck `INITIALIZING` / `EXPIRED` accounts** — use **Remove** in the **Connected Apps** table, or `composio_manage_accounts` `delete` (revokes the upstream provider credentials unless you pass `revoke: false`). Use `disable` / `enable` to park an account without deleting it.
- **Webhooks rejected (403)** — confirm the `webhook_secret` on the connection matches the subscription's signing secret; rotate if needed.
- **429 rate limits** — the client backs off automatically and returns a clear "retry in N seconds" error.

### Extension points

| Hook | Type | Signature |
|---|---|---|
| `wp_mcp_ai_composio_probe_tool` | filter | `( string\|null $probe_tool, string $toolkit )` |
| `wp_mcp_ai_composio_account_managed` | action | `( array $context )` |

**`wp_mcp_ai_composio_probe_tool`** pins or disables the read-only tool used to verify a toolkit's credentials. Probes are normally **discovered from the live catalog**, not hardcoded: NV oOS looks for a `{TOOLKIT}_{READ_VERB}_...` slug (`GET`, `LIST`, `FETCH`, `SEARCH`, `FIND`, `RETRIEVE`, `COUNT`, `CHECK`, `VALIDATE`, `DESCRIBE`, `READ`) with no required input parameters, so it can be called with `{}`. A small curated fast path exists for common apps (`gmail` → `GMAIL_GET_PROFILE`, `github` → `GITHUB_GET_THE_AUTHENTICATED_USER`, `notion` → `NOTION_GET_ABOUT_ME`, `linear` → `LINEAR_GET_CURRENT_USER`, `googledocs`), but every entry is validated against the catalog before use. Resolution is cached for 24h per connection + toolkit. `$probe_tool` is `null` when unfiltered; return a slug to pin the probe, or `''` to disable probing for that toolkit (verification then degrades to `status_only`). A pinned slug that does not belong to the toolkit, or is not a read verb, is rejected.

```php
// Accept Slack's stored status instead of probing it.
add_filter(
	'wp_mcp_ai_composio_probe_tool',
	function ( $probe_tool, $toolkit ) {
		return 'slack' === $toolkit ? '' : $probe_tool;
	},
	10,
	2
);
```

**`wp_mcp_ai_composio_account_managed`** fires after every state-changing `composio_manage_accounts` action (`reconnect`, `disable`, `enable`, `delete`, `prune` — not the read-only `validate`). It receives one array with `connection_id`, `action`, `account_id`, `toolkit` and the acting WordPress `user_id`. Use it for audit trails, or to alert an operator that an app needs re-authorising.

## References

- User guide: [`docs/composio-connect.md`](../composio-connect.md)
- Proposal: [`docs/project/proposals/030-composio-connect-integration-proposal.md`](../project/proposals/030-composio-connect-integration-proposal.md)
- Implementation plan: [`docs/project/proposals/030-composio-connect-integration-implementation-plan.md`](../project/proposals/030-composio-connect-integration-implementation-plan.md)
- Composio API reference: https://docs.composio.dev/reference/api-reference
