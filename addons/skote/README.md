# NV oOS Skote Addon

> **Status:** Phase 1 — skeleton. Provides the WordPress host plugin, REST
> surface, asset pipeline, admin page, shortcode, and Pro/JetEngine/WooCommerce
> bridge stubs. The Skote React template itself is NOT bundled (commercial
> license — see Credits below) and must be imported via `bin/import-skote.sh`.

NV oOS Skote embeds the [Skote React](https://themesbrand.com/skote-react/)
admin template inside WordPress and points it at the WordPress REST API
(`/wp-json/wp/v2`, `/wp-json/wc/v3`, `/wp-json/jet-cct`) plus a custom
`nvoos-skote/v1` namespace, replacing Skote's bundled fakebackend
(`axios-mock-adapter` / `json-server`).

It is a sibling addon to `mcp-ai-wpoos` (Base) and `mcp-ai-wpoos-pro` (Pro)
and follows the same packaging pattern as `nvoos-canvas`, `nvoos-graphify`,
and `nvoos-fantasy-football`.

## What you get

| Surface | Mount | When |
|--------|-------|------|
| **Admin page** | `wp-admin → NV oOS Skote` | Power users / operators (default `manage_options`) |
| **Shortcode** | `[nvoos_skote app="dashboard"]` | Front-end embed for selected apps with tighter capability gating |
| **REST namespace** | `/wp-json/nvoos-skote/v1` | Settings / Me / Apps / bridge proxies / Pro workflows + tools |

The `app` attribute deep-links into a Skote route via the React HashRouter
on first paint.

## REST surface (Phase 1)

| Route | Method | Capability |
|-------|--------|-----------|
| `/settings` | `GET`, `POST` | `read` (POST `site` requires `manage_options`) |
| `/me` | `GET` | `read` |
| `/apps` | `GET` | `read` |
| `/bridge/wp/users` | `GET` | `list_users` |
| `/bridge/wc/{resource}` | `GET` | `manage_woocommerce` (404 when WC inactive) |
| `/bridge/jet/cct/{slug}` | `GET` | `edit_posts` (404 when JetEngine inactive) |
| `/bridge/cpt/{post_type}` | `GET` | post-type `edit_posts`, plus the post type must be in the `nvoos_skote_allowed_cpts` option |
| `/workflows` | `GET` | `manage_options` (501 when Pro inactive) |
| `/workflows/{id}/dispatch` | `POST` | `manage_options` (501 when Pro inactive) |
| `/tools` | `GET` | `manage_options` (501 when Pro inactive) |
| `/tools/{slug}/execute` | `POST` | `manage_options` — Phase 5 wires HITL |

Every response uses a single envelope: `{ success, data, errors, meta }` so
the React Query hooks share a uniform shape.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- NV oOS base plugin (`mcp-ai-wpoos`) active
- Optional: NV oOS Pro for the workflows + tool registry endpoints
- Optional: WooCommerce (read paths)
- Optional: JetEngine (CCT bridge — Phase 4)
- For development only: Node.js ≥ 18.17.0 to build the React bundle
- For development only: a licensed Skote React checkout (Themesbrand)

## Build from source (developers / site owners)

The Skote React template is a commercial product. You must obtain a license
from [Themesbrand](https://themesbrand.com/skote-react/) (or via Envato /
ThemeForest) and download the React (Vite) variant of the source. Then:

```bash
cd addons/skote
npm install
npm run import:skote -- /path/to/skote-react   # copies Skote into src/
npm run build                                  # emits dist/index.{js,css,asset.php}
```

`bin/import-skote.sh` preserves the addon's own integration files
(`src/services/wpApi.ts`, `src/hooks/*`, `src/index.tsx`, `src/App.tsx`) and
removes Skote's `fakeBackend` so a stray import cannot silently re-enable
mock data.

The asset pipeline mirrors the `@wordpress/scripts` convention: Vite produces
`dist/index.js` and `dist/index.css`, then `bin/generate-asset-php.js` writes
a `dist/index.asset.php` with `[ 'dependencies' => [...], 'version' => '...' ]`
that `class-nvoos-skote-assets.php` consumes.

## Replacing Skote's fakebackend

`src/services/wpApi.ts` (preserved by `import-skote.sh`) exposes a configured
Axios client bound to `window.nvoosSkote.restUrl` with `X-WP-Nonce` baked
into every request. Replace any Skote saga / hook that imported
`fakebackend_helper` with hooks under `src/hooks/`, e.g.:

```ts
const { data } = useQuery(['users'], () => wpApi.get('bridge/wp/users'));
```

## Security model

- Cookie + `X-WP-Nonce` for the in-admin SPA (standard WP REST auth).
- Capability checks AND nonce checks on every state-changing route.
- Per-user prefs stored in user meta `nvoos_skote_prefs`; per-site UI defaults
  in option `nvoos_skote_settings` (admin-only writes).
- The generic CPT bridge is gated by an explicit allowlist option
  `nvoos_skote_allowed_cpts` — never expose every CPT to the SPA.
- JetEngine CCT queries use the canonical table prefix `jet_cct_` (with
  underscores) and any future CPT/CCT registration runs at `init` priority
  11+ to avoid racing JetEngine's CCT cache hydration.
- Tool execution from the SPA flows through the HITL approval queue
  (`mcp_ai_approval` CPT) when the tool is flagged as state-changing — wired
  in Phase 5.

## Filters & hooks

| Hook | Purpose |
|------|---------|
| `nvoos_skote_admin_capability` (filter) | Capability that gates the admin page (default `manage_options`) |
| `nvoos_skote_shortcode_capability` (filter) | Capability required to render the front-end shortcode (default `read`) |
| `nvoos_skote_localized_payload` (filter) | Adjust `window.nvoosSkote` (Pro / Woo / JetEngine bridges hook here) |
| `nvoos_skote_allowed_cpts` (filter) | Override the post-type allowlist served to `/bridge/cpt` |
| `nvoos_skote_apps_catalogue` (filter) | Add or remove apps from `/apps` |
| `nvoos_skote_register_post_types` (action) | Add custom CPTs at `init` priority 11 (safe JetEngine window) |

## License

GPLv3 or later — see the LICENSE file in the repository root.

## Credits

This addon does **not** bundle the Skote React template. Skote is a
commercial product owned by [Themesbrand](https://themesbrand.com/) and
distributed through [Envato / ThemeForest](https://themeforest.net/). Site
owners must hold their own Regular or Extended Themesbrand license to import
Skote source via `bin/import-skote.sh`.

Runtime / build-time JavaScript dependencies (declared in `package.json`):

- [React](https://react.dev/) — © Meta Platforms, Inc. — MIT
- [TanStack Query](https://tanstack.com/query) — © Tanner Linsley — MIT
- [Axios](https://axios-http.com/) — © Matt Zabriskie & contributors — MIT
- [Vite](https://vitejs.dev/) — © Vite contributors — MIT

For the full repo-wide attribution index, see [`CREDITS.md`](../../CREDITS.md)
at the repository root.
