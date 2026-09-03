# Pro SPA v2 Shortcode — Proposal & Implementation Plan

**Date:** 2026-09-03
**Status:** Draft — for review
**Author:** Zed coding agent (per user request)
**Related docs:** `docs/project/proposals/pro-spa-tool-shortcuts-commands.md`, `docs/project/proposals/chat-spa-v2-parity-plan.md`, `addons/pro/assets/spa-v2/IMMEDIATE_NEXT_STEPS.md`
**Version:** 1.0

---

## 1. Problem Statement

The Pro SPA v2 (`addons/pro/assets/spa-v2/`) is the modern TypeScript/esbuild/React 19 replacement
for the legacy webpack `pro-spa`, but it is currently **admin-only**:

- `WP_MCP_AI_Pro_SPA_Loader` (`addons/pro/includes/class-wp-mcp-ai-pro-spa-loader.php`) registers a
  top-level admin page (`wp-admin/admin.php?page=wp-mcp-ai-spa`, capability `read`) that renders
  `<div id="wp-mcp-ai-pro-spa-root">` and enqueues `pro-spa.js` / `pro-spa.css`.
- The module registry marks the `spa_loader` module with `context => admin`.
- There is **no shortcode** (verified: `add_shortcode` has no Pro SPA registration anywhere in the
  repo). The front-end embed options today are the base `[mcp_ai_chat]` shortcode and the chat-spa
  addon's `[nvoos_chat_spa]`.

Ask: expose the Pro SPA v2 chat experience on the front end via a shortcode so page builders,
landing pages, and client portals can embed the Pro chat surface (threads, memory, OKF drawer,
tool shortcuts, HITL approvals) without the admin shell.

### Non-goals (v1)

- No full 6-route admin SPA (Settings/Tools/Assistants/Workflows/Analytics) on the front end —
  those routes are admin tooling and are capability-gated anyway.
- No changes to the base `[mcp_ai_chat]` or chat-spa `[nvoos_chat_spa]` shortcodes.

---

## 2. Options Considered

| Option | Description | Verdict |
|--------|-------------|---------|
| **A. Ship full SPA in shortcode** | Mount `App` (3-column layout + all routes) on the front end | ❌ Rejected. Router is hash-based (conflicts with page anchors / multiple instances), admin CSS is scoped to `body.toplevel_page_wp-mcp-ai-spa`, and Settings/Tools/Assistants/Workflows/Analytics expose admin surfaces to the public. |
| **B. Embedded chat-first mode (recommended)** | New `mode: 'embedded'` renders `ChatPage` (+ optional transcripts sidebar, tool shortcuts, OKF drawer) directly, no router, no admin pages | ✅ Chosen. Matches the proven `nvoos_chat_spa` pattern, reuses the existing `[data-config]` multi-instance mount stub already present in `src/index.tsx`, and surfaces the Pro-specific features the chat-spa doesn't have. |
| **C. Do nothing / point users at `[nvoos_chat_spa]`** | Document that front-end embeds use chat-spa | ⚠️ Fallback. Valid for pure chat, but loses Pro features (threads, workflows UI, OKF drawer, tool shortcuts, HITL). Option B only builds where value > cost. |

---

## 3. Architecture

### 3.1 High-level flow

```
Page content:  [nvoos_pro_spa assistant_id="12" mode="embedded" theme="dark" guest="0"]
                        │
                        ▼
WP_MCP_AI_Pro_SPA_Shortcode::render()
  ├─ shortcode_atts() sanitize → build per-instance $config (like chat-spa)
  ├─ apply_filters( 'nvoos_pro_spa_can_render', true, $atts )  → '' short-circuit
  ├─ enqueue pro-spa.js / pro-spa.css (register once, enqueue per instance)
  ├─ wp_localize_script( NVOOS_PRO_SPA, shared runtime ) — built by extracted config builder
  └─ emit <div class="nvoos-pro-spa-root nvoos-pro-spa-embedded" data-config="…">
                        │
                        ▼
pro-spa.js index.tsx mountAll()
  └─ querySelectorAll( '#wp-mcp-ai-pro-spa-root, [class*="-root"][data-config]' )
       └─ per container: readProSpaConfig() merged with data-config JSON
            └─ mode === 'embedded' ? <EmbeddedApp/> : <App/>
```

### 3.2 Component diagram

```
┌──────────────────────────────────────────────────────────────────┐
│ Front end (page / post / widget)                                 │
│                                                                  │
│  WP_MCP_AI_Pro_SPA_Shortcode  ──►  WP_MCP_AI_Pro_SPA_Config     │
│        (new, frontend)                  (extracted builder)      │
│              │                                    │              │
│              │ localize NVOOS_PRO_SPA + data-config│              │
│              ▼                                    ▼              │
│        pro-spa.js (IIFE)  ──►  readProSpaConfig()  │              │
│              │                    (merge global + per-instance)  │
│              ▼                                                    │
│   EmbeddedApp (chat-first, no router)  or  App (admin, existing) │
│              │                                                    │
│              └──► mcp-ai/v1/chat-client (SSE)                     │
│                   mcp-ai-pro/v1/* (threads, okf, shortcuts, HITL) │
└──────────────────────────────────────────────────────────────────┘
```

### 3.3 Per-instance config contract (mirrors `ProSpaPerInstanceConfig`)

```json
{
  "mode": "embedded",
  "assistantId": 12,
  "theme": "auto",
  "height": "720px",
  "guest": false,
  "allowSensitiveTools": false,
  "showSidebar": true,
  "routes": ["chat"]
}
```

`mode` and `routes` are additive fields on `ProSpaPerInstanceConfig` in
`src/api/config.ts`; default `mode` is `'admin'` so the admin page behavior is unchanged.

---

## 4. PHP Design

### 4.1 New class: `WP_MCP_AI_Pro_SPA_Shortcode`

File: `addons/pro/includes/class-wp-mcp-ai-pro-spa-shortcode.php`

- `const SHORTCODE = 'nvoos_pro_spa';` (prefix-consistent with `nvoos_chat_spa`, `nvoos_status`).
- `register()` → `add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) )`.
- `render( $atts )`:
  1. `shortcode_atts()` with defaults `assistant_id=''`, `mode='embedded'`, `theme='auto'`,
     `height=''`, `guest='0'`, `allow_sensitive_tools='0'`, `show_sidebar='1'`.
  2. Validate: `mode` ∈ {`embedded`, `admin`} — **`admin` is rejected on the front end unless the
     current user has `manage_options`** (fall back to `embedded`); `theme` ∈ {auto, light, dark}.
  3. `apply_filters( 'nvoos_pro_spa_can_render', true, $atts )` gate.
  4. Sanitize/escape: `absint()` assistant ID, `esc_attr()` everything in the `data-config` JSON.
  5. Call `WP_MCP_AI_Pro_SPA_Shortcode::enqueue_assets( $config )`.
  6. Return
     `<div class="nvoos-pro-spa-root nvoos-pro-spa-embedded" data-config="{json}"></div>`
     (identical `data-config` pattern to `NV_oOS_Chat_Spa_Shortcode`).

### 4.2 Extracted builder: `WP_MCP_AI_Pro_SPA_Config`

File: `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php`

- Move the `$runtime` assembly currently inline in `WP_MCP_AI_Pro_SPA_Loader::enqueue()` into a
  static `WP_MCP_AI_Pro_SPA_Config::build( array $per_instance )` so admin page and shortcode share
  one source of truth for `NVOOS_PRO_SPA` (apiUrl, proApi, nonce, endpoints, user, assistants).
- Front-end deltas:
  - `nonce` = `wp_create_nonce( 'wp_rest' )` for logged-in users; empty for guests.
  - Guests: mint a guest access token using the existing guest-token machinery
    (`WP_MCP_AI_Shortcode::GUEST_TOKEN_*` pattern in `includes/class-wp-mcp-ai-shortcode.php` +
    `X-WP-MCP-AI-Guest` header support in the REST authenticator) and include it in the runtime so
    the SPA can send the header. No token, no render, when `guest=1` but guest access is disabled
    globally (`allow_guest_access` setting).
  - Endpoint allowlist shrinks for non-admins: `workflows`, `analytics`, `approvals` empty unless
    `manage_options`.

### 4.3 Module registry

`addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php`:

```php
$this->add_module(
    'spa_shortcode',
    'Pro SPA Shortcode',
    array(),
    array( 'context' => 'frontend' ),
    function () use ( $p ) {
        $f = $p . 'class-wp-mcp-ai-pro-spa-shortcode.php';
        if ( file_exists( $f ) ) {
            require_once $f;
            WP_MCP_AI_Pro_SPA_Shortcode::register();
        }
    }
);
```

`WP_MCP_AI_Pro_SPA_Loader` keeps its `admin` context; it gains a dependency on the extracted config
builder only.

### 4.4 REST delta

`GET /mcp-ai-pro/v1/spa/bootstrap` currently hard-requires `read` (403 for guests). Embedded mode
does **not** call bootstrap at all (v1) — `EmbeddedApp` seeds from `NVOOS_PRO_SPA` like the
chat-spa does. Bootstrap changes are deferred to Phase 3 (below); if a guest-capable bootstrap is
ever needed it must return a redacted payload (no threads, no tools catalogue, no user object) and
validate the guest token via the REST authenticator.

---

## 5. SPA v2 (TypeScript) Changes

| File | Change |
|------|--------|
| `src/api/config.ts` | Add `mode?: 'admin' \| 'embedded'`, `showSidebar?`, `routes?: string[]`, `height?`, `guest?`, `guestToken?` to `ProSpaPerInstanceConfig`. Add `mergePerInstanceConfig( dataConfig )` that reads the `data-config` attribute JSON and overlays it on `window.NVOOS_PRO_SPA`. |
| `src/index.tsx` | `mountAll()` already selects `[class*="-root"][data-config]` — pass each container's merged config into the root render (today it renders `<App />` with no props). |
| `src/App.tsx` | Read `config.mode`; render `<EmbeddedApp runtime={…} />` when `embedded`, else existing `<App />` path. |
| `src/features/embedded/EmbeddedApp.tsx` (NEW) | Chat-first layout: `ChatPage` + optional transcripts sidebar (`show_sidebar`), `ToolShortcutsDrawer`, `SlashCommandsDrawer`, `OkfDrawer`, `MemoryDrawer`, `HitlApprovalBar` — reusing existing feature components. **No router, no command palette, no settings/tools/assistants/workflows/analytics routes.** |
| `src/features/chat/ChatPage.tsx` | Ensure it can render standalone without router `useParams`/thread URL coupling (it currently derives thread from URL — embedded mode passes `threadId` as prop and defaults to conversation mode). |
| `src/styles/embedded.css` (NEW) | Front-end scoping: the admin CSS relies on `body.toplevel_page_wp-mcp-ai-spa` selectors that never match on the front end. Add `.nvoos-pro-spa-embedded` root styles (height from `data-config`/CSS var, overflow, z-index stacking against themes) and `@media` responsive pass. Import from `index.tsx`. |
| `src/sse-adapter.ts` | Send `X-WP-MCP-AI-Guest` header when `config.guest` and a guest token exists (mirror chat-spa's `guest` handling). |

**Hash-router constraint (documented):** v1 supports **one** embedded instance per page.
Multi-instance needs a router-free embedded mode (which is the v1 design) so this is only a
documentation note, not a blocker.

---

## 6. Security Model

| Concern | Decision |
|---------|----------|
| Default access | `guest='0'` — only logged-in users (`read`+) get a working instance by default. |
| Guest opt-in | `guest='1'` requires the global `allow_guest_access` security setting and an assistant whose config permits guests; token minted server-side, TTL capped (`GUEST_TOKEN_MAX_TTL`), never exposed in page source outside the runtime global. |
| Sensitive tools | `allow_sensitive_tools='0'` default; mirror the chat-client's existing enforcement. |
| Capability gating | `workflows`/`analytics`/`approvals` endpoints empty unless `manage_options`; `admin` mode blocked on front end below `manage_options`. |
| Output escaping | `data-config` JSON via `wp_json_encode()` + `esc_attr()`; container `aria-label` translatable + escaped. |
| Nonce | `wp_rest` nonce only for authenticated users; guests use the token header path. |
| State-changing routes | No new REST routes in v1 — the shortcode rides existing authenticated endpoints, so no new attack surface. |

---

## 7. File Map

### Files to create

| File | Description |
|------|-------------|
| `addons/pro/includes/class-wp-mcp-ai-pro-spa-shortcode.php` | Shortcode class (`nvoos_pro_spa`) |
| `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php` | Shared `NVOOS_PRO_SPA` runtime builder |
| `addons/pro/assets/spa-v2/src/features/embedded/EmbeddedApp.tsx` | Chat-first embedded layout |
| `addons/pro/assets/spa-v2/src/styles/embedded.css` | Front-end scoped styles |
| `addons/pro/assets/spa-v2/src/features/embedded/__tests__/EmbeddedApp.test.tsx` | Vitest coverage |
| `addons/pro/tests/test-pro-spa-shortcode.php` | PHPUnit coverage |
| `docs/project/proposals/033-pro-spa-v2-shortcode-proposal.md` | This document |

### Files to modify

| File | Change |
|------|--------|
| `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` | Register `spa_shortcode` module (`context => frontend`) |
| `addons/pro/includes/class-wp-mcp-ai-pro-spa-loader.php` | Use extracted config builder; unchanged admin behavior |
| `addons/pro/assets/spa-v2/src/api/config.ts` | New per-instance fields + `mergePerInstanceConfig()` |
| `addons/pro/assets/spa-v2/src/index.tsx` | Per-container config pass-through |
| `addons/pro/assets/spa-v2/src/App.tsx` | Mode switch (`embedded` vs admin) |
| `addons/pro/assets/spa-v2/src/features/chat/ChatPage.tsx` | Standalone render without router URL coupling |
| `addons/pro/assets/spa-v2/src/sse-adapter.ts` | Guest header support |
| `CHANGELOG.md` | Feature entry |
| `README.md` | Shortcode reference section |
| `addons/pro/README.md` | Addon shortcode docs |

### Not modified (explicitly out of scope)

`includes/class-wp-mcp-ai-shortcode.php` (base `[mcp_ai_chat]`), chat-spa addon,
`class-wp-mcp-ai-pro-spa-bootstrap-controller.php` (deferred to Phase 3).

---

## 8. Phased Rollout

### Phase 1 — P0: Core embedded shortcode (this proposal)

- PHP: shortcode + config builder + module registry + PHPUnit tests.
- TS: config merge, `EmbeddedApp` chat-first, front-end CSS, guest header, Vitest.
- Gate: `mode='embedded'` only; no bootstrap endpoint changes.
- Definition of done: `[nvoos_pro_spa assistant_id="12"]` renders a working chat on a front-end
  page for a logged-in user; admin page unchanged; `composer run lint` + PHPUnit + `npm test` green.

### Phase 2 — P1: Pro feature parity in embedded mode

- Transcripts sidebar (`show_sidebar`), `ToolShortcutsDrawer`, `OkfDrawer`, `MemoryDrawer`,
  `HitlApprovalBar` wired into `EmbeddedApp` (components already exist — this is wiring + CSS).
- Guest mode hardening: rate limiting via existing load guard, transcript scoping per guest token.

### Phase 3 — P2: Opt-in admin routes & block

- `mode='admin'` on front end for `manage_options` users only (full `App` with routes), including a
  front-end-scoped stylesheet for the admin layout.
- Redacted guest-capable `spa/bootstrap` payload if bootstrap becomes necessary for embedded mode.
- Gutenberg block (`addons/pro/includes/blocks/`) wrapping the shortcode (mirrors chat-spa block).

---

## 9. Testing Plan

**PHPUnit (`addons/pro/tests/test-pro-spa-shortcode.php`):**
- Render returns root div with `data-config` and `nvoos-pro-spa-embedded` class.
- `assistant_id` sanitized (`absint`), unknown `theme` clamps to `auto`.
- `mode='admin'` downgraded to `embedded` for non-admins; allowed for admins.
- `nvoos_pro_spa_can_render` filter returning false → empty string.
- `guest='1'` with guest access disabled → empty string (or logged notice).
- Shortcode registered (`shortcode_exists( 'nvoos_pro_spa' )`).

**Vitest (`addons/pro/assets/spa-v2`):**
- `mergePerInstanceConfig` overlays `data-config` onto the global.
- `EmbeddedApp` renders chat composer; no crash with missing `NVOOS_PRO_SPA` global.
- Missing required endpoints → graceful error state (config reader contract).

**Manual:**
- Front-end page with one instance; logged-in chat round-trip (SSE).
- Guest round-trip with token.
- Admin page (`page=wp-mcp-ai-spa`) regression pass.

---

## 10. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Admin CSS leaks/breaks on front end (body-class scoping) | High | Medium | Separate `embedded.css` + `.nvoos-pro-spa-embedded` scoping; visual regression checklist in PR. |
| Hash router conflicts on pages with anchors | Medium | Medium | Embedded mode is router-free by design (Phase 1); multi-instance doc note. |
| Guest token abuse (cost/load) | Medium | High | Opt-in only, TTL cap, rides existing load guard + cost tracker; Phase 2 hardening. |
| Bundle size on front end (~5 MB source maps excluded; IIFE bundle) | Medium | Low | Enqueue only when shortcode present (conditional enqueue); minified prod build already standard. |
| Divergence between loader and shortcode config | Low | Medium | Single extracted `WP_MCP_AI_Pro_SPA_Config::build()` — one source of truth. |
| ChatPage router coupling (thread URL param) | Medium | Medium | Explicit Phase 1 task: prop-drive `threadId`, default to conversation mode. |

---

## 11. Backward Compatibility

- ✅ Admin page behavior unchanged (`NVOOS_PRO_SPA` shape is a superset).
- ✅ New config fields optional with defaults; old bundles ignore them.
- ✅ No changes to existing shortcodes, options, or REST routes.
- ✅ `nvoos_pro_spa` is a new slug — no collision.
- ✅ Old `pro-spa.js` (legacy webpack) untouched.

---

## 12. Review Checklist

- [x] Follows `WP_MCP_AI_` naming and `nvoos_` shortcode prefix conventions
- [x] Security model covers auth (nonce/guest token), authorization (caps), escaping
- [x] Front-end vs admin context split defined (`spa_shortcode` vs `spa_loader` modules)
- [x] Base vs Pro gating: Pro-only, no base changes
- [x] Tests specified (PHPUnit + Vitest)
- [x] Backward compatibility assessed
- [x] Non-goals and phased scope explicit

*Next step: review, then break into atomic stories (PHP shortcode, config extraction, TS embedded
mode, CSS, tests) and PR them against `alpha-working` per the repo cluster workflow.*
