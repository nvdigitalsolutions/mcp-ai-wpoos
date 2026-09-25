---
type: Skill
name: design-elementor-mcp-connection
description: Connect and manage Elementor MCP servers as "MCP Apps" on NV oOS assistants — official Elementor MCP (application passwords, permission inheritance, enable/revoke flows), third-party Elementor MCP plugins, the Pro MCP Apps metabox/slash-command/REST surfaces, the Remote Sites "MCP Server" connection type with assistant connection_ref references, auth-type mapping (basic vs header vs oauth), the host allowlist (Security Center setting, constant, filter), tool discovery/bridging into chat, and troubleshooting (allowlist 403s, restricted hosts, protocol fallback, negative discovery cache, same-site in-process bridge, missing connection_ref). Use when connecting an assistant to Elementor MCP, creating a Remote Sites MCP Server connection, using /mcp-app, fixing Test Connection or Discover Tools failures, allowlisting an Elementor host, or debugging bridged elementor_* tools missing from chat.
license: Proprietary. See LICENSE.txt
metadata:
  type: Skill
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.84"
  plugin-version-tested: "1.1.84"
  last-updated: "2026-09-24"
---

# Elementor MCP Connections — MCP Apps on NV oOS Assistants

Operational guide for connecting Elementor MCP servers (remote WordPress +
Elementor sites) to NV oOS assistants through the Pro "MCP Apps" subsystem.
Verified against plugin v1.1.84 source (`addons/pro/includes/mcp-apps/`,
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

### Path B — inline MCP App (per-assistant)

Assistant editor → **MCP Apps** metabox → **Add MCP App**. Fields (sanitized
by `WP_MCP_AI_MCP_App_Registry::sanitize_app_config()`):

| Field | Notes |
|---|---|
| `label` | Friendly name; drives the bridge slug (`mcp_app_<label>_<tool>`). Use something short like `elementor` so slugs read `mcp_app_elementor_read_page`. |
| `server_url` | Full MCP endpoint URL from the Elementor-generated prompt. Must pass `is_url_allowed()` (scheme http/https + host + allowlist when configured). Omitted on reference entries. |
| `connection_ref` | (Reference mode) ID of a central `mcp_server` Remote Sites connection. Resolved at chat time; the row renders read-only with a "Managed in Remote Sites" badge. |
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
- `POST /mcp-apps/oauth/{probe,init,refresh,revoke}`, `GET /mcp-apps/oauth/callback`.

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
   auto-falls back to the legacy sessionful `initialize` handshake
   (echoing `Mcp-Session-Id`, advertising the negotiated version) when the
   server returns `-32601`/`-32600`. A bare "connection failed" with a 2xx
   response usually means the URL points at the wrong route slug — re-check
   against the generated prompt.
3. **Tools discovered but missing from chat** — bridged tools require the
   `edit_posts` capability check at execution and are only appended when the
   effective-tools seam resolves the assistant ID. Check: app `enabled` is
   true, the discovery cache isn't stale (re-run Discover Tools), and the
   negative cache (60 s) isn't masking a temporarily-down server. Saving apps
   invalidates both the discovery cache and the `/tools` REST listing cache.
4. **Response errors mid-chat** — remote bodies are capped at 2 MB; a larger
   payload surfaces a truncation error, not a silent drop. `verify_ssl: false`
   is only for self-signed staging endpoints — prefer adding the CA instead.
5. **Elementor tools exist but calls are denied** — the remote WordPress user
   (application password owner) lacks the capability; fix the role on the
   Elementor site, not the NV oOS config.
6. **"connection_ref not found in Remote Sites"** — a reference entry points
   at a deleted/renamed central connection. Recreate the connection in Remote
   Sites, or remove the reference row from the MCP Apps metabox. Imported
   bundles with missing references are auto-disabled with a warning
   (`wp_mcp_ai_mcp_apps_validate_imported_refs`).
7. **Restricted-host rejection (Remote Sites)** — the `mcp_server` type runs
   the manager's private/reserved-range guard (no bypass); localhost/private
   endpoints must use the inline MCP App path (Path B), while the site's own
   public hostname still routes in-process via the same-site bridge.

## Security rules (industry + plugin)

- **Least privilege on both ends.** On the Elementor site, a dedicated user
  with the minimum role the workflow needs; on the NV oOS site, bridged tools
  still pass per-tool capability gating (`edit_posts`).
- **Central connections are encrypted at rest.** `mcp_server` Remote Sites
  credentials (password, token, `mcp_oauth` blob) use the manager's AES-256-CBC
  scheme — prefer Path A for production. Inline MCP App tokens (Path B) live
  in assistant post meta plaintext (UI-masked) — do not echo them into chat
  logs, commits, or docs. Record them in the Vault (see `design-vault`).
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

## Code map (verified, v1.1.85)

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
