# Skote React ↔ WordPress Integration

> **Status:** Phase 1 — skeleton landed. Phases 2–6 sequence the Skote
> import, app surfaces, integrations, Pro wiring, and hardening.

The `nvoos-skote` addon embeds the [Skote React](https://themesbrand.com/skote-react/)
admin template inside WordPress and routes its data layer at the WordPress
REST API plus a custom `nvoos-skote/v1` namespace, replacing Skote's bundled
fakebackend (`axios-mock-adapter` / `json-server`).

It is a sibling addon to the base plugin and Pro and follows the same
packaging pattern as `nvoos-canvas`, `nvoos-graphify`, and
`nvoos-fantasy-football`.

## Why a separate addon?

Skote is a commercial template (Themesbrand, sold on Envato/ThemeForest).
Distributing Skote source in this repository would violate the Themesbrand
license. The addon therefore contains only:

- The WordPress host plugin and REST surface.
- The integration layer that points Skote's data hooks at WP REST.
- Build glue and a `bin/import-skote.sh` helper that copies a developer-
  supplied Skote checkout into `src/` at build time.

Site builders bring their own Skote license; the addon never bundles it.

## Architecture

```
Skote React UI
   ↓ (HashRouter mounted under wp-admin / shortcode / standalone page)
Compiled build (Vite → dist/index.{js,css,asset.php})
   ↓
addons/skote/ Pro add-on enqueue (wp_register_script + window.nvoosSkote)
   ↓
WordPress REST API (/wp-json/wp/v2, /wp/v3, /jet-cct)
+ Custom Pro REST endpoints (nvoos-skote/v1)
   ↓
WP users · WooCommerce · JetEngine · CPTs · Settings · Pro Workflows · Tools
```

## Directory layout

See [`addons/skote/README.md`](../../addons/skote/README.md#directory-layout)
for the full tree. The key points:

- `addons/skote/nvoos-skote.php` — WP plugin header + bootstrap.
- `addons/skote/includes/class-nvoos-skote*.php` — host classes.
- `addons/skote/includes/rest/` — REST controllers under `nvoos-skote/v1`.
- `addons/skote/includes/integrations/` — Pro / WooCommerce / JetEngine
  bridges.
- `addons/skote/src/` — developer-imported Skote tree (NOT committed,
  except for the four addon-owned files preserved by `bin/import-skote.sh`:
  `index.tsx`, `App.tsx`, `services/wpApi.ts`, `hooks/useApps.ts`).
- `addons/skote/dist/` — built React bundle, included in the release ZIP.

## REST surface (Phase 1)

Every response uses a uniform envelope (`{ success, data, errors, meta }`)
so the React Query hooks share a single response shape.

| Route | Purpose |
|-------|---------|
| `GET /settings`, `POST /settings` | Per-user prefs + per-site UI defaults |
| `GET /me` | Current user identity / role / caps |
| `GET /apps` | Enumerate "apps" filtered by integrations + caps |
| `GET /bridge/wp/users` | Phase 3 — proxy of `wp/v2/users` |
| `GET /bridge/wc/{resource}` | Phase 4 — WooCommerce read paths |
| `GET /bridge/jet/cct/{slug}` | Phase 4 — JetEngine CCT read paths |
| `GET /bridge/cpt/{post_type}` | Generic CPT bridge, gated by an allowlist option |
| `GET /workflows`, `POST /workflows/{id}/dispatch` | Pro workflow builder adapter |
| `GET /tools`, `POST /tools/{slug}/execute` | Pro tool registry — Phase 5 wires HITL |

## Security posture

- Cookie + `X-WP-Nonce` for the in-admin SPA (standard WP REST flow).
- Capability check + nonce check on every state-changing route.
- Admin page renders ONLY escaped chrome plus the empty React root div.
- Per-user prefs stored in user meta (`nvoos_skote_prefs`); site defaults in
  option `nvoos_skote_settings` (admin writes only).
- Generic CPT bridge gated by an explicit allowlist option
  (`nvoos_skote_allowed_cpts`) — never echo every CPT to the SPA.
- JetEngine CCT registrations attach to `init` priority 11+ to avoid racing
  JetEngine's CCT cache hydration (priorities 1–10).
- Tool execution from the SPA flows through the HITL approval queue
  (`mcp_ai_approval` CPT) when state-changing — wired in Phase 5.

## Open questions

The plan in the original PR description lists six open questions (Skote
variant, bundling vs developer-import, default delivery surface,
"workflow builder - TMA" vs Pro Workflow Builder, auth model,
standalone vs Pro-required). Phase 1 ships defaults that are easy to
reverse:

1. **Skote variant:** React (Vite). The build pipeline is Vite-only.
2. **Bundling:** developer-import via `bin/import-skote.sh`.
3. **Delivery surfaces:** admin page + shortcode shipped; standalone page
   template can be added in Phase 3 with no API impact.
4. **TMA reference:** plan bridges to the existing NV oOS Pro Workflow
   Builder (`option wp_mcp_ai_pro_workflows`,
   `WP_MCP_AI_Workflow_Dispatcher::dispatch()`).
5. **Auth:** cookie + nonce for admin SPA. Headless front-end auth (assistant
   credentials / Auth0) is opt-in and reuses the base plugin's auth filters.
6. **Standalone vs Pro-required:** standalone-capable; Pro features are
   feature-flagged behind `function_exists( 'wp_mcp_ai_pro_init' )`.

## Phased rollout

1. **Phase 1 — Skeleton** ✅ this addon (host plugin, REST scaffolding,
   tests, docs, CREDITS update, build-zip wiring).
2. **Phase 2 — Skote bootstrap:** import script flow, replace fakebackend
   with `wpApi.ts`, hydrate one app (Dashboard) end-to-end.
3. **Phase 3 — Apps:** Users, Tasks/Kanban CPT, Calendar CPT, Settings.
4. **Phase 4 — Integrations:** WooCommerce read paths, JetEngine CCT bridge.
5. **Phase 5 — Pro:** Tool Registry execution, Workflow dispatcher wired,
   HITL approvals inbox, observability cards.
6. **Phase 6 — Hardening:** a11y pass, full PHPCS, CodeQL, performance
   budget, release ZIP via `bin/build-addon-zips.sh`.
