---
type: Skill
name: design-elementor-mcp-connection
description: Connect and manage Elementor MCP servers as "MCP Apps" on NV oOS assistants — official Elementor MCP (application passwords, permission inheritance, enable/revoke flows), third-party Elementor MCP plugins, the Pro MCP Apps metabox/slash-command/REST surfaces, the Remote Sites "MCP Server" connection type with assistant connection_ref references, auth-type mapping (basic vs header vs oauth), the host allowlist (Security Center setting, constant, filter), tool discovery/bridging into chat, and troubleshooting (allowlist 403s, restricted hosts, protocol fallback, negative discovery cache, same-site in-process bridge, missing connection_ref). Use when connecting an assistant to Elementor MCP, creating a Remote Sites MCP Server connection, using /mcp-app, fixing Test Connection or Discover Tools failures, allowlisting an Elementor host, or debugging bridged elementor_* tools missing from chat.
license: Proprietary. See LICENSE.txt
metadata:
  type: Skill
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.91"
  plugin-version-tested: "1.1.91"
  last-updated: "2026-10-01"
---

# Elementor MCP Connections — MCP Apps on NV oOS Assistants

Operational guide for connecting Elementor MCP servers (remote WordPress +
Elementor sites) to NV oOS assistants through the Pro "MCP Apps" subsystem.
Verified against plugin v1.1.91 source (`addons/pro/includes/mcp-apps/`,
`includes/assistants/metaboxes/class-wp-mcp-ai-metabox-mcp-apps.php`,
`addons/pro/includes/slash-commands/`) and the Elementor MCP / WordPress MCP
Adapter public documentation (2026-09).

## When to use this skill

- Connecting an assistant to an Elementor MCP server (official Elementor MCP
  or a third-party Elementor MCP plugin).
- Creating or editing a **Remote Sites** connection with connection type
  `mcp_server`, or wiring an assistant to one via `connection_ref`
  ("Add from Remote Sites").
- Using the **MCP Apps** metabox on the assistant editor, the `/mcp-app`
  slash command, or the `mcp-ai/v1/mcp-apps` REST routes.
- Troubleshooting "Test Connection" / "Discover Tools" failures, allowlist
  rejections, or Elementor tools not appearing in chat.
- Deciding auth type for WordPress application-password-based servers.
- Anything touching assistant meta `_wp_mcp_ai_mcp_apps` /
  `_wp_mcp_ai_mcp_app_status` or the `mcp_app_*` bridged tool slugs.

## Mental model

```
Elementor site (remote)                          NV oOS site (this plugin)
────────────────────────────                     ────────────────────────────────
Elementor MCP server (official or plugin)        Assistant CPT post
  /wp-json/mcp/<server-slug>   ◄──JSON-RPC 2.0──  _wp_mcp_ai_mcp_apps meta
  Streamable HTTP + app password      over       (up to 10 app configs)
                                       HTTP      WP_MCP_AI_MCP_App_Client
                                                 WP_MCP_AI_MCP_App_Registry
                                                   ├─ tools/list discovery
                                                   ├─ WP_MCP_AI_MCP_App_Tool_Bridge
                                                   │    → local tools mcp_app_<label>_<name>
                                                   └─ wp_mcp_ai_chat_effective_tools seam
                                                        → bridged tools in chat payload
```

The remote side runs Elementor MCP (built on the WordPress **Abilities API**
+ the official **MCP Adapter**). The local side is the Pro MCP Apps subsystem:
it discovers the remote server's tools and re-registers each as a local
`mcp_app_*` tool so the assistant can call Elementor operations through normal
chat tool execution. Bridged tools register at chat time (not in the Tools
metabox) and are appended to the LLM payload via the
`wp_mcp_ai_chat_effective_tools` seam, so they are always visible to the
assistant once connected.

## Elementor MCP on the remote site — requirements and setup

### Official Elementor MCP (Elementor Core/Pro 4.3.0+)

1. **Requirements** (Elementor's stated minimums): WordPress 6.8+ (Abilities
   API is 6.9+ core, so prefer 6.9+), Elementor Core and Pro 4.3.0+.
2. **Enable:** WP Admin → **Elementor → Elementor MCP** (or Elementor →
   Editor → Elementor MCP) → **Enable MCP access** → pick the client tab
   (Claude Code, Claude Desktop, Codex, Cursor, or Other) → tick the
   acknowledgment → **Generate Prompt** → **Copy prompt**.
3. **What that prompt contains:** an **application password** (created under
   the logged-in user, named "Elementor MCP – <client>") plus a ready-to-paste
   MCP client config. Extract the **server URL** and the **username +
   application password** from it — those are the two inputs the NV oOS MCP
   App needs.
4. **Permission model:** the connected AI inherits the exact capabilities of
   the WordPress user the application password belongs to. It cannot edit
   pages, publish, or change site settings beyond that role. **Always create a
   dedicated, least-privilege user for MCP access** (industry best practice
   from the WordPress MCP Adapter guidance).
5. **Revoke:** per-client — **Users → Profile → Application Passwords →
   Revoke** next to "Elementor MCP – <client>". Site-wide — **Elementor →
   Elementor MCP → Turn off MCP access** (blocks all connected agents).
6. **Do not guess the endpoint slug.** Copy the URL from the generated prompt.
   Typical shapes across the ecosystem: `https://site.com/wp-json/mcp/<server-slug>`
   (e.g. `mcp-adapter-default-server`, third-party `elementor-mcp-server`,
   `emcp-tools-server`). The slug is per-plugin/per-install.

### Third-party Elementor MCP plugins (when the official one is not used)

- `msrbuilds/elementor-mcp` — 200+ tools, per-tool WordPress capability
  checks, ready-to-paste config including a `.mcpb` Claude Desktop bundle.
- `Digitizers/elementor-mcp` — endpoint `https://your-site.com/wp-json/mcp/elementor-mcp-server`.
- `emcptools.com` (EMCP) — `/wp-json/mcp/emcp-tools-server`, Freemius-licensed,
  up to 509 tools.
- `bvisible/elementor-mcp-api` — REST API + MCP, ships a Claude Code skill.

All of them authenticate with WordPress **application passwords**. The NV oOS
connection recipes below are identical regardless of which server you use.

## Connecting in NV oOS — two paths

### Path A (recommended) — Remote Sites connection + assistant reference

For a connection reused across assistants (or needing encrypted central
storage), create it once in **NV oOS Pro → Remote Sites → Add Connection**:

1. **Connection type:** `MCP Server (Elementor MCP / WordPress MCP Adapter)`
   (`connection_type: mcp_server`).
2. **URL:** the full endpoint from the Elementor-generated prompt
   (`https://site.com/wp-json/mcp/<server-slug>`).
3. **Authentication:** `Basic Auth` or `Application Password`
   (`username` + application password) — the manager maps this onto the MCP
   client's `basic` auth; `Custom Header` (header name + value, e.g.
   `Authorization` + `Bearer user:app-password`); `Bearer Token`; `OAuth 2.0`
   (MCP Authorization spec).
4. **Test Connection** runs a real JSON-RPC handshake; **Discover Tools**
   persists the tool count on the connection. Credentials are encrypted at
   rest (AES-256-CBC) like every other remote-site secret.
5. On the assistant editor → **MCP Apps** metabox → **Add from Remote Sites**
   → pick the connection. This creates a **reference entry**
   (`connection_ref` + label + enabled) — the credential lives centrally and
   is resolved (decrypt-on-use) at chat time. Rotating the application
   password in Remote Sites updates every referencing assistant.
   **(v1.1.90):** the dropdown also lists **Upwork-in-MCP-mode** connections
   (labelled `Name (Upwork MCP — https://mcp.upwork.com/mcp)`) —
   `resolve_connection_ref()` resolves those refs via
   `build_upwork_mcp_app_config()` (official gateway + the decrypted central
   `mcp_oauth` blob), the import validator no longer auto-disables them, and
   `finalize_oauth_flow()` persists the reference entry onto the assistant
   after the OAuth login completes.

### Path B — inline MCP App (per-assistant)

Assistant editor → **MCP Apps** metabox → **Add MCP App**. Fields (sanitized
by `WP_MCP_AI_MCP_App_Registry::sanitize_app_config()`):

| Field | Notes |
|---|---|
| `label` | Friendly name; drives the bridge slug (`mcp_app_<label>_<tool>`). Use something short like `elementor` so slugs read `mcp_app_elementor_read_page`. |
| `server_url` | Full MCP endpoint URL from the Elementor-generated prompt. Must pass `is_url_allowed()` (scheme http/https + host + allowlist when configured). Omitted on reference entries. |
| `connection_ref` | (Reference mode) ID of a central Remote Sites connection — `mcp_server` or Upwork-in-MCP-mode (v1.1.90+). Resolved at chat time; the row renders read-only with a "Managed in Remote Sites" badge. |
| `auth_type` | `none`, `bearer`, `basic`, `header`, or `oauth`. See the mapping below. |
| `token` | Bearer token / Basic credentials / raw header value / OAuth access token. Masked in the UI — never echoed back after save; leaving it blank preserves the stored value. |
| `header_name` | Custom header name, only for `auth_type: header`. |
| `enabled` | Per-app on/off switch (default true). |
| `timeout` | 1–120 s (default 30). |
| `verify_ssl` | Default true. |

Hard limits (constants on the registry): **10 apps per assistant**
(`MAX_APPS_PER_ASSISTANT`), 2 MB max response body (`MAX_RESPONSE_SIZE`),
5-minute discovery cache (`CACHE_TTL`), 60-second negative cache
(`FAILURE_CACHE_TTL`).

### Auth mapping for Elementor MCP (application passwords)

WordPress application passwords authenticate over HTTP Basic. Two supported
mappings, pick by what the server expects:

- **Preferred — `auth_type: basic`**, `token: <wp-username>:<application-password>`.
  The client auto-base64-encodes the credentials; a `:` in the token is the
  encode trigger, so raw `user:pass` input is fine. Application passwords
  print with spaces (e.g. `2SEB qW5j D7CW fpsh pbmN RGva`) — paste without the
  spaces in the password portion.
- **Alternative — `auth_type: header`**, `header_name: Authorization`,
  `token: Bearer <wp-username>:<application-password>` — for servers that read
  the application password from an `Authorization: Bearer user:pass` header
  (documented by some WordPress MCP plugins).

`oauth` is for servers running real OAuth 2.0 (metadata discovery, DCR, PKCE,
refresh) — Elementor MCP does not use it; use `basic`/`header` instead.
Discovery (v1.1.91+) walks the full MCP Authorization spec chain:
`WP_MCP_AI_MCP_App_OAuth_Client::discover_metadata()` tries RFC 8414 metadata
on the MCP origin + the §3.2 path-insertion variant, then RFC 9728
protected-resource metadata (every advertised `authorization_servers` entry),
then the 401 `WWW-Authenticate: Bearer resource_metadata` probe, then OIDC
`.well-known/openid-configuration`, then the WordPress REST metadata fallback.
RFC 8414 documents are accepted only with both `authorization_endpoint` and
`token_endpoint`; every attempt is recorded and the metabox failure alert
surfaces the real transport error (`attempts` + `hint`), the
server-advertised `default_scope` is honored, and per-probe timeouts cap at
10 s — so a "discovery failed" alert now tells you *which* step failed.

### Test, discover, import

- **Test Connection** — runs the JSON-RPC handshake and renders a status badge
  (Connected / Error / Not tested) persisted to `_wp_mcp_ai_mcp_app_status`.
- **Discover Tools** — fetches `tools/list` and shows the tool count; results
  are cached 5 minutes keyed by config hash.
- **JSON import** — paste a `mcpServers` block (Claude Desktop / Claude Code /
  Cursor format) to bulk-fill rows; entries are capped at the 10-app limit.
  This is the fastest path when the user already has an Elementor-generated
  client config — paste it and let the importer extract URL + credentials.
- Same-site URLs (the Elementor MCP endpoint lives on *this* WordPress site)
  are routed in-process via `rest_do_request()` instead of an outbound HTTP
  call, avoiding PHP-FPM pool deadlocks / hairpin-NAT stalls. The metabox JS
  warns when it detects this.

## Verification and troubleshooting

### Slash command `/mcp-app` (chat surface, requires manage_options, guests blocked)

```
/mcp-app                        # list apps for the current assistant
/mcp-app --test=<label>         # test one app's connection
/mcp-app --discover=<label>     # re-run tool discovery for one app
/mcp-app --assistant-id=<n> --json
```

### REST (namespace `mcp-ai/v1`, admin-gated)

- `POST /mcp-apps/test` — `server_url`, auth fields → handshake result.
- `POST /mcp-apps/discover` — `server_url`, `assistant_id` → tool list.
- `GET /mcp-apps/{assistant_id}` — stored configs (tokens redacted in output —
  verify masking before claiming otherwise).
- `POST /mcp-apps/oauth/{probe,init,refresh,revoke}`, `GET /mcp-apps/oauth/callback`,
  `POST /mcp-apps/oauth/complete` (manual loopback paste-back, 10-min state TTL).

### Failure patterns (root-cause order)

1. **"host is not on the configured allowlist" (403)** — the URL guard
   (`is_url_allowed()`) is rejecting the host. Add it to **Settings →
   Security Center → Network & Headers → "MCP App Allowed Hosts"** (textarea,
   one host per line, stored as `mcp_app_allowed_hosts` in `wp_mcp_ai_settings`).
   Precedence: `WP_MCP_AI_MCP_APP_ALLOWED_HOSTS` constant (hard override when
   defined — UI setting is then ignored) → `wp_mcp_ai_mcp_app_allowed_hosts`
   filter → saved setting. **Never bypass the guard for "internal" servers —
   add an allowlist entry instead.** Note: with no allowlist configured the
   guard is permissive (logs a warning); the code checks scheme + host only —
   it does NOT block loopback/private IPs, so an empty allowlist is genuinely
   open to arbitrary upstream servers. Recommend always configuring one.
2. **Test Connection fails on an older server** — the client first attempts
   the stateless `server/discover` handshake (protocol `2026-07-28`) and
   auto-falls back to the legacy sessionful `initialize` handshake when the
   server returns `-32601`/`-32600`/`session` errors **or a bare HTTP
   400/404/405/501 without a JSON-RPC error envelope** (strict 2025-era
   gateways reject the unknown method that way). The fallback `initialize`
   handshake and every request inside a legacy session omit the 2026-only
   `MCP-Protocol-Version`/`Mcp-Method` routing headers and the `_meta`
   envelope, so they look exactly like a 2025-era client (Claude Desktop /
   Cursor shape). Once a legacy fallback succeeds, a per-URL **dialect hint**
   (transient `wp_mcp_ai_mcp_app_legacy_<md5>`, 24h) skips the doomed probe on
   subsequent tests/connects (saves one round trip per connect cycle; visible
   as `Handshake: initialize` with no `server/discover` on the wire). A stale
   hint self-heals: if `initialize` is rejected with a stateless signature
   (`-32601`/`-32600` or HTTP 400/404/405/501), the hint is cleared and
   `discover()` is retried. A bare "connection failed" with a 2xx response
   usually means the URL points at the wrong route slug — re-check against the
   generated prompt.
3. **Tools discovered but missing from chat** — bridged tools require the
   `edit_posts` capability check at execution and are only appended when the
   effective-tools seam resolves the assistant ID. Check: app `enabled` is
   true, the discovery cache isn't stale (re-run Discover Tools), and the
   negative cache (60 s) isn't masking a temporarily-down server. Saving apps
   invalidates both the discovery cache and the `/tools` REST listing cache.
4. **"Tool enumeration failed: MCP server returned invalid JSON"** — some
   gateways (Envoy AI Gateway / Agent Router) answer `initialize` with plain
   JSON but every post-initialize request (`tools/list`, `tools/call`) with an
   **SSE stream** (`text/event-stream`, `event: message` + `data: {…}`). The
   client now detects SSE via the `Content-Type` header **or** `data:` body
   sniffing (some gateways mislabel SSE as `application/json`) and extracts
   the JSON-RPC message via `parse_sse_payload()`; an empty stream surfaces
   the dedicated `wp_mcp_ai_mcp_app_empty_sse` error. On older builds, update
   the plugin and re-run Test Connection.
5. **"No OAuth access token available" after a successful web login** — the
   metabox never sends `oauth_data` back (credentials are masked server-side).
   Current builds restore the stored `oauth_data` blob (including `client_id`,
   which Upwork requires on token/refresh requests) in `resolve_stored_token()`,
   persist rotated tokens (inline assistant meta / central `update_mcp_oauth()`),
   and re-hydrate the freshest credentials before each bridge execution.
6. **"Response errors mid-chat"** — remote bodies are capped at 2 MB; a larger
   payload surfaces a truncation error, not a silent drop. `verify_ssl: false`
   is only for self-signed staging endpoints — prefer adding the CA instead.
7. **Elementor tools exist but calls are denied** — the remote WordPress user
   (application password owner) lacks the capability; fix the role on the
   Elementor site, not the NV oOS config.
8. **"connection_ref not found in Remote Sites"** — a reference entry points
   at a deleted/renamed central connection. Recreate the connection in Remote
   Sites, or remove the reference row from the MCP Apps metabox. Imported
   bundles with missing references are auto-disabled with a warning
   (`wp_mcp_ai_mcp_apps_validate_imported_refs`).
9. **Restricted-host rejection (Remote Sites)** — the `mcp_server` type runs
   the manager's private/reserved-range guard (no bypass); localhost/private
   endpoints must use the inline MCP App path (Path B), while the site's own
   public hostname still routes in-process via the same-site bridge.
10. **Upwork MCP (`https://mcp.upwork.com/mcp`) returns "HTTP 400"** — Upwork's
   gateway rejects the 2026-07-28 `server/discover` probe with a bare HTTP
   400, which older client builds never fell back from. Verified fix (client
   + registry fallback on 400/404/405/501, legacy-header omission) shipped in
   the mcp-apps cluster — deploy it and re-run Test Connection / Discover
   Tools. **Remote Sites freelance-marketplace mode:** Upwork connections
   (`connection_type: upwork`) also accept `upwork_mode: mcp`, which drives
   the CRM search/import tools through the same gateway (`upwork__find_jobs`
   action `search`/`get`, `upwork__list_accounts` for `org_uid`); the login
   flow is the MCP Apps REST flow with `connection_ref` set to the Upwork
   connection ID, persisting tokens to its encrypted `mcp_oauth` field.
   Diagnostics caveat: Upwork binds OAuth access tokens to the
   originating server IP, so reproducing the handshake with `curl` from
   another machine returns 401 even with a valid token — validate on the site
   itself (the HTTP-error `WP_Error` data now includes a 400-char response
   body snippet). Upwork auth is OAuth 2.1 with DCR and **loopback-only
   redirect URIs**: the login tab ends on
   `http://localhost:<port>/callback?code=...&state=...` that never loads —
   paste that URL into the metabox's manual paste field and click **Complete
   Login** within the 10-minute state TTL. An expired state returns "This
   login link has expired" — re-initiate and paste the new URL. The paste-back
   box is **always visible** for unauthenticated OAuth rows (survives page
   reloads), and token exchange/refresh now send `client_id` in the request
   body (Upwork rejects requests without it with
   `invalid_request: Missing parameters: client_id`).

## Security rules (industry + plugin)

- **Least privilege on both ends.** On the Elementor site, a dedicated user
  with the minimum role the workflow needs; on the NV oOS site, bridged tools
  still pass per-tool capability gating (`edit_posts`).
- **Central connections are encrypted at rest.** `mcp_server` Remote Sites
  credentials (password, token, `mcp_oauth` blob) use the manager's AES-256-CBC
  scheme — prefer Path A for production. Inline MCP App secrets (Path B: token,
  `oauth_data` access/refresh) are **also encrypted at rest since v1.1.88**
  (same scheme) and masked in the metabox with blank-submit preservation —
  never echo them into chat logs, commits, or docs. Record them in the Vault
  (see `design-vault`). Remote Sites `verify_token` (WhatsApp/Messenger) and
  `verification_token` (Google Chat) are encrypted too, centrally decrypted in
  `get_connection()`; the three raw-store webhook read sites decrypt
  explicitly. Legacy plaintext rows keep working (decrypt-on-read is
  idempotent) and encrypt on their next save. Rotated OAuth tokens persist
  back to assistant meta / the central `mcp_oauth` blob, and central rotations
  emit `mcp_oauth_refresh` activity events with metadata only.
- **Revoke when done.** Rotate/revoke the application password on the
  Elementor site when a connection is decommissioned; deleting the NV oOS app
  row or Remote Sites connection does not revoke the remote credential.
- **Export/import:** the assistant portability engine redacts inline `token` /
  `oauth_data` fields on export and strips them from imports by default;
  `connection_ref` entries export as **non-secret pointers** (no credentials
  ever ride in assistant bundles). Remote Sites backups
  (`WP_MCP_AI_Export_Provider_Remote_Sites`) decrypt/re-encrypt credentials by
  design (trusted migration, `contains_sensitive_data=true`). Opt-out filters
  for assistant bundles: `wp_mcp_ai_assistant_export|import_redact_mcp_app_tokens`
  (default `true`). See the portability skill.
- **Prefer read-only ability sets on public endpoints** (WordPress MCP Adapter
  guidance): if the Elementor MCP endpoint is internet-reachable, the remote
  server should expose read/diagnostic abilities rather than destructive ones.
- **Monitor usage** — tool-execution telemetry fires for bridged tools like
  any other tool; MCP Server test/discover events are activity-logged; review
  logs after Elementor write operations.

## Code map (verified, v1.1.90)

| Concern | Location |
|---|---|
| Registry (meta keys, allowlist, discovery, status, reference resolution) | `addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php` |
| Transport client (handshake, auth types, fallback) | `addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php` |
| Tool bridge | `addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-tool-bridge.php` |
| OAuth client | `addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-oauth-client.php` |
| REST controller | `addons/pro/includes/mcp-apps/class-wp-mcp-ai-rest-mcp-apps-controller.php` |
| Registration + effective-tools seam + import-ref validator | `addons/pro/includes/mcp-apps/mcp-apps-init.php` |
| Remote Sites `mcp_server` type (save/validate/test/discover/mapping) | `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php` |
| Remote Sites admin UI (type option, MCP fields, toggles) | `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` |
| Assistant metabox (UI, badges, JSON import, Add from Remote Sites, ref rows) | `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-mcp-apps.php` |
| Slash command | `addons/pro/includes/slash-commands/commands/class-wp-mcp-ai-pro-slash-command-mcp-app.php` |
| Folder contract | `addons/pro/includes/mcp-apps/README.md` |

Key constants: `META_KEY = '_wp_mcp_ai_mcp_apps'`,
`STATUS_META_KEY = '_wp_mcp_ai_mcp_app_status'`, `MAX_APPS_PER_ASSISTANT = 10`,
`CACHE_TTL = 300`, `FAILURE_CACHE_TTL = 60`,
`PROTOCOL_VERSION = '2026-07-28'`, `MAX_RESPONSE_SIZE` = 2 MB.

## Cross-references

- `design-elementor-template-kits` — designing/building/importing Elementor
  page content once connected (kits vs live MCP editing are different tracks;
  MCP Apps give the agent live editing tools, kits give offline import).
- `design-ai-assistant-admin` — assistant-level configuration this skill
  plugs into (meta keys, capability gates).
- `mcp-ai-wpoos-assistant-portability` — export/import of assistants carrying
  MCP App configs (token caveat above).
- `mcp-ai-wpoos-plugin` — JSON-RPC over HTTP mechanics shared with the MCP
  bridge.
- `design-vault` — store the application passwords/tokens.
- `wp-security-deep` — SSRF review of the URL guard before touching it.
- `wp-security-secrets` — auditing token storage/handling.

## What this skill does NOT cover

- Running the NV oOS plugin as an MCP *server* for other clients — that is the
  inverse direction; see `addons/pro/includes/mcp-servers/`.
- Authoring Elementor template kits or fixing kit imports —
  `design-elementor-template-kits`.
- Writing Elementor abilities on the remote site (wp_register_ability /
  MCP Adapter development) — WordPress developer docs.
- The general chat UI / Elementor widget embeds of NV oOS — plugin chat docs.

## References

- Elementor MCP setup/revoke: https://elementor.com/help/how-to-connect-elementor-to-an-ai-tool-using-mcp/
- WordPress MCP Adapter + best practices: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/
- MCP Apps extension (SEP-1865): https://modelcontextprotocol.io/extensions/apps/overview
- Folder contract: `addons/pro/includes/mcp-apps/README.md`
- Tests: `tests/mcp-apps/test-mcp-app-chat-exposure.php`,
  `tests/mcp-apps/test-mcp-app-client-connection-enhancements.php`,
  `addons/pro/tests/test-pro-slash-command-mcp-app.php`
