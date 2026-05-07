# Toolkit SPA Blueprint

> **Status:** Foundation (Phase 0). Last reviewed: **May 2026** · Version: **1.0**
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
| `calendar-booking` | (todo) | `@fullcalendar/react` (MIT) |
| `financial-planner` | (todo) | refine + `react-financial-charts` + `recharts` (all MIT) |
| `analytics` | (todo) | refine + `recharts` / `visx` (MIT) |
| `regulatory-registration` | (todo) | refine |
| `law-firm` | (todo) | refine |
| `cre-debt` | (todo) | refine |
| `multilingual` | (todo) | refine + Monaco (`@monaco-editor/react`, MIT) |

### Tier B — Canvas / whiteboard shells
*Separate addon `addons/canvas-toolkit/` (lazy-loaded by mode).*

| Toolkit | Recommended SPA pieces |
|---------|------------------------|
| `architectural-design` | Excalidraw (`@excalidraw/excalidraw`, MIT) **or** tldraw v3 (MIT); bpmn-js (MIT) |
| `architect-agent` | tldraw v3 + Mermaid live preview |
| `ai-tool-builder` | `@xyflow/react` (MIT) for visual node-graph |

### Tier C — Document / rich-text shells
*Separate addon `addons/document-editor/` (Tiptap).*

| Toolkit | Recommended SPA pieces |
|---------|------------------------|
| `document-generation` | Tiptap (MIT, ProseMirror-based) |
| `site-creator` | Tiptap + GrapesJS (`@grapesjs/react`, BSD-3) |

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
| `ecommerce` | manifest in `toolkit-shell` | refine + product cards |
| `social-media` | manifest in `toolkit-shell` | refine + react-big-calendar (MIT) overlay |
| `extended-cognition` | (deferred) | Custom (timeline + visx) |

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

## 15. Scaffolding a new toolkit-SPA addon

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

## 16. References

- [`addons/docs-hub/`](../../addons/docs-hub/) — canonical reference
  implementation
- [`addons/saas-controller/`](../../addons/saas-controller/) — second
  reference, demonstrates committed pre-built artifacts + missing-bundle notice
- [`AGENTS.md`](../../AGENTS.md) §2 — layering rule for `.github/agents/`
- [`CLAUDE.md`](../../CLAUDE.md) — naming conventions, security, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) —
  required reading for every PR that touches the surface
- [`CREDITS.md`](../../CREDITS.md) — root attribution index
