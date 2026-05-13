# Per-Toolkit MCP Servers

> Status: Phase 0 + Phase 1 + Phase 2 + Phase 3 (3a/3b/3c/3d/3e) + Phase 4 + Phase 5 + Phase 6 + Phase 7 shipped — all 26 toolkits promoted (19 Tier-1 + 7 Tier-2), `/.well-known/mcp` discovery endpoint active, the per-toolkit endpoint supports execution and rate limiting, a `/mcp-server` slash command + WP-CLI command are available, cross-mount reads are recorded in the audit log, toolkit-scoped bearer tokens (Phase 3d) allow credential-based access without a WordPress user session, and a dedicated Pro admin page (`nvoos-pro-toolkit-mcp-servers`) provides a first-class management UI.
> ADR: [`docs/ADR_002_toolkit_mcp_servers.md`](../ADR_002_toolkit_mcp_servers.md)

Each Pro toolkit can be promoted into a first-class MCP (Model Context Protocol) server with its own JSON-RPC endpoint, capability negotiation, discovery descriptor, and per-toolkit configuration page — without disturbing the existing monolithic `/mcp-ai/v1/mcp` endpoint.

## REST endpoints

All routes live under namespace `mcp-ai-pro/v1`:

| Method | Route                            | Purpose                                                                 |
|--------|----------------------------------|-------------------------------------------------------------------------|
| `GET`  | `/mcp-ai-pro/v1/mcp`             | Descriptor list of every registered toolkit server                      |
| `GET`  | `/mcp-ai-pro/v1/mcp/{slug}`      | Single-server descriptor                                                |
| `POST` | `/mcp-ai-pro/v1/mcp/{slug}`      | JSON-RPC 2.0 entry point                                                |

Supported JSON-RPC methods:

- `initialize`
- `ping`
- `tools/list`
- `tools/call` *(Phase 3a)*
- `resources/list`
- `resources/read` *(Phase 3a)*
- `prompts/list`
- `prompts/get` *(Phase 3a)*

### `tools/call`

Routes through `WP_MCP_AI_Tool_Registry::execute_tool()` after gating on the per-server effective tool allowlist computed by `WP_MCP_AI_Toolkit_Server_Base::tool_is_allowed()`. The execution context is decorated with `source: 'toolkit_mcp'` and `toolkit_mcp_server: '{slug}'` so downstream observers (Prompt Injection Detector, OTel, audit log) can attribute calls to the originating server.

Two action hooks bracket each call:

- `wp_mcp_ai_toolkit_mcp_before_call( string $tool_slug, array $arguments, WP_MCP_AI_Toolkit_Server_Interface $server )`
- `wp_mcp_ai_toolkit_mcp_after_call( string $tool_slug, array $arguments, mixed $result, WP_MCP_AI_Toolkit_Server_Interface $server )`

These are intended for observability and audit subscribers (Phase 4).

### `resources/read`

Resolves the supplied `uri` against the server's effective `resources/list` output (native + mounted, after admin and source-toolkit gates). Returns a single `contents[]` entry whose `text` is a JSON descriptor of the underlying entity collection. Mounted resources are marked `mounted: true, read_only: true` in the body and retain their `application/vnd.nvoos.entity-collection+json` MIME type.

Materializing actual records is intentionally deferred — clients should call the toolkit's own read tools via `tools/call` for record data.

### `prompts/get`

Resolves the supplied `name` against the server's effective `prompts/list` output and returns a single `user`-role message whose text summarizes the bound ingestion surface (page slug, entity type, mount status). Mounted prompts include a note that they are read-only and resolve back to the source toolkit.

## Configuration options

Per-server configuration is persisted in option `wp_mcp_ai_toolkit_mcp_server_{slug}` and surfaced as the **MCP Server** tab on every toolkit settings page. The tab is auto-grown by `WP_MCP_AI_Toolkit_Settings_Base` whenever a server is registered for the toolkit.

Sections:

1. **Server** — master enable/disable switch.
2. **Tools** — allowlist matrix over `candidate_tool_slugs()`. Empty means "all candidates allowed".
3. **Ingestion Surfaces — Native** — per-page disable toggles for surfaces this toolkit owns.
4. **Ingestion Surfaces — Mounted** — per-mount disable toggles for foreign surfaces this toolkit consumes read-only.
5. **Limits** *(Phase 3c)*:
   - **Requests per minute** — per-user JSON-RPC rate limit on the per-toolkit endpoint. `0` = unlimited.
   - **Max request body size (bytes)** — reject JSON-RPC requests with bodies larger than this many bytes. `0` = no limit.
   - **Max agentic iterations** — per-server cap on agentic loop iterations. `0` = inherit global `wp_mcp_ai_max_agentic_iterations` filter.

The effective limits are also reflected in the discovery descriptor under the `limits` key, so clients can introspect them before issuing calls.

### Limits enforcement

Limits are enforced in `WP_MCP_AI_Toolkit_MCP_REST_Controller::enforce_limits()` *before* dispatch. Probe methods (`initialize`, `ping`) bypass the guard so handshakes are never throttled.

| Trigger                          | JSON-RPC error code | Notes                                       |
|----------------------------------|---------------------|---------------------------------------------|
| Payload exceeds `max_payload_bytes` | `-32098`         | `data.max_payload_bytes`, `data.received_bytes` |
| Rate-limit bucket full           | `-32099`            | `data.requests_per_minute`, `data.retry_after_seconds` |

Bucketing uses a per-user, per-server, per-60s-window transient (`wp_mcp_ai_tk_mcp_rl_{slug}_{user_id}_{epoch_minute}`).

### Filter — `wp_mcp_ai_toolkit_mcp_server_limits`

Final-mile override of effective limits, applied after admin overrides:

```php
add_filter(
    'wp_mcp_ai_toolkit_mcp_server_limits',
    function ( array $limits, string $slug ) {
        if ( 'crm' === $slug ) {
            $limits['requests_per_minute'] = 30;
        }
        return $limits;
    },
    10,
    2
);
```

## Toolkit-scoped credentials (Phase 3d)

Each toolkit MCP server can issue **bearer tokens** that allow programmatic access without a WordPress user session. This is designed for CI/CD pipelines, background agents, and external MCP clients that cannot maintain a cookie-based session.

### Token format

```
mcptk_{8-char prefix}.{40-char secret}
```

The raw token is shown **once** at generation time. Only a bcrypt hash of the secret is stored on disk (in WP option `wp_mcp_ai_tk_mcp_token_{slug}`). A maximum of 10 tokens per server are allowed.

### Authenticating requests

Include the token in the `Authorization` header:

```
Authorization: Bearer mcptk_a1b2c3d4.abcdef0123456789...
```

If the token prefix maps to `mcptk_`, the REST controller validates it against the stored hash for the target server **before** falling back to user-session authentication. If validation fails, the request is rejected with HTTP 401 rather than falling through to the session check.

### REST API for token management

All token management routes require `manage_options`.

| Method   | Route                                              | Description                                   |
|----------|----------------------------------------------------|-----------------------------------------------|
| `GET`    | `/mcp-ai-pro/v1/mcp/{slug}/token`                  | List token metadata (prefix, label, timestamps). |
| `POST`   | `/mcp-ai-pro/v1/mcp/{slug}/token`                  | Generate a new token. Accepts optional `label` body param. Returns the raw token once. |
| `DELETE` | `/mcp-ai-pro/v1/mcp/{slug}/token/{prefix}`         | Revoke a token by prefix.                     |

### WP-CLI

```bash
# Generate a token for the CRM server.
wp mcp-ai mcp-server token-generate crm --label=ci-pipeline

# List all active tokens (secrets omitted).
wp mcp-ai mcp-server token-list crm

# Revoke a token by its 8-char prefix.
wp mcp-ai mcp-server token-revoke crm a1b2c3d4 --yes
```

## Tier-1 servers

### Phase 1 pilot servers

| Slug                   | Class                                              | Native surfaces                                                                                            | Mounted surfaces                                                  |
|------------------------|----------------------------------------------------|------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| `crm`                  | `WP_MCP_AI_CRM_MCP_Server`                         | `company-research`, `post-research`, `page-research`, `place-research` (all R&A)                          | —                                                                 |
| `health`               | `WP_MCP_AI_Healthcare_MCP_Server`                  | `member-research` (R&A), `health-records-consolidate` (C&A) — both on `mcp_ai_member`                     | —                                                                 |
| `architectural-design` | `WP_MCP_AI_Architectural_Design_MCP_Server`        | `architectural-drawing-research`, `architectural-project-research`, `architectural-specification-research` | `health-records-consolidate` mounted read-only from `health` |

### Phase 2 promotions

| Slug                       | Class                                              | Native surfaces                                                                                              |
|----------------------------|----------------------------------------------------|--------------------------------------------------------------------------------------------------------------|
| `ai-tool-builder`          | `WP_MCP_AI_AI_Tool_Builder_MCP_Server`             | — (tools-only)                                                                                                |
| `calendar-booking`         | `WP_MCP_AI_Calendar_Booking_MCP_Server`            | `research-appointment` (R&A)                                                                                  |
| `cre-debt`                 | `WP_MCP_AI_CRE_Debt_MCP_Server`                    | `research-cre-debt` (R&A)                                                                                     |
| `dj-management`            | `WP_MCP_AI_DJ_Management_MCP_Server`               | — (tools-only)                                                                                                |
| `document-generation`      | `WP_MCP_AI_Document_Generation_MCP_Server`         | `research-document-template` (R&A)                                                                            |
| `eca`                      | `WP_MCP_AI_ECA_Management_MCP_Server`              | `research-eca` (R&A)                                                                                          |
| `ecommerce`                | `WP_MCP_AI_Ecommerce_MCP_Server`                   | `research-product` (R&A), `product-consolidate` (C&A) — both on `product`                                     |
| `financial-planner`        | `WP_MCP_AI_Financial_Planner_MCP_Server`           | `research-financial-account` (R&A)                                                                            |
| `image-production`         | `WP_MCP_AI_Image_Production_MCP_Server`            | `research-image-template` (R&A)                                                                               |
| `law-firm`                 | `WP_MCP_AI_Law_Firm_MCP_Server`                    | `research-law-firm` (R&A)                                                                                     |
| `media`                    | `WP_MCP_AI_Media_Toolkit_MCP_Server`               | `design-media` (C&A) on `attachment`                                                                          |
| `multilingual`             | `WP_MCP_AI_Multilingual_MCP_Server`                | — (tools-only)                                                                                                |
| `project-management`       | `WP_MCP_AI_Project_Management_MCP_Server`          | `research-project`, `research-task`, `research-event` (R&A); `event-consolidate` (C&A on `mcp_ai_event`)      |
| `regulatory-registration`  | `WP_MCP_AI_Regulatory_Registration_MCP_Server`     | `wp-mcp-ai-reg-product-research`, `wp-mcp-ai-reg-document-research`, `wp-mcp-ai-registration-research` (R&A)  |
| `social-media`             | `WP_MCP_AI_Social_Media_MCP_Server`                | — (tools-only)                                                                                                |
| `video-production`         | `WP_MCP_AI_Video_Production_MCP_Server`            | — (tools-only)                                                                                                |

## Registering a server

Implementations extend `WP_MCP_AI_Toolkit_Server_Base` and register through the `wp_mcp_ai_register_toolkit_servers` action:

```php
add_action(
    'wp_mcp_ai_register_toolkit_servers',
    static function ( $registry ) {
        $registry->register( new My_Toolkit_MCP_Server() );
    }
);
```

Each server must implement:

- `get_slug()` — kebab-case identifier used in REST routes.
- `get_name()` / `get_description()` / `get_version()`.
- `ingestion_surfaces()` — array of `{type, page_slug, entity_type, class_ref, label, bound_assistant_id}`.
- `mounted_surfaces()` — empty by default; override to mount foreign surfaces read-only.
- `candidate_tool_slugs()` — explicit set of tool slugs this server may surface.

## Settings page

Every toolkit settings page that extends `WP_MCP_AI_Toolkit_Settings_Base` automatically gets an **MCP Server** tab when a server is registered for that toolkit. The tab has four sections:

1. **Server** — master enable/disable switch and JSON-RPC endpoint URL.
2. **Tools** — checkbox matrix of candidate tool slugs. Empty allowlist exposes every candidate.
3. **Ingestion Surfaces — Native** — disable individual R&A or C&A pages from `tools/list` / `prompts/list` / `resources/list`.
4. **Ingestion Surfaces — Mounted** — list of foreign surfaces this toolkit consumes; admin can revoke each independently of the source.

Settings persist in option `wp_mcp_ai_toolkit_mcp_server_{slug}`.

## Cross-toolkit mounts

Architectural Design's three research pages explicitly link to Healthcare's `health-records-consolidate`. Rather than duplicate or shadow that surface, Architectural Design's MCP server **mounts** it read-only:

```php
public function mounted_surfaces() {
    return array(
        array(
            'type'                => 'consolidate_add',
            'page_slug'           => 'health-records-consolidate',
            'entity_type'         => 'mcp_ai_member',
            'class_ref'           => 'WP_MCP_AI_Health_Records_Consolidate_Page',
            'source_toolkit_slug' => 'health',
            'read_only'           => true,
        ),
    );
}
```

Effective visibility rules:

- **Consumer admin disables the mount** → suppressed on consumer; source unaffected.
- **Source admin disables its server** → suppressed on every consumer.
- **Source admin disables the underlying native surface** → suppressed on every consumer that mounts that page.
- **Assistant binding** stays with the source toolkit.

Mounted prompts appear under a `_mounted/` namespace; mounted resources use URIs of the form `nvoos://{consumer}/_mounted/{source}/{entity}`.

## Tests

- `addons/pro/tests/test-toolkit-server-contract.php` — generic contract assertions.
- `addons/pro/tests/test-ingestion-surface-parity.php` — R&A-only, C&A-only, dual-surface, multi-page shapes.
- `addons/pro/tests/test-cross-toolkit-mounts.php` — mount visibility, source-disable propagation, consumer-side suppression, binding ownership.
- `addons/pro/tests/test-pro-slash-command-mcp-server.php` — slash command coverage (Phase 3b).
- `addons/pro/tests/test-pro-cli-mcp-server-command.php` — WP-CLI command coverage (Phase 3e).
- `addons/pro/tests/test-toolkit-server-credentials.php` — bearer-token coverage (Phase 3d, 15 cases).

Run them with:

```bash
vendor/bin/phpunit --group toolkit-mcp-servers
```

## `wp mcp-ai mcp-server` WP-CLI command (Phase 3e)

A Pro WP-CLI command that mirrors the `/mcp-server` slash command for use in CI/CD pipelines, WP-CLI scripts, and shell automation.

| Command                                          | Description                                                              |
|--------------------------------------------------|--------------------------------------------------------------------------|
| `wp mcp-ai mcp-server list`                      | Table of all registered servers (slug, name, status, tool_count, version). Supports `--status=enabled/disabled` and `--format=table/json/yaml/csv/ids`. |
| `wp mcp-ai mcp-server get <slug>`                | Full descriptor for one server. `--format=json` returns the raw JSON envelope. |
| `wp mcp-ai mcp-server enable <slug>`             | Set `enabled: true` for the given server.                                |
| `wp mcp-ai mcp-server disable <slug> [--yes]`    | Set `enabled: false`. Prompts for confirmation unless `--yes` is given.  |
| `wp mcp-ai mcp-server tools <slug>`              | Effective tool slugs (after admin allowlist). Supports `--format=ids` for shell pipelines. |

```bash
# Enable a server in a deployment script.
wp mcp-ai mcp-server enable crm

# Export the full server list as JSON for a CI health-check.
wp mcp-ai mcp-server list --format=json

# Confirm a specific tool slug is still exposed after a Pro update.
wp mcp-ai mcp-server tools crm --format=ids | tr ' ' '\n' | grep crm_manage_companies
```

Mutating commands write to `wp_mcp_ai_toolkit_mcp_server_{slug}` — the same option used by the admin MCP Server tab and the `/mcp-server` slash command.

A Pro slash command for chat-side inspection and toggling of toolkit MCP servers. Mirrors the conventional sub-action + `--json` envelope shape used by every other slash command.

| Sub-action               | Capability       | Description                                                                  |
|--------------------------|------------------|------------------------------------------------------------------------------|
| `/mcp-server`            | `edit_posts`     | Defaults to `list` — table of every registered server.                       |
| `/mcp-server list`       | `edit_posts`     | Same as default.                                                             |
| `/mcp-server show <slug>`| `edit_posts`     | Full descriptor (name, version, enabled flag, tool count, surfaces, limits). |
| `/mcp-server tools <slug>` | `edit_posts`   | Effective tool slugs exposed by the server (after admin allowlist).          |
| `/mcp-server enable <slug>`  | `manage_options` | Set `enabled: true` on the server's config option.                       |
| `/mcp-server disable <slug>` | `manage_options` | Set `enabled: false` on the server's config option.                      |

Aliases: `/mcp-servers`, `/toolkit-mcp`. Add `--json` to any sub-action to receive the raw envelope as JSON.

Mutating sub-actions write to option `wp_mcp_ai_toolkit_mcp_server_{slug}` — the same store used by the **MCP Server** settings tab. Disabling a server suppresses every JSON-RPC method except `initialize`/`ping` (which still return the descriptor so clients can detect the disabled state).

## Cross-mount audit trail (Phase 4)

Whenever a mounted resource or prompt is accessed through `resources/read` or `prompts/get`, the framework fires the `wp_mcp_ai_toolkit_mcp_cross_mount_read` action and records an entry in the lightweight ring-buffer audit log.

### Storage

Option key: `wp_mcp_ai_toolkit_mcp_audit_log`

The log is a JSON-serialised array of up to 200 entries (configurable via the `wp_mcp_ai_toolkit_mcp_audit_max_entries` filter). Each entry has:

| Field      | Type    | Description                                               |
|------------|---------|-----------------------------------------------------------|
| `ts`       | int     | Unix timestamp.                                           |
| `consumer` | string  | Slug of the consumer server that initiated the read.      |
| `source`   | string  | Slug of the source (mounted) server.                      |
| `entity`   | string  | Resource entity type or prompt name.                      |
| `uri`      | string  | Resource URI, or empty string for prompt reads.           |
| `method`   | string  | `resources/read` or `prompts/get`.                        |
| `user_id`  | int     | WordPress user ID at the time of the request.             |

### REST endpoint

`GET /mcp-ai-pro/v1/mcp-audit` — requires `manage_options`.

Query parameters:

| Parameter      | Default | Description                                                          |
|----------------|---------|----------------------------------------------------------------------|
| `limit`        | 50      | Max entries to return (1–200).                                       |
| `consumer`     | —       | Filter by consumer server slug.                                      |
| `source`       | —       | Filter by source server slug.                                        |
| `summary_only` | false   | Return grouped `{consumer, source, count, last_ts}` array instead.  |

### Hooks

| Hook                                        | Type   | When                                              |
|---------------------------------------------|--------|---------------------------------------------------|
| `wp_mcp_ai_toolkit_mcp_cross_mount_read`    | action | Fired before recording, for external subscribers. |
| `wp_mcp_ai_toolkit_mcp_audit_recorded`      | action | Fired after each entry is written to the buffer.  |
| `wp_mcp_ai_toolkit_mcp_audit_max_entries`   | filter | Override the ring-buffer size (default 200).      |

### Tests

`addons/pro/tests/test-toolkit-mcp-audit-log.php` — 8 cases covering record, ring-buffer trim, entry ordering, consumer filter, summary grouping, clear, action trigger, and the `audit_recorded` action.

---

## Phase 5 — Assistant UI, Observability Card, Reference Docs

### Assistant "Toolkit MCP Servers" Metabox

A metabox on the `mcp_ai_assistant` CPT edit screen lets editors choose which per-toolkit MCP servers that assistant may invoke.

- **Class:** `WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers`
- **Meta key:** `_wp_mcp_ai_pro_allowed_mcp_servers` (array of slugs)
- **Empty array** = allow all enabled servers (default behaviour).
- Static helper: `WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers::get_allowed_servers( $post_id )` → `string[]`

### Observability Dashboard Card

`WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card` injects a summary card into the  
**NV oOS Settings → Performance** section via the  
`wp_mcp_ai_performance_section_after_components` action.

The card shows:

- Total registered servers + enabled count.
- Last cross-mount read timestamp.
- Top-3 most active consumer servers (last 24 h, derived from the audit log).
- Links to the Manage MCP Servers admin page and the audit log REST endpoint.

### Server Reference Documentation

`docs/mcp-servers.md` — auto-generated Markdown reference for all 19 Tier-1 servers. Includes: slug, name, description, REST endpoint table, configuration field reference, audit log query parameters, WP-CLI examples, and slash command cheatsheet.

### Tests

`addons/pro/tests/test-phase5-toolkit-mcp-servers.php` — 6 cases covering hook binding, save_meta_box slug persistence, empty-post clearing, get_allowed_servers(), observability card graceful noop, and hook binding for the performance section action.

---

## Phase 6 — Tier-2 Toolkits + `/.well-known/mcp` Externalisation

### Tier-2 Server Inventory

Seven additional toolkits promoted to first-class MCP servers. All are **tools-only** (no CPT-shaped ingestion surface):

| Slug                   | Class                                        | Description                                                                   |
|------------------------|----------------------------------------------|-------------------------------------------------------------------------------|
| `analytics`            | `WP_MCP_AI_Analytics_MCP_Server`             | Custom reporting, funnel analysis, revenue forecasting, ML segmentation.      |
| `architect-agent`      | `WP_MCP_AI_Architect_Agent_MCP_Server`       | Shell execution, git operations, file management, codebase search.            |
| `chat-channels`        | `WP_MCP_AI_Chat_Channels_MCP_Server`         | Cross-platform messaging (Slack, Discord, Teams, WhatsApp, Telegram, etc.).   |
| `extended-cognition`   | `WP_MCP_AI_Extended_Cognition_MCP_Server`    | Multimodal sensory capture (screen, audio, visual, motion) and analysis.      |
| `healthcare-imaging`   | `WP_MCP_AI_Healthcare_Imaging_MCP_Server`    | DICOM import/export, radiology reporting, DICOMweb connectivity.              |
| `healthcare-wellness`  | `WP_MCP_AI_Healthcare_Wellness_MCP_Server`   | Vital-sign tracking, wellness check-ins, BMI, vaccinations, prescriptions.    |
| `site-creator`         | `WP_MCP_AI_Site_Creator_MCP_Server`          | Site scaffolding, layout generation, theme structure, template management.    |

### `/.well-known/mcp` Discovery Endpoint

**Class:** `WP_MCP_AI_Pro_Well_Known_MCP`

Serves a JSON discovery document at `GET /.well-known/mcp` listing every **enabled** toolkit server:

```json
{
  "mcpServers": [
    {
      "slug":        "crm",
      "name":        "CRM",
      "description": "...",
      "version":     "1.0.0",
      "endpoint":    "https://example.com/wp-json/mcp-ai-pro/v1/mcp/crm"
    },
    ...
  ]
}
```

Disabled servers (`enabled: false` in their config) are excluded from the list. The document is cache-controlled with a 1-hour `max-age` by default.

#### Filters

| Filter | Description |
|--------|-------------|
| `wp_mcp_ai_well_known_mcp_cache_max_age` | Override the `Cache-Control: max-age` value (integer seconds, default 3600; use `0` to emit `no-store`). |
| `wp_mcp_ai_well_known_mcp_document` | Modify the full discovery document array before it is sent to the client. |

#### Rewrite rules

The endpoint is served via WordPress's rewrite system:

```
^\.well-known/mcp/?$ → index.php?wp_mcp_ai_well_known_mcp=1
```

Flush rewrite rules after activation (`WP_MCP_AI_Pro_Well_Known_MCP::activate()`).

### Tests

`addons/pro/tests/test-phase6-toolkit-mcp-servers.php` — 10 cases covering Tier-2 class existence, interface compliance, slug correctness, non-empty name/description, non-empty candidate tool slugs, empty ingestion surfaces, discovery document structure, disabled-server exclusion, the `wp_mcp_ai_well_known_mcp_document` filter, and constructor hook registration.

The Tier-2 server classes are also covered by the generic contract suite:  
`addons/pro/tests/test-toolkit-server-contract.php` — data provider updated to include all 7 Tier-2 slugs.
