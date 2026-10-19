---
name: mcp-ai-schedule-anything-ops
description: Operational guide for the Schedule Anything SaaS surface — the 3-plugin WordPress stack (base + Pro + schedule-anything-platform) and its tenant SPA, the mcp-ai-pro/v1 presets/toolkits REST contract (Proposal 081), the WP 6.9 nonce cookie-auth pitfall and its fix pattern, the sa-e2e Docker harness quirks, the SPA↔REST contract-alignment rules, and the Cloudways Velocity deploy + mirror-sync loop. Use when working on the Schedule Anything SPA or platform plugin, debugging its REST/auth surface, rebuilding its E2E stack, deploying or syncing the Velocity app, or extending presets/toolkits.
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.2.8"
  plugin-version-tested: "1.2.8"
  last-updated: "2026-10-18"
---

# Schedule Anything SaaS — Operational Guide

Canonized from the 2026-10-18 end-to-end session: E2E smoke on the sa-e2e
Docker stack, the nonce-auth fix, Proposal
[081](../../../docs/project/proposals/081-schedule-anything-api-completion-proposal.md)
implementation, and the Velocity deploy. The authoritative design doc is
[`docs/project/plans/schedule-anything-saas-implementation.md`](../../../docs/project/plans/schedule-anything-saas-implementation.md).

## When to use this skill

- Working on `addons/schedule-anything-spa`, `addons/schedule-anything-platform`,
  or the Pro-side Schedule Anything classes
- Debugging the SPA auth handshake, presets, or toolkit toggles
- Rebuilding or driving the `sa-e2e` Docker stack
- Deploying/syncing the Cloudways Velocity app
- Extending the presets catalogue or toolkit entitlements

## 1. Architecture (how the SPA and plugin work together)

```
Browser → static SPA bundle (Vite/React 18, zero-dependency server or nginx)
  → GET /wp-json/nvoos-saas/v1/auth/nonce   (public; returns wp_rest nonce + user)
  → all data via /wp-json/mcp-ai-pro/v1/*   (schedules/presets/toolkits) + mcp-ai/v1
WP backend (Multisite): base + Pro + schedule-anything-platform
  → platform REST (/nvoos-saas/v1) is service-to-service (X-SaaS-API-Key) for
    the Cloud Worker (provisioning, offboarding, lookup, status) — not the SPA
```

- **The SPA never talks to Cloudways or the Cloud Worker** — only WordPress
  REST. Cloud Worker is billing/heartbeat, PHP-side.
- **Tenant = current blog** (multisite). No client-supplied tenant param.
  `TenantContext` derives the slug from the subdomain and is a no-op on the
  main domain (tier falls back to `unknown`).
- Pro ships as part of the base plugin repo tree, but the **repo-form Pro
  entry has NO plugin header by design** (the build script adds it) — install
  the **built distribution zip** (`build/mcp-ai-wpoos-pro-*.zip`) on a real
  site; a headerless copy is invisible to WP and a header-added copy
  redeclares functions (E2E-verified both failure modes).

## 2. REST surface contract (Proposal 081, `rest_schedule_anything` module)

Files: `addons/pro/includes/schedule-anything/` (adapter + presets registry +
folder README), `addons/pro/includes/rest/class-wp-mcp-ai-pro-sa-rest-controller.php`,
registered in `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php`.

| Route | Notes |
|---|---|
| `GET /mcp-ai-pro/v1/toolkits` | `{ ok, toolkits: [{slug, flag_key, enabled}] }` — via `SA_Toolkit_Manager` (platform plugin); empty list without the platform |
| `POST /mcp-ai-pro/v1/toolkits/{slug}/toggle` | `{ enabled }` body; unknown slug 404, tier-locked → 403 `sa_toolkit_not_entitled` (server-side entitlements — the SPA never decides) |
| `GET /mcp-ai-pro/v1/presets` | `{ ok, presets, total }`; search/category/toolkit/page/per_page |
| `GET /mcp-ai-pro/v1/presets/{id}` | 404 `sa_preset_not_found` |
| `POST /mcp-ai-pro/v1/presets/{id}/install` | idempotent: key `preset:{id}:h{sha256(overrides)}` in option `wp_mcp_ai_sa_installs`; replay returns the original schedule with `replayed: true` |

- Capability seam is **shared** with the schedule CRUD controller:
  `wp_mcp_ai_pro_schedule_rest_capability` filter (default
  `sa_manage_workspace` when the platform plugin is active, else
  `manage_options`). Reads use the same seam as writes — the SPA is a
  tenant-admin surface.
- Overrides are **whitelisted** (`override_whitelist()`) — unknown keys are
  dropped, never mass-assigned.
- Schedule records leaving REST go through
  `WP_MCP_AI_Pro_Schedule_Manager::redact_schedule_for_public()`, which strips
  `broadcast_config`, `assistant_config`, `result_delivery`, `notify_email`,
  `callback_secret`, `notify_channel_credentials`. Do NOT call
  `redact_envelope_for_public()` on a schedule record — that method is for
  result envelopes and takes two args; the schedule CRUD controller's old
  one-arg calls were a latent fatal that only fired once schedules existed.

## 3. The WP 6.9 nonce cookie-auth pitfall (learned the hard way)

**Symptom:** SPA redirect loop to wp-login; or authenticated REST calls fail
`403 rest_cookie_invalid_nonce` while wp-admin works fine.

**Root cause:** WP 6.9 `rest_cookie_check_errors()` calls
`wp_set_current_user( 0 )` on ANY REST request without a nonce. A nonce
endpoint called without a nonce therefore sees user 0 and mints a **user-0
nonce**, which fails `wp_verify_nonce()` once the cookie is attached
(cookie user ≠ nonce user).

**Fix pattern** (mirror `handle_session_nonce()` in
`includes/rest/class-wp-mcp-ai-rest-chat-controller.php`): before minting,
re-bind the cookie identity:

```php
$cookie_user_id = wp_validate_auth_cookie( '', 'logged_in' );
if ( $cookie_user_id && absint( $cookie_user_id ) !== get_current_user_id() ) {
    wp_set_current_user( absint( $cookie_user_id ) );
}
$nonce = wp_create_nonce( 'wp_rest' );
```

Plus `Cache-Control: no-cache, no-store, must-revalidate` — the payload is
session-bound. The platform's `get_nonce()` and the regression test
`tests/test-rest-chat-controller.php::test_session_nonce_rebinds_cookie_session_after_core_neutralisation`
are the references.

## 4. sa-e2e Docker harness (existing stack, reuse it)

- Containers: `sa-e2e-wp` (WP 6.9, port 8099, volume `sa-e2e-core`, network
  `sa-e2e-net`), `sa-e2e-db` (MariaDB, root/rootpass, DB `wordpress`),
  `sa-e2e-spa` (nginx, port 5173, serves the addon's `assets/dist` and proxies
  `/wp-json`, `/wp-admin`, `/wp-login.php`, `/wp-includes` to sa-e2e-wp with
  `Host: localhost:8099`). Base + platform plugins are network-activated
  bind mounts of the monorepo — **editing repo files updates the stack live**.
- wp-cli from the host (the WP image has no CLI):

```bash
MSYS_NO_PATHCONV=1 docker run --rm --network sa-e2e-net -v sa-e2e-core:/var/www/html \
  -e WORDPRESS_DB_HOST=sa-e2e-db -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
  wordpress:cli-php8.3 wp --path=/var/www/html --url=http://localhost:8099 <cmd> --allow-root
```

  The DB env vars are **required** — wp-config uses `getenv_docker()`, and
  without them the CLI container falls back to `example username`/host `mysql`
  ("Error establishing a database connection").
- Git Bash/Windows: use `MSYS_NO_PATHCONV=1` for any `docker exec -i` with
  container paths (`/var/www/html/...` gets mangled to `C:/Program Files/Git/...`
  otherwise).
- Login: admin/password (reset via `wp user update`), `SA_SAAS_API_KEY` is in
  wp-config. Test auth with a cookie jar:
  `curl -c/-b` through wp-login POST → nonce → `X-WP-Nonce` header.
- Clean up after every test run: DELETE created schedules via REST and delete
  the `wp_mcp_ai_sa_installs` option — the shared stack must stay clean.

## 5. SPA ↔ REST contract-alignment rules (Builder/presets)

- The manager's cron cadence is `$data['schedule']` (a **registered cron
  slug**), NOT `interval`. Presets/schedules must use `daily`, `weekly`,
  `twicedaily`, `hourly`, or the manager's custom `wp_mcp_ai_monthly` /
  `wp_mcp_ai_weekly` / `wp_mcp_ai_every_*` — `'monthly'` is NOT registered.
- Task schedules require `hook` (use `WP_MCP_AI_Pro_Schedule_Manager::DISPATCH_HOOK`
  = `wp_mcp_ai_pro_schedule_exec`); workflow schedules require non-empty
  `workflow_steps`; assistant_run/broadcast need their config blocks. The
  server's validation chain (missing params → steps → hook → schedule slug)
  is the contract — surface `WP_Error.message` verbatim in the SPA (sonner),
  never `alert`.
- The SPA payload builder is `addons/schedule-anything-spa/src/lib/schedulePayload.ts`
  (pure, unit-tested — extend it, don't inline payload logic in FlowCanvas).
- After SPA changes: `npm test` + `npm run build` in the addon, commit the
  rebuilt `assets/dist/` (deterministic byte-identical hashes), and rebuild
  the SPA zip (`bin/build-spa-zips.js`).

## 6. Cloudways Velocity deploy + mirror sync

- App: `examples/schedule-anything-velocity/` — prebuilt `dist/` +
  zero-dependency `scripts/serve.mjs` (ported from the standalone Pro SPA's
  proven entry). Mirror repo: **`nvdigitalsolutions/nvoos-schedule-anything-velocity`**
  (public, flat history, force-push only, never commit directly).
- **The app ships at the mirror repo ROOT** (unlike the Pro SPA mirror, which
  nests under `examples/` because it also vendors spa-v2 sources). Velocity
  root directory = repo root. **Velocity defaults its PM2 entry to
  `server.js` at the app root** — the app ships a one-line ESM shim
  (`server.js` → `import('./scripts/serve.mjs')`) so it deploys with zero
  entry-file configuration; without it the deploy fails with
  `[PM2][ERROR] Script not found: .../private_html/server.js` (rc=1). The
  real server keeps its `#!/usr/bin/env node` shebang (Velocity executes the
  entry through the shell). No build command (no-op `build`/`start` shims
  exist for auto-detection); env `NVOOS_TARGET_SITE=<wp-site>`.
- Cookie-auth proxy: `/wp-json` etc. proxied, login page re-scoped (relative
  form action + redirect_to), Set-Cookie Domain stripped → **no CORS config
  needed** in proxy mode.
- Sync: `.github/workflows/sync-nvoos-schedule-anything-velocity.yml` triggers
  on pushes touching `examples/schedule-anything-velocity/**` (main or
  alpha-working) + dispatch. It drops the dev-only `sync-dist.mjs` from the
  mirrored tree and smoke-tests serve.mjs before the force-push.
- **GitHub secret names max out at 40 chars** — the deploy-token secret is
  `NVOOS_SA_VELOCITY_REPO_TOKEN` (the first attempt,
  `NVOOS_SCHEDULE_ANYTHING_VELOCITY_REPO_TOKEN`, was 43 chars and rejected).
- After SPA changes merge: `npm run sync:dist` in the app folder + commit →
  that commit triggers the sync → mirror updates → Velocity redeploys.
  Merges touching only `addons/schedule-anything-spa/**` or workflows do NOT
  trigger the sync (path filter) — dispatch manually if needed.

## 7. Release bookkeeping

- Platform plugin zip: `bin/build-addon-zips.sh` step 13 (PHP-only).
- SPA zip: `bin/build-spa-zips.js` (`nvoos-schedule-anything-spa-v*.zip`,
  version from package.json) and CI `build-spa-addons.yml` (Pattern-B branch:
  version from package.json, verify index.html + hashed chunks, anchored
  `--exclude='/index.html'`).
- Bundle guardrail: `spa-bundle-size.yml` guards the main chunk
  (`assets/dist/assets/index-*.js`, 150 KB gzip limit for this SPA).
- Presets/toolkits REST routes are NOT MCP tools — no registry surfaces;
  CHANGELOG + proposal docs only.

## References

- Proposal 081 + plan doc (linked above)
- `mcp-ai-wpoos-spa-ui` skill — the SPA UI stack, Pattern B rules, vitest gotchas
- `mcp-ai-wpoos-test-suite` skill — PHPUnit conventions for the new suites
  (`addons/pro/tests/test-sa-toolkit-rest.php`, `test-schedule-presets-rest.php`)
- `examples/nvoos-pro-spa-vite/scripts/serve.mjs` — the proxy core this app ports
- `.github/workflows/{sync-nvoos-schedule-anything-velocity,build-spa-addons,spa-bundle-size}.yml`
