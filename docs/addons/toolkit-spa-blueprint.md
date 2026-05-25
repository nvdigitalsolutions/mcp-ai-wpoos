# Toolkit SPA Blueprint

> **Status:** Phases 0–9 complete · Tier A manifests complete · canvas-toolkit v0.2.0 · document-editor v0.2.0 · chat-spa v0.6.0 (Tier E — memory drawer, HITL bar, attachments, regenerate, branching + legacy opt-out). Last reviewed: **May 2026** · Version: **2.5**
>
> This document formalizes the reusable pattern established by
> [`addons/docs-hub/`](../../addons/docs-hub/) for shipping a React Single-Page
> Application (SPA) as an installable NV oOS addon. Every new toolkit-SPA addon
> (CRM, calendar-booking, financial-planner, document-generation, canvas, etc.)
> **must** follow this blueprint so the surfaces stay consistent, secure, and
> easy to maintain.

---

## 1. Why a blueprint?

The Docs Hub addon proved out an end-to-end pattern for "an installable WP
plugin folder that ships a React SPA as a new surface onto the NV oOS REST
backend." The same pattern is now reused by every Pro toolkit that needs a
dedicated UI without fragmenting the chat surface.

Following the blueprint guarantees:

- **One way to enqueue and mount** — shortcode + Gutenberg block + admin embed,
  all rendering the same `<div class="…-root" data-config="…">` container.
- **One way to auth** — re-uses the four NV oOS auth modes (WP nonce, assistant
  credentials, Auth0, guest tokens). No new tokens.
- **One way to cache** — two-layer cache (filesystem + 1h transients), invalidated
  on plugin activate/deactivate/upgrade.
- **One way to ship** — pre-built esbuild IIFE bundle committed under
  `assets/dist/`, bumped in lockstep with the PHP version constant and
  `package.json` so `?ver=` query strings invalidate browser caches.
- **One way to test** — PHPUnit tests covering shortcode, REST contract, and
  manifest scanning.
- **One way to attribute** — `THIRD_PARTY_NOTICES.md`, root `CREDITS.md`, and a
  per-addon `README.md` "Credits" section for every upstream library.

---

## 2. The eight reusable pieces (from Docs Hub)

| # | Piece | Docs Hub citation |
|---|-------|-------------------|
| 1 | Standalone WP plugin folder with own header + GPLv3 + base-plugin dependency check | [`addons/docs-hub/nvoos-docs-hub.php`](../../addons/docs-hub/nvoos-docs-hub.php) |
| 2 | esbuild IIFE bundle (React 19 + react-router) → `assets/dist/<slug>.{js,css}` | [`addons/docs-hub/esbuild.config.js`](../../addons/docs-hub/esbuild.config.js) |
| 3 | Dedicated REST namespace `/wp-json/nvoos-<slug>/v1/*` (public reads + capability-gated writes + nonces) | [`addons/docs-hub/includes/rest/class-nvoos-docs-hub-rest.php`](../../addons/docs-hub/includes/rest/class-nvoos-docs-hub-rest.php) |
| 4 | Shortcode + Gutenberg block — both render `<div class="…-root" data-config>` and `wp_localize_script()` API URL + nonce + config | [`addons/docs-hub/includes/shortcode/class-nvoos-docs-hub-shortcode.php`](../../addons/docs-hub/includes/shortcode/class-nvoos-docs-hub-shortcode.php) |
| 5 | Two-layer cache (filesystem JSON + transients), `.htaccess`/`index.php` guards, auto-invalidation | [`addons/docs-hub/includes/class-nvoos-docs-hub-cache.php`](../../addons/docs-hub/includes/class-nvoos-docs-hub-cache.php) |
| 6 | WP-CLI parity (`sync` / `clear` / `status`) | [`addons/docs-hub/includes/class-nvoos-docs-hub-cli.php`](../../addons/docs-hub/includes/class-nvoos-docs-hub-cli.php) |
| 7 | Cron rebuild job + filters/actions for extensibility | [`addons/docs-hub/includes/jobs/class-nvoos-docs-hub-rebuild-job.php`](../../addons/docs-hub/includes/jobs/class-nvoos-docs-hub-rebuild-job.php) |
| 8 | PHPUnit tests for scanner, indexer, REST manifest, shortcode | [`addons/docs-hub/tests/`](../../addons/docs-hub/tests/) |

---

## 3. Directory layout

Every toolkit-SPA addon **must** match this layout (replace `<slug>` with the
addon slug, e.g. `toolkit-shell`, `canvas-toolkit`, `document-editor`,
`ohif-viewer`):

```
addons/<slug>/
├── nvoos-<slug>.php                      # Plugin entry — header + constants
├── uninstall.php                         # Cleanup on delete
├── README.md                             # Includes "Credits" section
├── THIRD_PARTY_NOTICES.md                # Per-addon attribution
├── package.json                          # Pinned deps; "version" matches PHP constant
├── package-lock.json                     # Committed (npm ci on CI)
├── esbuild.config.js                     # IIFE bundle to assets/dist/
├── tsconfig.json                         # If using TS
├── includes/
│   ├── class-nvoos-<slug>-plugin.php     # Singleton: hook registration + lifecycle
│   ├── admin/class-nvoos-<slug>-settings.php
│   ├── block/
│   │   ├── block.json
│   │   └── class-nvoos-<slug>-block.php
│   ├── jobs/class-nvoos-<slug>-rebuild-job.php  # Cron handler (optional)
│   ├── rest/class-nvoos-<slug>-rest.php  # Namespace: nvoos-<slug>/v1
│   └── shortcode/class-nvoos-<slug>-shortcode.php
├── src/                                  # React SPA source (TS/TSX)
│   ├── index.tsx
│   ├── App.tsx
│   ├── api/
│   ├── components/
│   ├── routes/
│   └── styles/main.css
├── assets/dist/                          # COMMITTED pre-built artifacts
│   ├── <slug>.js                         # IIFE bundle
│   └── <slug>.css                        # Extracted stylesheet
├── tests/
│   ├── test-rest.php
│   ├── test-shortcode.php
│   └── test-manifest.php                 # If manifest-driven
├── config/spa-manifests/                 # Optional: per-toolkit manifests
└── languages/.gitkeep
```

> **Manifest-driven shells** (e.g. `addons/toolkit-shell/`) read per-toolkit
> manifest JSON files from `addons/pro/config/spa-manifests/<toolkit-slug>.json`
> so the same React bundle can serve CRM, calendar, financial-planner, etc.

---

## 4. Naming conventions

| Element | Pattern | Example |
|---------|---------|---------|
| Plugin folder | `addons/<slug>/` | `addons/toolkit-shell/` |
| Plugin entry file | `nvoos-<slug>.php` | `nvoos-toolkit-shell.php` |
| PHP class prefix | `NV_oOS_<TitleSlug>_*` | `NV_oOS_Toolkit_Shell_Plugin` |
| PHP constant prefix | `NVOOS_<UPPER_SLUG>_*` | `NVOOS_TOOLKIT_SHELL_VERSION` |
| Option key | `nvoos_<slug>_<thing>` | `nvoos_toolkit_shell_settings` |
| REST namespace | `nvoos-<slug>/v1` | `nvoos-toolkit-shell/v1` |
| Shortcode | `[nvoos_<slug>_app]` (or shorter) | `[nvoos_toolkit]` |
| Gutenberg block | `nvoos/<slug>` | `nvoos/toolkit-shell` |
| Root container class | `nvoos-<slug>-root` | `nvoos-toolkit-shell-root` |
| Localized JS global | `NVOOS_<UPPER_SLUG>` | `NVOOS_TOOLKIT_SHELL` |
| Filter prefix | `nvoos_<slug>_*` | `nvoos_toolkit_shell_manifest` |
| Action prefix | `nvoos_<slug>_*` | `nvoos_toolkit_shell_after_rebuild` |

> **Important:** these are **addon** prefixes. The base plugin uses
> `WP_MCP_AI_*` / `wp_mcp_ai_*` (see `CLAUDE.md`). Do not mix them.

---

## 5. Build & version-bump workflow

**Three places must be bumped in the same commit** (per the stored build-process
memory):

1. Plugin header `Version:` in `nvoos-<slug>.php`
2. `define( 'NVOOS_<UPPER_SLUG>_VERSION', '<x.y.z>' );` immediately below
3. `"version": "<x.y.z>"` in `package.json`

The PHP constant is passed to `wp_enqueue_script()` and `wp_enqueue_style()` as
the `$ver` argument, which forces browsers to fetch the new bundle. Skipping
step 1, 2, or 3 leaves stale assets in users' caches.

### Build command

```bash
cd addons/<slug>
npm ci          # use lockfile; never `npm install` on CI
npm run build   # produces assets/dist/<slug>.{js,css}
```

The pre-built artifacts **must** be committed (per `addons/docs-hub/` and
`addons/saas-controller/` patterns). Without them the shortcode emits a
"Loading failed for the script" error on first page load. Add a missing-bundle
admin notice (à la `addons/saas-controller/includes/admin/`-style
`render_missing_bundle_notice`) so operators see a clear error.

### What to git-ignore

```gitignore
node_modules/
*.log
.cache/
.tsbuildinfo
```

Do **not** ignore `assets/dist/` or `package-lock.json`.

---

## 6. REST namespace contract

Every addon registers its routes under `nvoos-<slug>/v1`. Standard endpoints:

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `GET` | `/manifest` | Public (or capability-gated if domain-sensitive) | Returns the addon's manifest (resources, fields, views) |
| `GET` | `/resources/{type}` | Capability-gated | List items |
| `GET` | `/resources/{type}/{id}` | Capability-gated | Get one item |
| `POST` | `/resources/{type}` | Capability + nonce | Create |
| `PUT` | `/resources/{type}/{id}` | Capability + nonce | Update |
| `DELETE` | `/resources/{type}/{id}` | Capability + nonce | Delete |
| `POST` | `/rebuild` | `manage_options` + nonce | Rebuild any cached data |
| `GET` | `/health` | `manage_options` | Diagnostics |

Rules (matching the Docs Hub controller):

- Permission callbacks **must** check capabilities — never `__return_true` on a
  mutating route.
- All inputs go through `sanitize_text_field`, `absint`, etc. (see
  `.context/security-checklist.md`).
- All outputs go through `esc_*` helpers when emitted as HTML; JSON responses
  use `WP_REST_Response`.
- Mutating routes accept the WP nonce header (`X-WP-Nonce`) and reject CSRF.
- **SPAs must talk to the Pro toolkits' existing `mcp-ai-pro/v1/*` endpoints**
  for actual domain data — do not duplicate the data plane. The addon's own
  `nvoos-<slug>/v1/*` namespace is for SPA-specific concerns (manifest, cache,
  rebuild, health).

---

## 7. Render surfaces

### Shortcode

```php
[nvoos_<slug>_app toolkit="crm" theme="auto" view="kanban"]
```

Reserved attributes (every addon should accept these):

| Attribute | Default | Purpose |
|-----------|---------|---------|
| `toolkit` | (none) | Which manifest to load (manifest-driven shells only) |
| `theme` | `auto` | `auto` / `light` / `dark` |
| `view` | (manifest default) | Which view to render |
| `height` | (CSS default) | Optional fixed height |

### Gutenberg block

`nvoos/<slug>` block.json declares the same attributes; `render_callback`
delegates to the shortcode renderer.

### Admin screen

Optional. If the addon needs an admin-only UI, register a top-level or submenu
page that renders the same root container — never use an iframe.

### Container contract

All three surfaces emit:

```html
<div id="nvoos-<slug>-root-<uniq>"
     class="nvoos-<slug>-root"
     data-config='{"toolkit":"crm","theme":"auto",…}'></div>
```

The React entry queries `.nvoos-<slug>-root[data-config]` and mounts a root in
each match. This makes embedding multiple instances per page safe.

---

## 8. Authentication

SPAs **must** use the existing four-mode auth pattern (see `CLAUDE.md`):

1. **WP Nonce** (default for logged-in admin/editor surfaces) — `X-WP-Nonce`
   header, set via `wp_localize_script()`.
2. **Assistant credentials** — `Authorization: Bearer cred_xxxxx.SECRET`. Use
   when the SPA is acting as an assistant client.
3. **Auth0 tokens** — `Authorization: Bearer <auth0-token>`.
4. **Guest tokens** — `X-WP-MCP-AI-Guest` for public-facing surfaces (e.g.,
   public booking widgets). Only enable when `current_user_can()` would
   otherwise gate the surface — never on admin endpoints.

Do not invent a fifth auth mode.

---

## 9. Caching

Every addon that ships a manifest or transformed data **must** use the
two-layer pattern from `NV_oOS_Docs_Hub_Cache`:

1. **Filesystem** — JSON files in `wp-content/uploads/nvoos-<slug>/`, protected
   by:
   - `.htaccess` — `Deny from all` for Apache
   - `index.php` — `<?php // Silence is golden.`
2. **WordPress transients** — fast key/value with 1-hour TTL.

Filesystem acts as warm-up for transients on cache miss. Cache invalidates on:

- Plugin activation / deactivation (`activated_plugin`, `deactivated_plugin`)
- Plugin upgrade (`upgrader_process_complete`)
- Manual rebuild via REST `POST /rebuild`, WP-CLI `clear`, or admin button

---

## 10. Tests

`tests/` mirrors Docs Hub:

- `test-shortcode.php` — verifies the shortcode emits the root container,
  honours attributes, and respects `nvoos_<slug>_can_render` filter.
- `test-rest.php` — exercises every route's permission callback and contract.
- `test-manifest.php` (manifest-driven shells) — verifies the manifest JSON
  schema and that unknown fields are rejected gracefully.
- `test-cache.php` (optional) — round-trips through both layers.

Run via the repo-standard:

```bash
composer run test -- --filter '<TestClass>'
```

---

## 11. Per-toolkit manifest schema (manifest-driven shells)

The reference manifest-driven shell is `addons/toolkit-shell/`. Manifests are
loaded from two roots in priority order (later overrides earlier):

1. `addons/toolkit-shell/config/spa-manifests/<toolkit-slug>.json` — bundled
   defaults, always present so the shell remains usable without Pro.
2. `addons/pro/config/spa-manifests/<toolkit-slug>.json` — Pro-shipped
   manifests, override bundled defaults.

Custom roots can be added via the `nvoos_toolkit_shell_manifest_dirs` filter.
The filename **is** the toolkit slug — the shell uses it instead of the JSON
`toolkit` field, so manifests cannot spoof their slug.

Schema:

```jsonc
{
  "$schema": "https://nvdigitalsolutions.com/schemas/toolkit-spa-manifest-v1.json",
  "version": "1.0",
  "toolkit": "crm",
  "label": "CRM",
  "icon": "groups",
  "rest_namespace": "mcp-ai-pro/v1",
  "capability": "edit_posts",
  "resources": [
    {
      "name": "contacts",
      "label": "Contacts",
      "endpoint": "/crm/contacts",
      "primary_key": "id",
      "fields": [
        { "name": "id",        "type": "integer", "readonly": true },
        { "name": "full_name", "type": "string", "label": "Name", "required": true },
        { "name": "email",     "type": "email"  },
        { "name": "stage",     "type": "enum", "options": ["lead","qualified","won","lost"] }
      ]
    }
  ],
  "views": [
    { "name": "list",   "resource": "contacts", "default": true,  "type": "table"  },
    { "name": "kanban", "resource": "contacts", "type": "kanban", "group_by": "stage" }
  ]
}
```

Validation rules (enforced by `NV_oOS_Toolkit_Shell_Manifest_Registry::sanitize_manifest()`):

- `toolkit` is `sanitize_key`'d. Manifests with no `toolkit` or no `resources` are dropped silently.
- `rest_namespace` must match `^[a-z0-9\-]+/v\d+$`. Anything else (including URLs) falls back to `mcp-ai-pro/v1`.
- `capability` is `sanitize_key`'d and enforced at request time on the manifest endpoint.
- `fields[].type` is one of `string|number|integer|email|url|date|datetime|enum|text|boolean|reference`. Unknown types fall back to `string`.
- `views[].type` is one of `table|kanban|detail|form|calendar|chart`. Unknown types fall back to `table`.
- `resources[].endpoint` characters outside `[A-Za-z0-9_\-/{}]` are stripped.
- File size is capped at 256 KB; oversized files are dropped.
- Unknown top-level keys are dropped (never errored on) so manifests can grow.

REST endpoints:

- `GET /wp-json/nvoos-toolkit-shell/v1/manifests` — list all manifest summaries (auth: `read`).
- `GET /wp-json/nvoos-toolkit-shell/v1/manifests/{toolkit}` — single manifest (auth: the manifest's declared `capability`).
- `GET /wp-json/nvoos-toolkit-shell/v1/health` — diagnostics (auth: `manage_options`).

---

## 12. Vetting checklist for new upstream SPAs

Before adding a new dependency to any toolkit-SPA addon, every candidate
**must** clear all ten gates:

1. **License** — MIT, Apache-2.0, BSD-2/3, or ISC. Reject AGPL (except for
   community addons that already are AGPL — e.g. `algorave`), BUSL, SSPL,
   custom. Verify in upstream `LICENSE` and `package.json`.
2. **Bundle weight** — gzip < 200 KB for Tier A/C shells. Specialist shells
   (OHIF, Remotion, tldraw) ship as **separate** addons.
3. **Maintenance** — last commit ≤ 6 months, ≥ 100 weekly npm downloads, no
   critical CVEs (run `gh-advisory-database`).
4. **React 19 compatibility** — Docs Hub already pins React 19; reject deps
   that hard-pin React 18 peer-deps.
5. **Headless / embeddable** — must mount into a `<div>`, no router takeover,
   no global CSS reset that conflicts with `wp-admin` styles.
6. **Data shape** — exposes a `dataProvider` hook or props-based flow that
   maps to `mcp-ai-pro/v1/*` REST shapes without forking.
7. **i18n** — exposes a string table or supports `wp.i18n` interop.
8. **Accessibility** — WCAG 2.1 AA out of the box.
9. **Security** — no inline `eval`, no remote script loads at runtime
   (must be self-hosted under `assets/dist/`); SSRF-safe for any URL inputs.
10. **Attribution** — added to the addon's `THIRD_PARTY_NOTICES.md`,
    root `CREDITS.md`, and the per-addon README "Credits" section.

> Run the [`gh-advisory-database`](https://github.com/advisories) tool against
> every new dependency before `npm install`. This is a **standing repo rule**
> per the Copilot Coding Agent system prompt.

---

## 13. Tier matrix (recommended SPA pieces per Pro toolkit)

The 24 Pro toolkits map to **6–8 reusable shells** rather than 24 bespoke SPAs:

### Tier A — Data-CRUD shells
*One bundle (`addons/toolkit-shell/`), driven by per-toolkit manifests.*

| Toolkit | Manifest | Recommended SPA pieces |
|---------|---------|------------------------|
| `crm` | [`crm.json`](../../addons/pro/config/spa-manifests/crm.json) | refine + `@dnd-kit/sortable` Kanban (Pipeline / contacts / deals) |
| `calendar-booking` | [`calendar-booking.json`](../../addons/pro/config/spa-manifests/calendar-booking.json) | `@fullcalendar/react` (MIT) |
| `financial-planner` | [`financial-planner.json`](../../addons/pro/config/spa-manifests/financial-planner.json) | refine + `react-financial-charts` + `recharts` (all MIT) |
| `analytics` | [`analytics.json`](../../addons/pro/config/spa-manifests/analytics.json) | refine + `recharts` / `visx` (MIT) |
| `regulatory-registration` | [`regulatory-registration.json`](../../addons/pro/config/spa-manifests/regulatory-registration.json) | refine |
| `law-firm` | [`law-firm.json`](../../addons/pro/config/spa-manifests/law-firm.json) | refine |
| `cre-debt` | [`cre-debt.json`](../../addons/pro/config/spa-manifests/cre-debt.json) | refine |
| `multilingual` | [`multilingual.json`](../../addons/pro/config/spa-manifests/multilingual.json) | refine + Monaco (`@monaco-editor/react`, MIT) |

### Tier B — Canvas / whiteboard shells
*Separate addon `addons/canvas-toolkit/` (lazy-loaded by mode).*

Ships all four canvas modes (v0.2.0):

| Mode | Library | Status |
|------|---------|--------|
| `flow` | `@xyflow/react` MIT | ✅ shipped v0.1.0 |
| `whiteboard` | `tldraw` v5 MIT | ✅ shipped v0.2.0 |
| `bpmn` | `bpmn-js` MIT | ✅ shipped v0.2.0 |
| `mermaid` | `mermaid` MIT | ✅ shipped v0.2.0 |

| Toolkit | Recommended mode |
|---------|-----------------|
| `architectural-design` | `whiteboard` (tldraw) or `bpmn` (bpmn-js) |
| `architect-agent` | `whiteboard` (tldraw) + `mermaid` (Mermaid live preview) |
| `ai-tool-builder` | `flow` (@xyflow/react — visual node-graph) |

### Tier C — Document / rich-text shells
*Separate addon `addons/document-editor/` (Tiptap + GrapesJS).*

| Toolkit | Recommended SPA pieces | Status |
|---------|------------------------|--------|
| `document-generation` | Tiptap (MIT, ProseMirror-based) | ✅ shipped v0.1.0 |
| `site-creator` | Tiptap + GrapesJS (`@grapesjs/react`, MIT) | ✅ shipped v0.2.0 |

### Tier D — Domain-specialist shells
*Each ships as its own addon (à la `cornerstone3d` and `canvas`).*

| Toolkit | Addon | Recommended SPA pieces |
|---------|-------|------------------------|
| `healthcare-imaging` | `addons/ohif-viewer/` | OHIF Viewer v3 (MIT) — extends `cornerstone3d` |
| `healthcare` | `addons/medplum-react/` | Medplum React Components (Apache-2.0) — only if FHIR endpoints are added |
| `video-production` | `addons/video-studio/` | etro.js (MIT) — Remotion has a non-standard license, must verify |
| `image-production` | `addons/media-studio/` | tldraw + react-image-crop + react-konva (all MIT) |
| `media` | `addons/media-studio/` | wavesurfer.js (BSD-3) + react-player (MIT) |
| `dj-management` | extends `addons/algorave/` | Tone.js (MIT) |
| `chat-channels` | (no new addon) | Reuses base chat UI |
| `ecommerce` | manifest in `toolkit-shell` — [`ecommerce.json`](../../addons/pro/config/spa-manifests/ecommerce.json) | refine + product cards |
| `social-media` | manifest in `toolkit-shell` — [`social-media.json`](../../addons/pro/config/spa-manifests/social-media.json) | refine + react-big-calendar (MIT) overlay |
| `extended-cognition` | (deferred) | Custom (timeline + visx) |

### Tier E — Chat surfaces
*Modern React replacements for the legacy `assets/js/chat.js` UI. Uses
`@ai-sdk/react`'s `useChat` hook on the React side only; the WordPress PHP
layer remains the orchestrator and AI provider gateway.*

| Toolkit | Addon | Recommended SPA pieces |
|---------|-------|------------------------|
| `chat` | [`addons/chat-spa/`](../../addons/chat-spa/) | `@ai-sdk/react` (Apache-2.0) + client-side SSE → AI SDK Data Stream Protocol adapter ([`src/sse-adapter.ts`](../../addons/chat-spa/src/sse-adapter.ts)) |

---

## 14. Risks & guardrails

| Risk | Guardrail |
|------|-----------|
| License creep | Every new dep must clear §12 gate 1 + `gh-advisory-database`. Remotion / FullCalendar Premium / Syncfusion / AG-Grid Enterprise are **not** acceptable. |
| Bundle bloat | Tier A bundle stays < 200 KB gzipped. Specialist shells are **separate addons**, lazy-loaded via dynamic `import()`. |
| Coupling to Pro CCTs | SPAs talk only through REST. Backing CCTs/CPTs must register on `init` priority **≥ 11** (see stored memories about JetEngine CCT init priority). |
| Auth surface | Re-use the four-mode auth pattern. Guest tokens only on public-facing surfaces. |
| Cache & version-bump | Bump plugin header + define + `package.json` together. Without the bump, `?ver=` is stale. |
| Multi-agent ownership | Each new addon needs `.github/agents/<addon>-maintainer.agent.md` per the layering rule in `AGENTS.md` §2 (slim file — no naming/security/PHP-compat rules). |
| Don't fragment chat | These SPAs are **complementary** surfaces. Expose existing tools via REST; do not re-implement orchestration. |

---

## 14. Phase 5 quality gates — a11y hardening & CSP

Every toolkit-SPA addon **must** pass these gates before merging to `main`.
The CI workflow [`.github/workflows/spa-a11y.yml`](../../.github/workflows/spa-a11y.yml)
enforces items 1–3 automatically.

### 1. eslint-plugin-jsx-a11y (automated, blocking)

All `src/**/*.{ts,tsx}` sources must pass `eslint --max-warnings 0` with the
`jsx-a11y/recommended` ruleset. The config lives in `eslint.config.js` in each
addon root.

```bash
npm run lint:a11y   # runs: eslint --max-warnings 0 src/
```

Key rules this enforces (WCAG 2.1 AA subset):
- `alt-text` — all `<img>`, `<canvas>`, and `<object>` elements have alt text.
- `no-noninteractive-element-to-interactive-role` — do not attach `role="tab"` /
  `role="button"` etc. to block-level landmark elements (`<nav>`, `<section>`).
  Use `<div role="tablist">` instead of `<nav role="tablist">`.
- `aria-required-attr` / `aria-valid-attr` — ARIA attributes match the role.
- `interactive-supports-focus` — all interactive elements are keyboard-focusable.
- `click-events-have-key-events` — click handlers must be paired with key handlers
  (or the element must be natively interactive like `<button>`).

### 2. TypeScript type-check (automated, blocking)

`npm run typecheck` (`tsc --noEmit`) must pass with zero errors. Type-checking
is run before the a11y lint in CI.

### 3. Mount container ARIA landmark (automated, enforced in PHP)

Every shortcode/block PHP render method **must** output:

```php
'<div class="nvoos-<slug>-root" role="application" aria-label="%s" data-config="%s">',
esc_attr( __( 'Human-readable title', 'nvoos-<slug>' ) ),
esc_attr( $config_json )
```

`role="application"` signals to screen readers that the region contains a
complex interactive widget. The `aria-label` identifies the surface. This is
the WCAG 1.3.6 Identify Purpose requirement.

### 4. axe-core dev integration (local, non-blocking in prod)

Each addon's `src/index.tsx` boots `@axe-core/react` in non-production builds:

```ts
if ( process.env.NODE_ENV !== 'production' ) {
    Promise.all( [ import('react'), import('react-dom'), import('@axe-core/react') ] )
        .then( ( [ React, ReactDOM, axe ] ) => axe.default( React, ReactDOM, 1000 ) )
        .catch( () => {} );
}
```

esbuild replaces `process.env.NODE_ENV` with `"production"` via the `define`
option, dead-code-eliminating the entire block. Run `npm run build:dev` to get
a bundle with live axe output in the browser console.

### 5. CSP compliance (manual, one-time per addon)

All toolkit-SPA addons are **CSP-safe by construction**:

- esbuild produces a single IIFE bundle — no `eval()`, no `new Function()`.
- All scripts are self-hosted under `assets/dist/` — no remote CDN loads.
- Nonces are injected by WordPress via `wp_enqueue_script()` and `wp_localize_script()`.

Recommended `Content-Security-Policy` header for sites hosting these addons:

```
Content-Security-Policy:
  default-src 'self';
  script-src  'self' 'nonce-{WP_NONCE}';
  style-src   'self' 'nonce-{WP_NONCE}' 'unsafe-inline';
  img-src     'self' data: blob: https:;
  media-src   'self' data: blob: https:;
  connect-src 'self' https://{your-domain};
  frame-src   'self' https://www.youtube.com https://player.vimeo.com;
```

Notes:
- `style-src 'unsafe-inline'` is required by Tiptap (document-editor) and
  react-konva (media-studio) which inject inline `<style>` tags.
- `frame-src` entries cover media-player `<iframe>` embeds (YouTube / Vimeo).
- Remove any `'unsafe-eval'` directive from existing headers — it is not needed.

### 6. Focus management

Interactive toolbar buttons must be reachable by keyboard (`Tab` / `Shift+Tab`).
Canvas-based surfaces (react-konva, @xyflow/react) should expose a skip link:

```tsx
<a className="nvoos-skip-link" href="#nvoos-main-content">Skip to main content</a>
<div id="nvoos-main-content" tabIndex={ -1 }>{ /* canvas */ }</div>
```

---

## 15. Phase 6 i18n guide

All toolkit-SPA addons must pass gate #7 from §12: **"exposes a string table or
supports `wp.i18n` interop"**. The pattern below is the reference implementation.

### How it works

1. **React components** import from `@wordpress/i18n`:

   ```tsx
   import { __, sprintf } from '@wordpress/i18n';

   // Simple string
   <button>{ __( 'Save', 'nvoos-my-addon' ) }</button>

   // With a variable
   <p>{ sprintf( __( 'Page %1$d of %2$d', 'nvoos-my-addon' ), page, total ) }</p>

   // Conditional aria-label
   aria-label={ playing ? __( 'Pause', 'nvoos-my-addon' ) : __( 'Play', 'nvoos-my-addon' ) }
   ```

2. **esbuild** maps `@wordpress/i18n` to `window.wp.i18n` at bundle time via
   the `wpI18nPlugin` in `esbuild.config.cjs`. The library is **not** bundled —
   it reads `window.wp.i18n` at runtime:

   ```js
   const wpI18nPlugin = {
     name: 'wp-i18n-external',
     setup( build ) {
       build.onResolve( { filter: /^@wordpress\/i18n$/ }, ( args ) => ( {
         path: args.path, namespace: 'wp-i18n-ns',
       } ) );
       build.onLoad( { filter: /.*/, namespace: 'wp-i18n-ns' }, () => ( {
         contents: `module.exports = window.wp.i18n;`, loader: 'js',
       } ) );
     },
   };
   ```

3. **PHP shortcode** declares `'wp-i18n'` as a script dependency and calls
   `wp_set_script_translations()`:

   ```php
   wp_register_script(
       'nvoos-my-addon',
       NVOOS_MY_ADDON_URL . 'assets/dist/my-addon.js',
       array( 'wp-i18n' ),
       NVOOS_MY_ADDON_VERSION,
       true
   );
   wp_set_script_translations(
       'nvoos-my-addon',
       'nvoos-my-addon',
       NVOOS_MY_ADDON_PATH . 'languages'
   );
   ```

4. **Translation files** live in `languages/`. Generate the `.pot` template with:

   ```bash
   # Requires WP-CLI
   wp i18n make-pot . languages/nvoos-my-addon.pot \
     --domain=nvoos-my-addon \
     --include="src/*.tsx,src/**/*.tsx,includes/**/*.php"

   # Convert .po → .mo (gettext)
   msgfmt languages/nvoos-my-addon-fr_FR.po -o languages/nvoos-my-addon-fr_FR.mo

   # Convert .po → WordPress JS JSON (for wp_set_script_translations)
   wp i18n make-json languages/ --no-purge
   ```

   The `languages/` directory ships empty (`.gitkeep`). Translators provide `.po`
   files which maintainers compile to `.mo` + `.json` before release.

### Verify the bundle is clean

After `npm run build`, check the production bundle does **not** contain
`@wordpress/i18n` source:

```bash
grep -c "@wordpress/i18n\|setLocaleData" assets/dist/my-addon.js
# Should output: 0
grep -c "window.wp.i18n" assets/dist/my-addon.js
# Should output: 1
```

---

## 16. Phase 8 bundle-size guardrail

Every toolkit-SPA addon must keep its **gzipped JS bundle** within its tier
threshold. The CI workflow
[`.github/workflows/spa-bundle-size.yml`](../../.github/workflows/spa-bundle-size.yml)
enforces this automatically on every PR that touches `src/`,
`esbuild.config.cjs`, or `package.json`.

### Thresholds

| Addon | Tier | Limit (gzip) | Current (approx.) |
|-------|------|-------------|-------------------|
| `toolkit-shell` | A — data shell | **200 KB** | ~61 KB |
| `canvas-toolkit` | B — canvas | **1600 KB** | ~1495 KB |
| `document-editor` | C — document | **500 KB** | ~485 KB |
| `media-studio` | D — specialist | **900 KB** | ~806 KB |
| `chat-spa` | E — chat surface | **350 KB** | ~73 KB |

Tier A/C shells must stay under 200 KB gzipped (per §12 gate 2 and §13 Risks).
`canvas-toolkit` ships four heavy specialist libraries (tldraw, bpmn-js, mermaid,
@xyflow/react) and is granted a higher 1600 KB limit.
`media-studio` ships three heavy peer deps (react-konva, wavesurfer.js,
react-player) and is granted a higher 900 KB limit as a specialist shell.

### How the check works

The workflow builds each addon (`npm ci && npm run build`) and then:

```bash
GZ_BYTES=$(gzip -c "assets/dist/<addon>.js" | wc -c)
# Fail if GZ_BYTES > LIMIT_BYTES
```

It prints headroom remaining so contributors know how close they are to the
limit before a failure.

### Keeping bundles lean

- Keep heavy deps (react-konva, wavesurfer.js, Tiptap) in Tier D only.
- `@wordpress/i18n` must stay external (see §15) — never let it bundle.
- Run `npm run build && gzip -c assets/dist/<addon>.js | wc -c` locally
  before opening a PR that adds new dependencies.
- If a Tier A/B/C addon is approaching the 200 KB limit, extract the new
  feature into a separate Tier D addon instead.

---

## 17. Phase 9 — Scaffolder CI auto-patch

The scaffolder (`bin/scaffold-toolkit-spa.sh`) automatically registers every
new addon in both CI workflow matrices. No manual YAML editing is needed.

### What gets patched

| Workflow | Change |
|----------|--------|
| `spa-a11y.yml` | Adds `addons/<slug>/src/**` and `addons/<slug>/eslint.config.js` to both `push` and `pull_request` path filters; appends `- <slug>` to the `matrix.addon` list. |
| `spa-bundle-size.yml` | Adds `src/**`, `esbuild.config.cjs`, and `package.json` path entries to both triggers; appends `- addon: <slug>\n  limit_kb: 200` to the `matrix.include` list. |

### Bundle-size limit for new addons

New addons scaffolded after Phase 9 default to **200 KB gzip**. If your
addon ships heavy peer dependencies (a Tier D specialist shell), update
`spa-bundle-size.yml` manually after scaffolding and explain the higher limit
in a comment.

### Idempotent

If the scaffolder detects that `addons/<slug>/src/**` is already present in
the workflow file it skips that file, preventing duplicate entries if the
patch step is accidentally re-run.

---

## 18. Scaffolding a new toolkit-SPA addon

Run:

```bash
./bin/scaffold-toolkit-spa.sh <slug> "<Human-Readable Title>"
```

This copies the blueprint into `addons/<slug>/` with all placeholders
substituted (slug, title, constants, REST namespace, JS global). After
scaffolding:

1. `cd addons/<slug> && npm install && npm run build`
2. Edit `src/App.tsx` and `includes/rest/class-nvoos-<slug>-rest.php` to add
   addon-specific surfaces.
3. Add a per-addon `README.md` "Credits" section + `THIRD_PARTY_NOTICES.md`.
4. Add a slim `.github/agents/<slug>-maintainer.agent.md` (see
   [`examples/agents/toolkit-spa-maintainer.agent.md`](../../examples/agents/toolkit-spa-maintainer.agent.md)).
5. Update `AGENTS.md` inventory + `CREDITS.md`.
6. Bump version once before opening the PR.

---

## 19. References

- [`addons/docs-hub/`](../../addons/docs-hub/) — canonical reference
  implementation
- [`addons/saas-controller/`](../../addons/saas-controller/) — second
  reference, demonstrates committed pre-built artifacts + missing-bundle notice
- [`AGENTS.md`](../../AGENTS.md) §2 — layering rule for `.github/agents/`
- [`CLAUDE.md`](../../CLAUDE.md) — naming conventions, security, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) —
  required reading for every PR that touches the surface
- [`CREDITS.md`](../../CREDITS.md) — root attribution index

---

## 20. Migrating from legacy `chat.js` to `chat-spa`

The `chat-spa` addon (Tier E, v0.6.0) is a drop-in React replacement for
`assets/js/chat.js`. Until fully migrated, both can coexist.

### Feature parity matrix

| Feature | `chat.js` | `chat-spa` |
|---------|-----------|-----------|
| Text chat (streaming) | ✅ | ✅ |
| Tool-call display | ✅ | ✅ |
| Memory events (annotation pills) | ✅ | ✅ |
| Transcript sidebar | ✅ | ✅ |
| Memory drawer (Memories / Scope / Audit) | — | ✅ v0.4.0 |
| HITL approval bar | — | ✅ v0.5.0 |
| File attachments (base64 data-URL) | ✅ | ✅ v0.6.0 |
| Regenerate last response | — | ✅ v0.6.0 |
| Edit + re-submit user message | — | ✅ v0.6.0 |
| Voice recording | ✅ | planned |
| Bubble mode | ✅ | planned |
| Elementor widget | ✅ | planned |

### Migration steps

1. **Install the addon** — activate `addons/chat-spa/nvoos-chat-spa.php`.
2. **Replace shortcodes** — swap `[mcp_ai_chat assistant_id="X"]` with
   `[nvoos_chat_spa assistant_id="X"]` on your pages/posts.
3. **Opt-out of legacy mode** — add to `wp-config.php`:
   ```php
   define( 'WP_MCP_AI_LEGACY_CHAT_JS', false );
   ```
   This prevents `[mcp_ai_chat]` from being registered and stops
   `chat-bundle.min.js` from being enqueued, reducing page weight.
4. **Test** — verify streaming, tool cards, memory drawer, HITL bar, and
   file attachments work on your site before removing the legacy shortcodes.

> **Note:** Setting `WP_MCP_AI_LEGACY_CHAT_JS = false` without first replacing
> all `[mcp_ai_chat]` shortcodes will result in those shortcodes rendering as
> plain text. Always migrate shortcodes first.

---

## 21. External Template Ingestion (v3.0)

The SPA blueprint now supports importing external React projects — Envato
templates, admin dashboards, CRM apps, landing pages — and adapting them into
blueprint-compliant addons. This is orchestrated by:

```bash
node bin/import-react-template.mjs [OPTIONS]

# Or via npm:
npm run import-template -- --source /path/to/template --slug my-addon --title "My Addon" --auto-fix
npm run import-template:analyze -- --source /path/to/template
```

The pipeline runs in five phases:

| Phase | What | Tool |
|-------|------|------|
| 1. Resolve | Extract ZIP if needed, locate project root | `--source-zip` flag |
| 2. Analyze | Static analysis → gap report | `bin/lib/template-analyzer.mjs` |
| 3. Vet | 13-gate checklist (10 blueprint + 3 ingestion) | `bin/lib/adapters/vetting-runner.mjs` |
| 4. Plan | Determine adapter execution order | `bin/lib/adapter-catalog.mjs` |
| 5. Integrate | Scaffold addon, merge source, apply adapters, generate manifest, build | `bin/lib/blueprint-integration.mjs` |

The ingestion pipeline **never** edits the base plugin, Pro addon PHP, or
existing addons. It produces a new standalone addon under `addons/<slug>/`
that follows every blueprint convention.

### What the pipeline handles automatically

- **Mount adapter**: Wraps the template's `ReactDOM.createRoot` to mount inside `<div class="nvoos-{slug}-root">` instead of `#root`
- **Auth adapter**: Replaces JWT/bearer tokens with WordPress `X-WP-Nonce` header injection
- **Build adapter**: Generates `esbuild.config.cjs` (IIFE bundle, wp.i18n external, CSS extraction)
- **Manifest generator**: Maps discovered API calls + TypeScript interfaces to JSON manifest resources
- **Blueprint scaffold**: Creates the addon skeleton (`nvoos-<slug>.php`, `includes/`, etc.)

### What still requires human review

All 8 adapters now run automatically in the pipeline. The following items are
flagged in the `todos` output for human verification:

- **API endpoint mapping**: Ambiguous REST namespace mappings (e.g., TanStack Query hooks) are flagged for review; verify endpoint correctness.
- **i18n**: Strings that look like both code identifiers and UI text (camelCase, URLs) are flagged as ambiguous — confirm each is meant for translation.
- **CSS scoping**: Complex CSS-in-JS patterns (styled-components `createGlobalStyle`, Emotion `Global`) and `:root` custom property scoping are flagged for manual verification.
- **A11y audit**: Run axe-core post-integration; the adapter doesn't fix a11y violations.

---

## 22. Template Analysis Schema

The template analysis report (`template-analysis.json`) is the contract between
the analyzer and the adapter pipeline. See §21 for the full pipeline.

Schema (informal):

```jsonc
{
  "template_name": "material-dashboard-react",
  "envato_id": null,
  "analysis_version": "1.0",
  "analyzed_at": "2026-05-25T00:00:00.000Z",
  "tech_stack": {
    "framework": "react",
    "version_range": "18.2.0",
    "router": "react-router-dom",
    "state": "redux",
    "css": "tailwind",
    "ui_library": "@mui/material",
    "bundler": "webpack",
    "i18n_lib": "none-detected"
  },
  "entry_points": [
    { "file": "src/index.tsx", "isDefault": true }
  ],
  "api_calls": [
    {
      "file": "src/services/userService.ts",
      "method": "GET",
      "endpoint": "/api/users",
      "source": "axios"
    }
  ],
  "components": {
    "pages": 12,
    "components": 45,
    "layouts": 3
  },
  "typescript_interfaces": [
    {
      "name": "User",
      "file": "src/types/user.ts",
      "fields": [
        { "name": "id", "tsType": "number", "inferred": "integer", "required": true },
        { "name": "email", "tsType": "string", "inferred": "email", "required": true }
      ]
    }
  ],
  "i18n": {
    "hasI18n": false,
    "hardcodedCount": 142,
    "samples": ["Dashboard", "Users", "Settings"]
  },
  "gaps": [
    {
      "severity": "critical",
      "category": "auth",
      "description": "Template uses JWT auth; needs WP nonce adapter",
      "adapter_needed": "auth-adapter"
    }
  ],
  "vetting_results": { /* per-gate pass/warn/fail */ },
  "estimated_effort": "medium",
  "recommended_tier": "A",
  "recommended_addon_type": "manifest-driven"
}
```

---

## 23. Adapter Catalog

The import pipeline ships with 8 adapters, indexed in `bin/lib/adapter-catalog.mjs`.
Each adapter addresses one gap category and is composable — you can run only the
ones your template needs.

| Adapter ID | Category | Automated | Description |
|-----------|----------|-----------|-------------|
| `mount-adapter` | mount | ✅ Auto | Wraps entry point to mount inside `.nvoos-{slug}-root` |
| `auth-adapter` | auth | ✅ Auto | Replaces JWT/session tokens with `X-WP-Nonce` header injection |
| `build-adapter` | build | ✅ Auto | Generates `esbuild.config.cjs` from webpack/vite/cra originals |
| `api-adapter` | data_plane | ✅ Auto | Rewrites API service files to target WP REST; generates typed client + REST types; flags ambiguous mappings |
| `i18n-adapter` | i18n | ✅ Auto | Auto-wraps hardcoded UI strings with `__()`; injects `@wordpress/i18n` imports; generates full POT; flags ambiguous strings |
| `css-scope-adapter` | css | ✅ Auto | Framework-aware CSS scoping: Tailwind prefix, MUI ThemeProvider, styled-components, global selector rewriting |
| `vetting-runner` | quality | ✅ Auto | 13-gate checklist; blocks import on critical failures |
| `bundle-optimizer` | build | ✅ Auto | Analyzes esbuild metafile/source tree; detects lazy-load candidates; produces optimization report |

Adapter execution order is determined by `planAdapters()` in the adapter catalog:
`mount → auth → build → api → i18n → css-scope → bundle-optimizer`

---

## 24. Template Compatibility Matrix

A curated list of known-compatible Envato/third-party React templates lives at
[`docs/addons/template-compatibility-matrix.md`](template-compatibility-matrix.md).

Key selection criteria when evaluating a template:

- License: MIT, Apache-2.0, or BSD (blueprint §12 gate 1)
- React 18/19, standard router (`react-router-dom` preferred)
- No premium-only dependencies (AG-Grid Enterprise, Syncfusion, Kendo UI)
- Source code included (not compiled JS only)
- No remote CDN script loads (or self-host them)
- Estimated bundle < 400 KB for Tier F (imported) shells

---

## 25. Ingestion Vetting Addendum

In addition to the standard 10-gate checklist (§12), imported templates must
also clear three ingestion-specific gates:

| Gate | Name | Blocker? |
|------|------|----------|
| 11 | **No remote CDN scripts** — all JS must be self-hosted under `assets/dist/` | ✅ Critical |
| 12 | **CSS scoped to addon root** — no global `body`/`html`/`*` resets outside `.nvoos-{slug}-root` | ⚠️ Warning |
| 13 | **Build script uses esbuild** — `package.json` scripts must call `esbuild.config.cjs` | ⚠️ Warning |

If gate 11 fails, the import is **blocked** — you must remove all remote script
loads before proceeding. Gates 12 and 13 produce warnings but don't block;
they're resolved by the CSS-scope and build adapters respectively.

---

## 26. Manifest Auto-Generation Rules

The manifest generator (`bin/lib/manifest-generator.mjs`) produces a valid
§11 manifest from the analysis report using three strategies:

**Strategy 1 — API call grouping**: API endpoints discovered by the analyzer
are grouped by resource name (e.g. `/api/users` → resource `users`). Each group
becomes a resource in the manifest.

**Strategy 2 — TypeScript interface mapping**: For each resource, the generator
searches for a matching TypeScript interface (exact name, singularized, or
partial match). Field types are inferred:

| TS Type | Manifest Field Type |
|---------|-------------------|
| `string` | `string` |
| `number` | `number` |
| `boolean` | `boolean` |
| `Date` / `DateTime` | `date` / `datetime` |
| String literal union (`'a' \| 'b'`) | `enum` with options |
| `Array<T>` / `T[]` | `string` (simplified) |
| Fields named `id`, `created_at`, `updated_at` | Auto-marked `readonly: true` |

**Strategy 3 — Fallback**: If no API calls or interfaces are found, a single
generic resource is created with `{ id, title, status, created_at, updated_at }`
fields.

Views are generated automatically: one `table` view per resource, plus a
`kanban` view for any resource with enum fields (grouped by the first enum field).

The generator emits to `addons/pro/config/spa-manifests/{toolkit}.json` and
follows all validation rules from the manifest registry (§11).

---

**Blueprint version:** 3.0  
**Last reviewed:** May 2026  
**Next review:** November 2026
