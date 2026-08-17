# Composio Connect Integration

> 1,000+ third-party apps (Gmail, Slack, GitHub, Notion, Linear, ...) for NV oOS AI assistants via one remote connection — with per-user hosted authentication and provider tokens that never touch WordPress.

## What is Composio Connect?

Composio Connect is a tool aggregator: a single REST API (v3.1 at `https://backend.composio.dev`) that gives AI agents authenticated access to 1,000+ SaaS apps. Instead of building one integration per app, NV oOS Pro ships one `composio` connection type in the Remote Site Manager backed by a PHP API client, six `composio_*` tools, and a signature-gated webhook receiver for trigger events.

- **Composio Connect Links** — hosted per-user OAuth pages (`POST /api/v3.1/connected_accounts/link`). End users authenticate their own accounts on Composio; OAuth tokens are stored and refreshed by Composio and never pass through WordPress.
- **Tool execution** — `POST /api/v3.1/tools/execute/{slug}` with a connected account ID.
- **Triggers** — `gmail.message.new`, `slack.message.received`, ... delivered to the site's webhook receiver (`/wp-json/mcp-ai/v1/webhooks/composio/{connection_id}`).

## Prerequisites

| Requirement | Details |
|---|---|
| **WordPress** | 6.0+ |
| **NV oOS Pro** | v1.4.0+ (Pro addon) |
| **Composio account** | Project API key (`ak_...`) from the [Composio dashboard](https://app.composio.dev) |
| **Public HTTPS site** | Required only for trigger webhooks |

## Installation & Activation

### Step 1: Create the connection

1. Go to **NV oOS Pro → Remote Sites → Add New Connection**
2. Choose **Composio Connect (AI Tool Aggregator)**
3. Paste your project API key (stored encrypted)
4. Pick an **Identity Mode**:
   - **Shared (site-wide)** — one identity for all assistants (default)
   - **Per user** — each WordPress user connects their own accounts
5. Optionally restrict the **Toolkit Allowlist** (e.g. `gmail, slack, github`)
6. Save, then click **Test Connection**

### Step 2: Connect apps

On the connection edit screen under **Connect an App**, enter a toolkit slug (e.g. `gmail`) and click **Open Connect Link**. The user completes the hosted flow and the connected account is linked back automatically. If the Composio project has callback identity verification enabled, NV oOS redeems the single-use `session_uri` (anti session-fixation).

### Step 3: Assign the connection to assistants

On the assistant edit screen, the **Remote Site Connections** metabox shows every Composio connection with a purple badge: `● N connected apps` (cached count, no live API calls) plus a **Connect apps →** shortcut.

### Step 4 (optional): Enable triggers

1. Register a trigger via `composio_manage_triggers` or the Composio dashboard
2. Create a webhook subscription pointing at `https://yoursite.example/wp-json/mcp-ai/v1/webhooks/composio/{connection_id}`
3. Store the returned signing secret on the connection (`webhook_secret`, encrypted). Every delivery is HMAC-SHA256 verified and deduped by event ID.

## The AI Tools

| Tool | Capability | Purpose |
|---|---|---|
| `composio_list_tools` | `edit_posts` | Search the 1,000+ tool catalog by intent or toolkit (24h cached) |
| `composio_get_tool_schema` | `edit_posts` | Input/output schema for a `TOOLKIT_ACTION` slug |
| `composio_list_connected_accounts` | `manage_options` | Status of connected accounts (5min cached) |
| `composio_create_connect_link` | `manage_options` | One-time hosted auth URL for a user |
| `composio_execute_tool` | `manage_options` | Execute a tool on a connected account; write-class verbs flagged `destructive` |
| `composio_manage_triggers` | `manage_options` | List / upsert / enable / disable / delete trigger instances |

### Example Natural Language Prompts

- "What tools can I use to send emails?" → `composio_list_tools` with search "send an email"
- "Connect this site's admin to Gmail" → `composio_create_connect_link` with toolkit `gmail`
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
│   └── triggers               types / instances / upsert
├── composio_* tools (6)       canonical envelope + two-gate sanitisation
├── Connect Link callback      admin.php?oauth_handler=composio_oauth_callback
└── Webhook receiver           /mcp-ai/v1/webhooks/composio/{id} (HMAC-gated)
```

**Key principles:**

- **Provider tokens never touch WordPress** — only the project API key + webhook secret are stored (AES-256-CBC encrypted, masked, never logged).
- **No `@composio/core` TS package** — the integration is pure PHP against the REST API; admin JS only calls the site's own endpoints.
- **Cost control** — toolkit allowlists, 24h tool-catalog cache, 5min account cache, 429 cooldowns with `Retry-After`.
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
- **"No active connected account found"** — create a Connect Link for that toolkit (`composio_create_connect_link`), then retry.
- **Webhooks rejected (403)** — confirm the `webhook_secret` on the connection matches the subscription's signing secret; rotate if needed.
- **429 rate limits** — the client backs off automatically and returns a clear "retry in N seconds" error.

## References

- User guide: [`docs/composio-connect.md`](../composio-connect.md)
- Proposal: [`docs/project/proposals/030-composio-connect-integration-proposal.md`](../project/proposals/030-composio-connect-integration-proposal.md)
- Implementation plan: [`docs/project/proposals/030-composio-connect-integration-implementation-plan.md`](../project/proposals/030-composio-connect-integration-implementation-plan.md)
- Composio API reference: https://docs.composio.dev/reference/api-reference
