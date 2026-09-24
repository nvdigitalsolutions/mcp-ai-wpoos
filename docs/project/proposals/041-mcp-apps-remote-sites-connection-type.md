# Proposal 041: MCP Apps as a Remote Sites Pro Connection Type

**Date:** September 24, 2026
**Status:** In Progress — Phases 0–2 implemented (2026-09-24); Phase 3.1 (restricted hosts) and 3.4 (audit logging) partial; Phase 3.2 (server-card discovery), 3.3 (full admin OAuth flow), and Phase 4 (adopt flow) remain
**Plugin Version:** 1.1.84 (target 1.1.85+)
**Proposal Type:** Architecture / Feature Integration
**Priority:** High (security posture + connection reuse)
**Estimated Total Effort:** Phase 1: 5–7 days · Phase 2: 4–5 days · Phase 3 (optional): 5–7 days · Phase 4: 2 days

---

## Executive Summary

NV oOS currently manages Elementor MCP / remote MCP servers through the Pro
**MCP Apps** subsystem: per-assistant post meta (`_wp_mcp_ai_mcp_apps`) with
plaintext `token`/`oauth_data` fields, a 10-app cap per assistant, and no
cross-assistant reuse. Meanwhile the Pro **Remote Site Manager**
(`WP_MCP_AI_Pro_Remote_Site_Manager`) already solves the hard parts of that
problem for 30+ connection types: a central store (`wp_mcp_ai_pro_remote_sites`),
AES-256-CBC credential encryption at rest, per-type validation, test
connections, OAuth callback flows, and a Backup & Restore export provider that
decrypts/re-encrypts credentials for trusted migration.

This proposal adds **`mcp_server` as a Remote Sites connection type** and,
in a second phase, lets per-assistant MCP App entries **reference** a global
connection instead of duplicating credentials. The result:

- Elementor MCP / WordPress MCP Adapter endpoints become first-class,
  centrally managed connections (one credential, rotated in one place).
- MCP credentials are encrypted at rest like every other remote-site secret
  (closing the plaintext-post-meta gap for central connections).
- Assistant export bundles carry pointers, not secrets — complementing the
  1.1.85 portability redaction already landed (see
  `docs/assistant-import-export.md` → Redaction policy).
- Discovery aligns with emerging industry standards: MCP Server Cards
  (SEP-1649/2127), `.well-known/mcp` (SEP #1960), the `mcp://` URI scheme
  (IETF draft-serra-mcp-discovery-uri), and the MCP Authorization spec
  (2025-06-18, OAuth 2.1 resource-server model).

Backwards compatibility is a hard requirement: existing inline MCP App
configs keep working forever; adoption into Remote Sites is a one-click,
reversible migration.

---

## 1. Industry Standards Review

Research date 2026-09-24. Sources: modelcontextprotocol.io (spec, registry,
auth 2025-06-18), MCP SEP issues #1649/#1960/#2127, IETF
draft-serra-mcp-discovery-uri-04, WordPress Developer Blog (MCP Adapter),
Elementor Help (Elementor MCP), plus ecosystem analyses (Kong, WorkOS,
Aembit, Descope, Permit, Prefect).

### 1.1 Connection configuration — the `mcpServers` JSON shape

The de facto client-config standard (Claude Desktop/Code, Cursor, VS Code) is
a `mcpServers` map: `{ "<name>": { "command"/"args" (stdio) | "url" (HTTP),
"headers", "env" } }`. NV oOS already imports this shape in the MCP Apps
metabox (PR #6753). The plan keeps this as the universal paste-import format
for the new connection type.

### 1.2 Discovery — Server Cards and `.well-known/mcp`

- **MCP Server Cards (SEP-1649, moved to SEP-2127):** HTTP servers advertise
  metadata at `/.well-known/mcp/server-card.json` — name, description,
  endpoint URL, auth declaration. Not yet merged into the core spec
  (as of 2026-08) but the emerging convention.
- **`.well-known/mcp` (SEP #1960):** domain-level MCP metadata +
  security-policy declaration ("what MCP endpoints are available at this
  domain, and how do I authenticate").
- **`mcp://` URI scheme (IETF draft-serra-mcp-discovery-uri-04):** a compact
  `mcp://domain` identifier resolving to the server card — usable as a
  paste-shortcut in connection forms.

Practical takeaway: "Add MCP Server" should accept a bare site URL and
auto-discover the card (name, endpoint, auth hint), with the card cached
short-term and every field still user-editable.

### 1.3 Authentication — the MCP Authorization spec (2025-06-18)

- MCP servers are **OAuth 2.1 resource servers**: they publish Protected
  Resource Metadata (RFC 9728) pointing at trusted authorization servers and
  scopes; tokens are resource-indicated (bound to one server).
- **PKCE is mandatory** for authorization-code flows (OAuth 2.1 §7.5.2).
- Dynamic Client Registration is **deprecated** in favour of pre-registered
  clients + Client ID Metadata Documents (CIMD); DCR kept for compatibility.
- Credentials are **bound to the issuer** (SEP-2352) — re-register when the
  authorization server changes.

NV oOS already implements the client side (`WP_MCP_AI_MCP_App_OAuth_Client`:
metadata discovery, DCR, PKCE, refresh/revoke). The plan reuses it unchanged
and adds issuer-binding notes.

### 1.4 WordPress-hosted MCP (Elementor MCP) — application passwords

Elementor MCP and the WordPress MCP Adapter authenticate with **application
passwords** over HTTP Basic (sometimes `Authorization: Bearer user:pass`).
Industry guidance from the WordPress MCP Adapter announcement:

- MCP clients act as **logged-in WordPress users** — capability inheritance
  is the permission model. Use a **dedicated least-privilege user** per
  connection; never an administrator.
- Prefer read-only abilities on internet-reachable endpoints.
- Revoke via Users → Profile → Application Passwords; monitor and log usage.

The `mcp_server` connection type must therefore support `basic` and `header`
auth out of the box (Elementor flow) in addition to `bearer` and `oauth`.

### 1.5 Secret-management best practices (synthesized)

1. Encrypt at rest; never write plaintext credentials to post meta/options
   that are exported or backed up verbatim.
2. Mask in UI; never echo after save.
3. Rotate/revoke on decommission; deleting a client-side record does not
   revoke the remote credential.
4. Centralize per endpoint so rotation is a single operation.
5. Export/import must be an explicit, gated, "contains sensitive data" action
   — never a silent default.

---

## 2. Current State & Gap Analysis

### 2.1 What exists today (verified against v1.1.84 source)

| Concern | Remote Sites (Pro) | MCP Apps (Pro) |
|---|---|---|
| Storage | Option `wp_mcp_ai_pro_remote_sites`, keyed by `id` | Assistant meta `_wp_mcp_ai_mcp_apps` (plaintext) |
| Credential encryption | AES-256-CBC (`ENCRYPT_V2_PREFIX`) + legacy XOR fallback; per-field `_*_encrypted` markers | None (plaintext, UI-masked) |
| Connection types | ~30 (`wordpress`, `shopify`, `gmail`, `composio`, …) | n/a — one generic shape |
| Test | `test_connection()` per type | JSON-RPC handshake via `WP_MCP_AI_MCP_App_Client` |
| Tool discovery | n/a | `discover_tools()` + bridge into chat registry |
| Status | Test result transients in admin | `_wp_mcp_ai_mcp_app_status` snapshots |
| Admin UI | NV oOS Pro → Remote Sites (edit form, per-type fields) | Assistant editor → MCP Apps metabox (rows, badges, JSON import) |
| Slash command | n/a | `/mcp-app` (`--list/--test/--discover`) |
| REST | Admin-UI + tools only | `mcp-ai/v1/mcp-apps` CRUD + OAuth endpoints |
| Export/import | `WP_MCP_AI_Export_Provider_Remote_Sites` — decrypt on export, re-encrypt on import (`contains_sensitive_data=true`) | Assistant portability — since 1.1.85, `token`/`oauth_data` redacted by default (filters to opt out) |
| URL guard | Per-type HTTPS validation | `is_url_allowed()`: scheme + hostname allowlist (constant > filter > settings). **Does not block loopback/private ranges** (README overstates; verified) |

### 2.2 Gaps this proposal closes

1. **Plaintext MCP credentials in post meta** (G2.1). Assistant exports no
   longer leak them (1.1.85), but at rest they remain plaintext and are
   duplicated per assistant.
2. **No reuse / no single source of truth.** One Elementor MCP server on 10
   assistants = 10 credential copies; rotating the application password means
   touching 10 assistants.
3. **No discovery.** mcpServers JSON import exists, but there is no server
   card / `mcp://` / URL auto-fill, and no registry alignment.
4. **SSRF guard divergence.** Remote Sites validates per type; MCP Apps has a
   hostname allowlist but no private/loopback blocking, and the two never
   share policy.
5. **OAuth flows are MCP-App-only.** Remote Sites has mature OAuth callback
   patterns (Gmail, Google Drive, Composio) that MCP OAuth servers cannot use.

---

## 3. Design Options

| Option | Shape | Pros | Cons |
|---|---|---|---|
| **A — Global type + one-way import** | `connection_type: mcp_server` in Remote Sites (encrypted creds, test, discover). MCP Apps metabox gains "Add from Remote Sites" that copies URL + auth into the inline per-assistant config. | Minimal risk; zero migration; reuses all manager infrastructure; assistants unchanged | Credentials still duplicated at copy time; rotation still touches assistants (until Phase 2) |
| **B — Reference mode** | MCP App entries gain optional `connection_ref`; registry resolves the global connection at chat time (decrypt-on-use, short static cache). Inline configs remain supported. | Single source of truth; rotation = one edit; bundles carry pointers only | Registry gains a Pro-manager dependency; status/caching keys must handle resolution |
| **C — Full merge** | MCP Apps becomes a pure picker over global connections; inline configs deprecated. | Cleanest end state | Breaks per-assistant overrides, standalone/Pro boundaries, and all existing configs without a migration campaign |

**Recommendation: A now (1.1.85), B next (behind a feature flag), C deferred.**
Option A delivers the security win (encrypted central storage, reuse
workflow) with near-zero blast radius; Option B delivers the operational win
(single source of truth) incrementally on top.

---

## 4. Implementation Plan

### Phase 0 — Groundwork (DONE, this session)

- Portability redaction of `_wp_mcp_ai_mcp_apps` `token`/`oauth_data`
  (export + import), update-preservation on overwrite, structural
  sanitization, filters
  `wp_mcp_ai_assistant_export|import_redact_mcp_app_tokens`. Tests green on
  WP 6.9 + 7.1, phpcs clean. See `includes/assistants/class-wp-mcp-ai-assistant-portability.php`
  and `tests/test-assistant-portability.php`.

### Phase 1 — `mcp_server` connection type (Option A)

1. **Manager — `class-wp-mcp-ai-pro-remote-site-manager.php`**
   - Accept `connection_type = 'mcp_server'` in `validate_connection_data()`:
     require `url` (http/https via the shared guard, see Phase 3.1), allow
     `auth_type ∈ { none, basic_auth, application_password, custom_header,
     bearer, oauth }`; require `username`+`password` for basic/application
     password, `token` for bearer, `header_name` for custom header.
   - Store MCP-specific fields on the connection array: `header_name`,
     `verify_ssl`, `timeout`, `mcp_oauth` (nested array, JSON-encoded), and
     `discovered_at` / `tool_count` / `last_test` snapshot keys.
   - Add `token`, `password`, `mcp_oauth` to the existing secret-field
     handling (`SECRET_FIELDS` + `is_credential_field()` + `_*_encrypted`
     markers) so they encrypt at rest exactly like other types.
   - `test_connection()` dispatch: `case 'mcp_server'` → build a
     `WP_MCP_AI_MCP_App_Client` config (map remote-site auth fields → MCP
     auth: `basic_auth`/`application_password` → `basic` with
     `user:pass`; `custom_header` → `header`; `bearer` → `bearer`; `oauth` →
     `oauth` with the OAuth client) and return the handshake result.
   - New `discover_mcp_tools( $connection_id )`: decrypt, discover via the
     MCP client, cache 5 min, persist `tool_count` + `last_test` on the
     connection (mirrors `WP_MCP_AI_MCP_App_Registry::discover_tools()`).

2. **Admin — `class-wp-mcp-ai-pro-remote-sites-admin.php`**
   - Add the `<option value="mcp_server">MCP Server (Elementor MCP, WP MCP
     Adapter, generic)</option>` entry and a conditional field block
     (URL, auth select, username/password, bearer token, header name,
     verify_ssl, timeout) following the existing per-type toggle pattern.
   - Buttons: **Test Connection**, **Discover Tools** (result panel with tool
     count — reuse MCP Apps metabox JS), **Paste mcpServers JSON** (reuse the
     metabox importer), and **Auto-fill from URL** (fetch
     `/.well-known/mcp/server-card.json`, fall back to `/.well-known/mcp.json`;
     cache 6 h; every field stays editable).
   - List view: render MCP rows with a tool-count badge and last-test state.

3. **Export provider — `class-wp-mcp-ai-export-provider-remote-sites.php`**
   - Include `token`, `password`, `mcp_oauth` in the credential-field list so
     backups decrypt on export / re-encrypt on import (trusted migration,
     `contains_sensitive_data=true`). Document the deliberate contrast with
     assistant-bundle redaction (Phase 0): assistant bundles carry pointers
     only; full site backups may carry decrypted MCP credentials.

4. **Assistant MCP Apps metabox — `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-mcp-apps.php`**
   - Add an **Add from Remote Sites** dropdown listing `mcp_server`
     connections (name + URL). Selection copies URL + auth material
     (decrypted once, at copy time) into a new inline app row — no
     reference, no behaviour change for the registry.
   - Keep the existing JSON import and manual rows unchanged.

5. **Docs & skills**
   - `docs/features/remote-sites.md`: new type section.
   - `docs/features/mcp-apps.md` (create if absent): central vs per-assistant
     guidance.
   - `design-elementor-mcp-connection` skill: add the Remote Sites path
     (central connection → "Add from Remote Sites") as the recommended flow,
     retain the inline path as fallback.

### Phase 2 — Reference mode (Option B)

1. **Registry resolution — `class-wp-mcp-ai-mcp-app-registry.php`**
   - `get_apps()` returns entries verbatim; new `resolve_apps( $assistant_id )`
     expands entries carrying `connection_ref` by fetching the global
     connection and decrypting credentials on demand (static per-request
     cache; credentials never written back to post meta).
   - `register_remote_tools()` / `get_remote_tool_slugs()` /
     `test_connection()` / `discover_tools()` all consume `resolve_apps()`.
   - Missing/broken reference → record a status snapshot
     (`last_error: 'connection_ref not found'`), skip registration, log
     warning. Never silent (folder convention).
2. **Metabox:** reference rows render read-only URL + "managed in Remote
   Sites" link; allow promote/demote (inline ↔ reference) with a confirm.
3. **Portability:** assistant bundles export `connection_ref` (pointer,
   non-secret). Import keeps the ref; when the target site lacks the
   connection, add a per-item import-report warning and default the app to
   `enabled=false` rather than silently shipping a broken tool.
4. **Feature flag:** `wp_mcp_ai_mcp_app_connection_refs` filter (default
   `true` once tested); rollout note in changelog.

### Phase 3 — Governance & discovery hardening (optional but recommended)

1. **Shared URL guard.** Extract the MCP Apps `is_url_allowed()` logic into a
   shared Pro helper; both Remote Sites (for `mcp_server`) and MCP Apps call
   it. Add private/loopback/reserved-range blocking (IPv4/IPv6, RFC 1918,
   link-local, loopback) with a same-site carve-out for the in-process
   bridge, and keep the existing allowlist precedence (constant > filter >
   setting). This closes the verified gap where the MCP guard accepts
   private ranges.
2. **Server-card discovery service.** Small class caching
   `/.well-known/mcp/server-card.json` / `/.well-known/mcp.json` (6 h
   transient, `wp_safe_remote_get`, size-capped) used by both the Remote
   Sites form and the metabox import.
3. **OAuth 2.1 for MCP servers.** For `auth_type=oauth` on `mcp_server`
   connections, wire the existing `WP_MCP_AI_MCP_App_OAuth_Client` flow into
   the Remote Sites admin (probe → init → callback → refresh/revoke),
   mirroring the Gmail/Composio handler patterns; store tokens encrypted
   (`mcp_oauth`); bind refresh to the recorded issuer (SEP-2352) and
   re-register on issuer change.
4. **Audit.** Log connection CRUD, test, and discover events through
   `WP_MCP_AI_Logger` (activity type), matching the existing tool-execution
   telemetry for bridged tools.

### Phase 4 — Migration & deprecation posture

- One-click **Adopt into Remote Sites** from the MCP Apps metabox: creates a
  `mcp_server` global connection from the inline config (encrypted), swaps
  the entry to `connection_ref`, clears the inline `token`/`oauth_data`.
- Inline configs stay supported indefinitely (back-compat); no forced
  migration, no deprecation schedule until C is ever scheduled.

---

## 5. Data Model

Global connection (option `wp_mcp_ai_pro_remote_sites`, keyed by `id`):

```json
{
  "id": "mcp_elementor_client_site",
  "name": "Elementor MCP — Client Site",
  "url": "https://client.com/wp-json/mcp/elementor-mcp-server",
  "connection_type": "mcp_server",
  "auth_type": "basic_auth",
  "username": "mcp-agent",
  "password": "v2.<aes-256-cbc ciphertext>",
  "_password_encrypted": true,
  "token": "",
  "header_name": "",
  "verify_ssl": true,
  "timeout": 30,
  "mcp_oauth": "",
  "discovered_at": 1758710400,
  "tool_count": 87,
  "last_test": { "success": true, "at": 1758710400, "protocol_version": "2025-11-25" }
}
```

Per-assistant entry (meta `_wp_mcp_ai_mcp_apps`), reference form:

```json
{ "label": "Elementor", "connection_ref": "mcp_elementor_client_site", "enabled": true }
```

Auth mapping (Remote Sites → MCP client):

| Remote Sites `auth_type` | MCP client `auth_type` | Notes |
|---|---|---|
| `none` | `none` | |
| `basic_auth` / `application_password` | `basic` | `user:pass`, auto base64 (a `:` triggers encoding) |
| `custom_header` | `header` | `header_name` + `token` = raw header value (covers `Authorization: Bearer user:pass` servers) |
| `bearer` (new value) | `bearer` | |
| `oauth` (new value) | `oauth` | existing OAuth client + encrypted `mcp_oauth` blob |

---

## 6. Security

1. **Encryption parity.** New/central MCP credentials are AES-256-CBC
   encrypted at rest with `_*_encrypted` markers, identical to all other
   remote-site secrets. Inline legacy MCP App configs remain plaintext +
   UI-masked (documented; assistant-bundle export redaction already covers
   them since 1.1.85).
2. **Export/import matrix (must be documented in one table):**
   - Assistant bundles: inline tokens redacted (default on), `connection_ref`
     exported as a non-secret pointer; import warns on missing refs.
   - Remote Sites backups: decrypt/re-encrypt credentials; provider is
     `contains_sensitive_data=true`, gated in the Backup & Restore UI.
3. **SSRF convergence** (Phase 3.1): allowlist precedence unchanged
   (constant hard override > filter > Security Center setting); add
   private/loopback blocking with the same-site in-process-bridge carve-out.
   Never bypass for "internal" servers — allowlist instead (existing
   convention).
4. **Least privilege on the remote side:** Elementor MCP / WP MCP Adapter
   connections must use dedicated limited WordPress users; application
   passwords are revocable per client (Users → Profile → Application
   Passwords). Document in the admin field help.
5. **OAuth hardening:** PKCE mandatory (already in the client), issuer
   binding (SEP-2352), CIMD-first DCR fallback (already in the client),
   resource indicators preserved.
6. **Rotation workflow:** rotation happens once in Remote Sites; Phase 2
   propagates to every referencing assistant automatically. Deleting a
   client-side record never revokes the remote credential — keep the
   existing skill guidance.

---

## 7. Test Plan

- **Unit — manager:** `mcp_server` validation matrix (missing URL, scheme
  rejections, auth-combo requirements); secret-field encryption round-trip
  (`token`/`password`/`mcp_oauth`); `test_connection()` against
  `pre_http_request` mocks (mirror `tests/mcp-apps/test-mcp-app-client-connection-enhancements.php`
  patterns — one test class per file).
- **Unit — export provider:** `mcp_oauth`/`token` decrypt-on-export and
  re-encrypt-on-import; `contains_sensitive_data()` stays true.
- **Integration — reference mode (Phase 2):** resolve/decrypt-on-use;
  missing-ref → status snapshot + disabled app; assistant export carries
  `connection_ref`, never credentials; import warns on missing ref.
- **Portability suite:** extend `tests/test-assistant-portability.php`
  (Phase 0 tests are already green) with the `connection_ref` pointer
  assertions.
- **Admin:** form save/toggle for the new type (nonce + `manage_options`
  gates); discover-button result persistence.
- **Gates:** WP 6.9 + WP 7.1 phpunit runs, phpcs on changed files
  (error-severity gate), `php -l`, UTF-8 checks (house pattern 47).

---

## 8. Rollout & Rollback

- Land Phase 1 behind no flag (additive connection type + metabox dropdown —
  zero behaviour change for existing configs).
- Land Phase 2 behind `wp_mcp_ai_mcp_app_connection_refs` (default `true`
  after the integration suite is green; `false` reverts to inline-only
  resolution).
- Rollback: deleting the connection type option from the admin form does not
  affect stored connections (option data is type-agnostic); reference-mode
  entries degrade to "connection_ref not found" status snapshots rather than
  fatal errors.
- No DB schema changes; no data migration required until Phase 4 adoption.

## 9. Open Questions

1. **Per-user vs site-wide MCP credentials.** Elementor application passwords
   are per-WP-user. Should `mcp_server` support per-WP-user credential sets
   (mirroring `composio_user_mode`), or start site-wide (one service user)
   and revisit?
2. **Remote Sites REST/SPA surface.** Remote Sites is admin-UI + tools only
   today. Should `mcp_server` CRUD/discover get REST endpoints
   (`mcp-ai/v1/remote-sites/mcp-*`) for the SPA, or stay admin-only until the
   broader Remote Sites REST track exists?
3. **Allowlist policy for the shared guard.** Phase 3.1 blocks
   loopback/private ranges — some existing remote-site types intentionally
   allow localhost dev endpoints. Confirm the carve-out mechanism
   (per-type opt-out filter vs allowlist entry).
4. **Discovery caching authority.** 6 h transient vs per-server-card
   `Cache-Control` — follow headers or fixed TTL?
5. **Skill coverage.** After Phase 1/2, should `design-elementor-mcp-connection`
   also cover authoring abilities on the remote Elementor site
   (wp_register_ability / MCP Adapter development), or stay client-side only
   and point at WordPress developer docs (current stance)?

## 10. References

- Elementor MCP (setup, application passwords, revoke):
  https://elementor.com/help/how-to-connect-elementor-to-an-ai-tool-using-mcp/
- WordPress MCP Adapter + security best practices:
  https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/
- MCP Authorization spec (2025-06-18):
  https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization
- MCP Server Cards: SEP-1649/SEP-2127
  (https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1649,
  https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2127)
- `.well-known/mcp` discovery: SEP #1960
  (https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1960)
- `mcp://` URI scheme: https://datatracker.ietf.org/doc/draft-serra-mcp-discovery-uri/04/
- MCP Registry: https://modelcontextprotocol.io/registry/about
- Codebase: `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`,
  `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`,
  `addons/pro/includes/export/class-wp-mcp-ai-export-provider-remote-sites.php`,
  `addons/pro/includes/mcp-apps/README.md`,
  `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-mcp-apps.php`,
  `includes/assistants/class-wp-mcp-ai-assistant-portability.php`
- Skill: `.agents/skills/design-elementor-mcp-connection/SKILL.md`
- Docs: `docs/assistant-import-export.md` (Redaction policy),
  `docs/features/remote-sites.md`
