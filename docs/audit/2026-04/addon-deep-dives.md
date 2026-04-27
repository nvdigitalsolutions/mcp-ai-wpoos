# Phase 4 — Per-Addon Deep Dives

## 1. `addons/algorave/` — Live-coding / WebAudio

**Risk profile.** ⚠️ Medium. The addon's purpose is to evaluate user-supplied JavaScript audio code in the browser. This is a deliberate code-execution surface and must be tightly gated.

### Surface

| Item | Count |
|---|---:|
| PHP files | 20 |
| Tools | ~6 |
| Shortcodes | 2 (`algorave_live_coder`, `algorave_pattern_library`) |
| REST routes | included via base `mcp-ai/v1` |
| Frontend `eval`/`new Function` | **1** (`algorave-pattern-engine.js:917`) |

### Findings

- **F-AI-01 (High).** `new Function( 'Tone', code )` executes user-typed code in the page's main JS context with full DOM access.
  - **Required mitigation.** Run inside a sandboxed iframe or Web Worker with a strict CSP (`script-src 'self'; default-src 'none'`). Block access to `window.parent`, `document.cookie`, `localStorage`, `fetch` to non-allowlisted origins.
  - **Capability gate.** Only allow rendering of the shortcode for users with at least `edit_posts`; for the front-end shortcode, gate behind an opt-in option.
- **F-XSS-01 (Medium).** Pattern library renders user-saved snippets — verify all template insertion points use `wp_kses` / `esc_html` and never `innerHTML` on raw saved content.

## 2. `addons/canvas/` — Fabric/canvas editor

**Risk profile.** Low. Only 2 PHP files; the editor logic is JS.

### Surface

- 2 PHP files for asset registration and shortcode wiring.
- Heavy JS surface bundled under `addons/canvas/assets/` (not in this clone — built externally).

### Findings

- **F-XSS-02 (Medium).** SVG-as-image upload paths must validate against `<script>`/`<foreignObject>` injection. Confirm canvas import paths run SVG through an `wp_kses` profile that strips event handlers and script tags before persisting to the media library.
- **F-UPLOAD-02 (Low).** Confirm canvas-exported images are uploaded via `wp_handle_upload()` (not direct `move_uploaded_file()`).

## 3. `addons/cornerstone3d/` — DICOM / medical imaging

**Risk profile.** ⚠️ High consequence (PHI), low LoC. 2 PHP files but DICOM is a notoriously dangerous binary format.

### Surface

- 2 PHP files (loader + asset registration).
- JS-side parses DICOM files via Cornerstone3D library.

### Findings

- **F-UPLOAD-01 (High).** DICOM (`.dcm`) parsing of untrusted binaries is a known RCE / DoS surface (see [past `dcmtk` CVEs](https://nvd.nist.gov/vuln/search/results?query=dcmtk)). Required:
  1. File-size cap (e.g. 100 MB).
  2. Magic-number check (`DICM` at offset 128) before any further processing.
  3. Run server-side parsing (if any) inside `proc_open` with strict resource limits.
  4. Reject uploads from non-`upload_files` capability users.
- **F-PRIV-03 (High).** DICOM files routinely embed full-name + DOB + medical-record-number tags. The plugin must:
  1. Document HIPAA posture in `readme.txt` and `docs/EXTERNAL_SERVICES.md`.
  2. Strip / redact PHI tags before sending images to any AI provider.
  3. Provide a Privacy-API exporter and eraser specifically for DICOM-attached posts.
  4. Refuse to load on multisite without an explicit BAA opt-in option.

## 4. `addons/embedded/` — iframe / embeddable surface

**Risk profile.** Medium. Iframe surfaces invite clickjacking, postMessage abuse, and CSRF.

### Surface

| Item | Count |
|---|---:|
| PHP files | 36 |
| `register_rest_route` | included via main namespace |
| `uninstall.php` | present, **missing ABSPATH guard** |

### Findings

- **F-CMP-02 (Low).** `addons/embedded/uninstall.php` missing `ABSPATH` guard.
- **F-CSP-01 (Medium).** Embedded chat surface should set:
  - `X-Frame-Options: SAMEORIGIN` for the WordPress admin (default WP behaviour) **but** explicitly `frame-ancestors` CSP for the embed page allowing only configured origins.
  - On the JS side, `window.addEventListener('message', …)` must verify `event.origin` against an allowlist before acting.
- **F-AUTHZ-04 (Medium).** Guest-token issuance for embeds must:
  1. Set short TTL (≤ 24 h).
  2. Bind to a single embed origin (referer + origin checked at issuance and at use).
  3. Be revocable via admin UI.

## 5. `addons/fantasy-football/` — Yahoo / ESPN OAuth

**Risk profile.** Medium. OAuth flows + paid-API key storage + outbound HTTP.

### Surface

| Item | Count |
|---|---:|
| PHP files | 25 |
| Tools | ~14 (Yahoo + ESPN) |
| OAuth client | `league/oauth2-client` (root composer) |

### Findings

- **F-SSRF-01 (Medium, shared).** Outbound URL fetches in `class-wp-mcp-ai-tool-fantasy-football-*` should use the proposed central HTTP wrapper that blocks private IP ranges (RFC 1918, 169.254.169.254, ::1, fe80::/10).
- **F-CRYPTO-01 (Medium).** Yahoo / ESPN client_secret stored in WP options. Verify this passes through `wp_mcp_ai_encrypt` (not stored plaintext). Check `class-wp-mcp-ai-credentials.php` covers Yahoo provider.
- **F-RATE-01 (Low).** No rate-limit on outbound calls — Yahoo will block the site IP if a runaway agent loop hits the API too fast. Add per-tool exponential backoff.

## 6. `addons/graphify/` — Graph DB and rendering

**Risk profile.** ⚠️ Medium-High. Custom SQL tables + SVG rendering.

### Surface

| Item | Count |
|---|---:|
| PHP files | 22 |
| Tools | ~10 (`build-graph`, `query-graph`, `shortest-path`, …) |
| Custom tables | 3 (`{prefix}graphify_nodes`, `{prefix}graphify_edges`, `{prefix}graphify_meta`) |
| Shortcode | `nvoos_graphify` |

### Findings

- **F-SQL-01 (High).** 7 unprepared SQL statements:
  - `class-nvoos-graphify-db.php:152-154` — interpolates self-managed table names. Should use `$wpdb->prepare()` with `%i` placeholder (WP 6.2+) or wrap each interpolation with `esc_sql()` plus a strict regex against the registered table set.
  - `class-nvoos-graphify-report.php:80` — same pattern.
  - **Action.** While the variables are server-controlled today, the WPCS rule guards against future regression where a tool argument might be allowed to reach a query path.
- **F-SVG-XSS-01 (Medium).** Graph visualisation outputs SVG. Confirm `wp_kses` is applied to any node label that renders into SVG `<text>` tags.
- **F-DOS-01 (Low).** `query-graph` and `shortest-path` tools accept arbitrary node counts — add a hard cap on traversal depth and result-set size to prevent runaway queries on million-node graphs.

## 7. `addons/pro/` — Largest surface (1,141 PHP files, 584 tools)

**Risk profile.** ⚠️ High by virtue of size + the operations exposed. Detailed below.

### 7.1 Subsystems and files

| Subsystem | Path | Notes |
|---|---|---|
| Tools | `addons/pro/includes/tools/` | 584 classes; categories below |
| Services | `addons/pro/includes/services/` | Background workers, task queue, federation |
| A2A | `addons/pro/includes/a2a/` (and base) | Agent-to-agent protocol; HMAC-signed messages |
| Slash commands | `addons/pro/includes/slash-commands/` | `/help`, `/ship`, `/compact`, etc. |
| Bundled skills | `addons/pro/includes/bundled-skills/` | YAML-defined agent personas |
| Autonomous sessions | `addons/pro/includes/.../autonomous-sessions-cct.php` | Long-running agent state |
| REST controllers | `addons/pro/includes/rest/` | Webhooks for Telegram, WhatsApp, Twitter, Messenger, Google Chat |
| Vault | `addons/pro/includes/vault/` | Password / secret manager |
| Calendar booking | `addons/pro/includes/calendar-booking/` | Public-facing booking flow |
| Health/Wellness | `addons/pro/includes/.../health-wellness*.php` | PHI-handling |

### 7.2 Tool categories (top-level)

| Category | Tools | Risk | Examples |
|---|---:|---|---|
| Architect-agent | ~28 | **High** — direct shell exec | `class-wp-mcp-ai-tool-execute-shell-command.php`, `class-wp-mcp-ai-tool-git-operations.php`, `class-wp-mcp-ai-tool-search-codebase.php` |
| Document generation | ~25 | Medium — file I/O, PDF parsers | `class-wp-mcp-ai-tool-html-to-pdf.php`, `class-wp-mcp-ai-tool-merge-pdfs.php`, `class-wp-mcp-ai-tool-pro-word.php` |
| AI tool builder | ~12 | High — runs `shell_exec` for compliance check | `class-wp-mcp-ai-tool-check-tool-compliance.php` |
| Health & wellness | ~30 | High (PHI) | `class-wp-mcp-ai-tool-parse-health-information.php` |
| Vault | ~15 | High (secret data) | `class-wp-mcp-ai-tool-vault-*.php` |
| ChatChannels | ~25 | Medium (webhook auth) | Telegram, WhatsApp, Twitter, Messenger, Google Chat |
| Calendar booking | ~15 | Medium (public-facing) | booking flow |
| Real estate / vehicle / Shopify | ~40 | Medium | external-API wrappers |
| Other (CCT/CPT, automation, analytics, federation, …) | ~394 | Low–Medium | per-feature |

### 7.3 Findings specific to pro

- **F-EXEC-01 (High).** `shell_exec`/`exec` calls in 11 tool classes. Each needs:
  1. Capability gate on `manage_options` *and* a separate `wp_mcp_ai_allow_shell_tools` option that defaults to **false**.
  2. Argument allowlisting via `escapeshellarg` even though args come from server-controlled tool inputs.
  3. Log every invocation with the calling user ID + timestamp + redacted command.
  4. Refuse on shared hosts where `disable_functions` blocks `proc_open` (already implemented partially in `class-wp-mcp-ai-tool-execute-shell-command.php`).
- **F-AUTHZ-01 (High).** 11 webhook routes use `permission_callback => __return_true`. Each must verify the provider's signature header **inside the route callback** before any side-effect:
  - Telegram: `X-Telegram-Bot-Api-Secret-Token` constant-time compare.
  - WhatsApp / Messenger: `X-Hub-Signature-256` HMAC-SHA256 of body with `app_secret`.
  - Twitter: `x-twitter-webhooks-signature` (CRC validation already implemented).
  - Google Chat: bearer JWT verified against Google public keys (audit current implementation).
- **F-FS-01 (Medium).** Document-generation tools write temp files. Confirm they are:
  1. Created in `wp_upload_dir()['basedir'] . '/wp-mcp-ai-temp/'` with mode `0750`.
  2. Cleaned up via the existing `wp_mcp_ai_cleanup_*` cron jobs.
  3. Filenames are random (`wp_unique_filename`) — never user-controlled.
- **F-AUTHZ-03 (Medium).** Pro admin UI uses `current_user_can( 'manage_options' )` consistently, but multisite super-admin gates (`is_super_admin()` / `manage_network`) on the federation / fleet-wide operations still need a sweep.
- **F-PRIV-01 (Medium).** WP Privacy API exporter/eraser does not currently cover:
  - Channel messages CCT
  - Channel contacts CCT
  - Vault items CPT
  - Autonomous sessions CCT
  - Health & wellness CPT (also F-PRIV-03)
  - Calendar booking events
- **F-CMP-02 (Low).** `addons/pro/includes/vault/class-wp-mcp-ai-vault-conflict-resolver.php`, `addons/pro/includes/vault/class-wp-mcp-ai-vault-background-sync.php`, `addons/pro/build/workflow-builder/workflow-builder.asset.php` missing `ABSPATH` guards.

### 7.4 PHPCS exclusion of pro is the single biggest audit gap

`phpcs.xml.dist:24` excludes the entire pro tree. Re-enabling produces an estimated multi-thousand-error backlog (proportional to base ratio: base has 330 errors in 902 files → pro 1,141 files would surface ~400+ similar items, plus its own legacy patterns). This is the highest-leverage tooling fix and is roadmap item **R-T-01**.

---

## 8. Cross-cutting recommendations

1. **Central HTTP client wrapper** that all addons must call — implements SSRF allowlist, TLS verify on, default 10 s timeout, Bearer token attach, and per-host rate-limit bucket. Tracked as **R-A-02**.
2. **Central upload-validator service** — enforces MIME match, size cap, ClamAV scan hook, randomised filename. Tracked as **R-A-03**.
3. **Privacy registry** — every CPT/CCT registers itself with a single registry that auto-wires WP Privacy exporters/erasers. Tracked as **R-A-04**.
