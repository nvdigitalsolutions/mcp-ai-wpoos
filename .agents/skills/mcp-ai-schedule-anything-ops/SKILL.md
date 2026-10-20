---
name: mcp-ai-schedule-anything-ops
description: Operational guide for the Schedule Anything SaaS surface — the 3-plugin WordPress stack (base + Pro + schedule-anything-platform) and its tenant SPA, the mcp-ai-pro/v1 presets/toolkits/sa-tools REST contract (Proposal 081), the WP 6.9 nonce cookie-auth pitfall and its fix pattern, the sa-e2e Docker harness quirks, the SPA↔REST contract-alignment rules, the Cloudways Velocity deploy + mirror-sync loop for both Velocity apps (schedule-anything + Complete Console on console.nvoos.cloud), and the Complete-bundle zip build/verify/staleness loop. Use when working on the Schedule Anything SPA or platform plugin, debugging its REST/auth surface, rebuilding its E2E stack, deploying or syncing a Velocity app, extending presets/toolkits, or diagnosing a 404 on a new SPA-consumed route (e.g. sa-tools).
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.2.8"
  plugin-version-tested: "1.2.8"
  last-updated: "2026-10-20"
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
WP backend (Multisite for the SaaS; single-site runs sole-tenant demo mode,
see the single-site section below): base + Pro + schedule-anything-platform
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
- **Do NOT add `Requires Plugins: mcp-ai-wpoos` to the platform plugin**
  (removed by #7132): core matches dependency entries against the installed
  plugin's **folder slug** with AND semantics, and the base ships under three
  slugs (`mcp-ai-wpoos` dev checkout, `nvdigital-open-operator-system-oos`
  wp.org base, `-oos-complete` bundle) — one static header blocks two of the
  three distributions. The runtime guard `sa_platform_base_is_active()`
  (→ `wp_mcp_ai_core_loaded()`) is slug-agnostic and already detects all
  three. `addons/page-agent/nvoos-page-agent.php` still carries the same
  header — apply the same treatment if it needs bundle support.

## 2. REST surface contract (Proposal 081, `rest_schedule_anything` module)

Files: `addons/pro/includes/schedule-anything/` (adapter + presets registry +
folder README), `addons/pro/includes/rest/class-wp-mcp-ai-pro-sa-rest-controller.php`,
registered in `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php`.

| Route | Notes |
|---|---|
| `GET /mcp-ai-pro/v1/toolkits` | `{ ok, toolkits: [{slug, flag_key, enabled}] }` — via `SA_Toolkit_Manager` (platform plugin); empty list without the platform |
| `POST /mcp-ai-pro/v1/toolkits/{slug}/toggle` | `{ enabled }` body; unknown slug 404, tier-locked → 403 `sa_toolkit_not_entitled` (server-side entitlements — the SPA never decides) |
| `GET /mcp-ai-pro/v1/sa-tools` | `{ ok, tools: [{slug, name, description, group, group_label}] }` — live-tool-registry light rows for the visual builder palette (#7147, since 1.2.8); args `search`/`group`/`page`/`per_page` (max 100); registers **unconditionally** (works without the platform plugin) |
| `GET /mcp-ai-pro/v1/presets` | `{ ok, presets, total }`; search/category/toolkit/page/per_page |
| `GET /mcp-ai-pro/v1/presets/{id}` | 404 `sa_preset_not_found` |
| `POST /mcp-ai-pro/v1/presets/{id}/install` | idempotent: key `preset:{id}:h{sha256(overrides)}` in option `wp_mcp_ai_sa_installs`; replay returns the original schedule with `replayed: true` |

Platform REST (`/nvoos-saas/v1`, platform 0.1.1+):

- `GET /tenants/lookup?slug=` resolves a bare slug against the network
  domain in both subdomain (`<slug>.<domain>`) and path
  (`<domain>/<slug>/`) forms — the old exact-domain-only match 404'd on
  both install types.
- Tier preset seeding (`SA_Toolkit_Manager::get_presets_for_tier()`) derives
  from the Proposal 081 catalogue — capped starter 5 / professional 10 /
  enterprise all — and provisioning installs through
  `WP_MCP_AI_SA_Schedule_Presets::install_catalogue_preset()` (same
  idempotency-key flow as the REST install). The legacy underscore ids
  resolved through the old `WP_MCP_AI_Pro_Schedule_Presets` registry and
  silently installed nothing.

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

### Built-zip verification in a throwaway stack (cross-worktree)

When the release zip itself is the deliverable (e.g. proving a Complete
bundle zip contains a route), install it in a **fresh** stack instead of
reusing sa-e2e — its bind mounts declare the same functions, so installing
a bundle zip alongside would collide:

- Containers: `mariadb:10.11` + `wordpress:6.9-php8.3-apache` with
  `-v <vol>:/var/www/html -e WORDPRESS_SKIP_INSTALL=1` (same image as
  sa-e2e-wp) + `wordpress:cli-php8.3` one-off runners on a dedicated
  network. Run `wp core install` via the cli runner.
- Get the zip into the shared volume with
  `docker cp <zip> <wp-container>:/var/www/html/` — host-path
  `-v F:/…:/build` mounts are unreliable on this box (install fails with a
  misleading "plugin could not be found").
- The cli image runs as uid 82 (www-data) while the wp image owns the
  volume as uid 33 → add `--user root` (plus `--allow-root`) to cli runs,
  or wp-cli cannot create `wp-content/upgrade`.
- `wp config set WP_MEMORY_LIMIT 512M --type=constant` first — the bundle's
  tool classes fatally exhaust the image's 128M default during plugin load.
- `wp rewrite structure "/%postname%/"` — with plain permalinks `/wp-json/*`
  serves HTML instead of routing (production has pretty permalinks).
- REST URLs 301 to their trailing-slash canonical form — probe with
  `curl -sL` (a missing route stays a hard 404; a 301→200 proves the route
  exists).
- `MSYS_NO_PATHCONV=1` on **every** `docker run`/`docker exec` (even plain
  `ls`: `/var/www/html` gets mangled to `C:/Program Files/Git/...`), and
  `docker exec` does not glob-expand — use `sh -c "ls /var/www/html/"`.
- Tear down after: `docker rm -f` both containers, then `docker network rm`
  + `docker volume rm` the throwaway resources.

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
- **Schedule delivery-time editing (Pro Schedule Manager, #7130)** — the edit
  modal now carries a Delivery Date & Time (`datetime-local`) field: prefill
  from the stored first-run `timestamp` only while it is still in the future;
  `saveEdit()` sends `timestamp` only for future values (past → alert, blank
  → keep the current cron event); `ajax_get_schedules` exposes the stored
  `timestamp` for the prefill.
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
  root directory = repo root. **Velocity PM2 defaults its entry to `server.js`
  at the app root** — the repo ships a one-line ESM shim
  (`import('./scripts/serve.mjs')`, `package.json` `"main": "server.js"`, the
  `"type": "module"` field makes the root file ESM) so the deploy needs zero
  entry-file configuration; the real server logic stays in
  `scripts/serve.mjs` (shebang mandatory — without it PM2 restart-loops). The
  sync workflow smoke-tests `node server.js` (the actual Velocity entry). No
  build command (no-op `build`/`start` shims exist for auto-detection); env
  `NVOOS_TARGET_SITE=<wp-site>`.
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

### Complete Console (`console.nvoos.cloud`) — mirror + WP backend

Second Velocity app in the same family, serving the NV oOS **Complete
Console** SPA (compiled from `addons/pro/assets/spa-v2/` — not the SA
addon SPA):

- Mirror repo **`nvdigitalsolutions/nvoos-complete-velocity`** (flat,
  force-push), assembled from `examples/nvoos-complete-velocity/` by
  `sync-nvoos-complete-velocity.yml`. Triggers: pushes to main/alpha-working
  touching `examples/nvoos-complete-velocity/**` **or
  `addons/pro/assets/spa-v2/**`** (the console compiles spa-v2 in at
  monorepo build time — a spa-v2 change must redeploy the console or it
  serves stale code) + workflow_dispatch. Secret:
  `NVOOS_COMPLETE_VELOCITY_REPO_TOKEN`. Ships at the repo ROOT (entry
  `server.js` → `scripts/serve.mjs`), no build command, env
  `NVOOS_TARGET_SITE=<complete-bundle-wordpress-site>`.
- The app proxies `/wp-json`, `/wp-admin`, `/wp-login.php` and
  `/wp-includes` to `NVOOS_TARGET_SITE` (cookie-auth mode — same-origin
  cookies + the wp_rest nonce handshake; the login page is re-scoped to the
  app origin). The target site runs the **Complete bundle**
  (`nvdigital-open-operator-system-oos-complete`), not the standalone
  base/pro pair — the console app needs only the bundle (the
  `schedule-anything-platform` plugin serves the SA tenant SPA and is not
  part of this app's backend). Any SPA-consumed Pro route must therefore
  ship in the complete zip. Never point `NVOOS_TARGET_SITE` at a URL you
  do not control — the proxy forwards cookies for those prefixes.
- **Failure class "SPA ahead of the bundle"**: the SPA redeploys calling a
  newer route while the installed bundle predates it → that one route 404s
  while sibling routes still answer. Probe ladder (unauthenticated):
  `GET /wp-json/mcp-ai-pro/v1/sa-tools` → **404 = route missing (bundle
  stale)**; `toolkits`/`presets` → **403 = routes exist, auth-gated**;
  `GET /wp-json/nvoos-saas/v1/auth/nonce` → **200 = platform plugin
  present**. Fix = deploy a bundle zip containing the route; verify
  freshness before deploying (see §7).

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
- **`build/*.zip` are git-tracked** — they are committed release artifacts,
  not throwaway output. CI (`build-assets.yml`) rebuilds the wp.org-slug
  `nvdigital-open-operator-system-oos-*` zips on main/alpha-working/release
  merges, so a "stale zip" claim must be re-verified against the current
  HEAD before rebuilding:
  `unzip -p build/nvdigital-open-operator-system-oos-complete-1.2.8.zip "nvdigital-open-operator-system-oos-complete/addons/pro/includes/rest/class-wp-mcp-ai-pro-sa-rest-controller.php" | grep -c "sa-tools"`
  must be > 0 (2 in the 1.2.8 build: registration + docblock). In the
  complete zip the Pro controller ships at `addons/pro/includes/rest/` — it
  is NOT flattened into `includes/rest/`. The repo-slug
  `mcp-ai-wpoos-pro-*.zip` can lag its wp.org-slug sibling (per-slug CI
  coverage — seen 2026-10-20: repo-slug 0 hits vs wp.org-slug 2).
- **Rebuilding zips on this Windows box**: the native Git Bash `rsync.exe`
  is broken (`msys-xxhash-0.dll` missing → exit 127) and
  `build-plugin-zip.sh` has no WSL fallback (only
  `build-wordpress-org-from-base.sh` re-execs via WSL) → run the whole
  build in WSL:
  `wsl -e bash -c "cd /mnt/f/GITHUB/mcp-ai-wpoos && bash bin/build-plugin-zip.sh --base --pro --combined --core-only --wp-org"`
  (WSL has php 8.3 / composer / node / zip / unzip / rsync). `--combined`
  alone is NOT enough for the `nvdigital-…-complete` zip: the wp.org
  transform runs under `set -e` and aborts if any of its four input zips
  (base/pro/combined/core) is missing. Build inside
  `git worktree add --detach <path> <commit>` so `composer install
  --no-dev` + `npm ci && npm run build:full` churn stays out of the main
  tree.

## 8. Single-site (sole tenant) mode (Proposal 082, platform 0.1.1+)

On a non-multisite install the platform runs in **sole-tenant mode** — the
single blog is the tenant. No constant opt-in; it is automatic.

- **Activation** no longer `wp_die`s or self-deactivates. It seeds
  `sa_tenant_meta` (tier default `enterprise`, filter `sa_single_site_tier`)
  + `SA_Toolkit_Manager::ensure_defaults( $tier )` — both idempotent, never
  overwrite. The old multisite-required error notice is replaced by a
  dismissible `notice-info` (option `sa_single_site_notice_dismissed`,
  nonce'd GET link).
- **Capability seam**: the platform filters
  `wp_mcp_ai_pro_schedule_rest_capability` to `manage_options` on
  single-site (Pro defaults to `sa_manage_workspace` when the platform is
  active — site admins don't hold it, so without this every SPA REST call
  403s).
- **Provisioner**: `provision()` = idempotent self-registration (no
  `wpmu_create_blog`); same tenant contract + `single_site: true`. Admin
  user created via `wp_create_user`, elevated to `administrator` ONLY when
  newly created (existing users are never escalated). `offboard()` of the
  sole tenant → `409 sa_cannot_offboard_sole_tenant`; unknown IDs →
  `sa_blog_not_found`. `generate_subdomain()` uniqueness loop is
  `is_multisite()`-guarded (the old call to multisite-only
  `get_blog_id_from_url()` was a latent single-site fatal).
- **REST**: `lookup` matches the provisioned `sa_tenant_meta.subdomain` or
  the host label only (never fabricates tenants); `status` resolves the
  sole tenant; `healthz` already reports `multisite: false, blog_count: 1`.
  Platform auth (X-SaaS-API-Key) is NOT relaxed.
- **Heartbeat**: `SA_Usage_Tracker::send_heartbeat()` no-ops without
  `SA_SAAS_API_KEY` (both modes — fixes the latent empty-key POST).
- **Tests**: `addons/schedule-anything-platform/tests/test-single-site-mode.php`
  (17 cases) + a self-guarding `tests/bootstrap.php` wired into the root
  suite (`phpunit.xml.dist`); all three platform test files now run in the
  standard single-site PHPUnit suite.

## 9. WP-CLI toolkit + SA-adjacent commands (Pro, #7135)

- **Canonical toolkit key-map** — `WP_MCP_AI_Pro_Toolkit_Settings_Map`
  (`addons/pro/includes/cli/class-wp-mcp-ai-pro-toolkit-settings-map.php`,
  40 toolkits) is consumed by `toolkit list/enable/disable` **and**
  `pro status` — never hardcode a toolkit list in a command (11 real settings
  keys were missing and `toolkit enable enable_cloudways_toolkit` failed with
  "Unknown toolkit key").
- **Gates** — `toolkit enable|disable` require `manage_options`; `enable` takes
  `--yes`; formats include `ids`/`count`. Integration CLIs (ezuite/flowhub/
  shopify-sync) load unconditionally from the Pro loader (so
  `wp help mcp-ai` discovers them) and assert their toolkit toggle per verb.
- **SA-adjacent commands** — `wp mcp-ai fnb status`; `foundry status` +
  `export <assistant-id>` (canonical export tools, `--dry-run`);
  `mcp-app list --assistant-id=<id>` (metadata only, never renders secrets).

## References

- Proposal 081 + plan doc (linked above)
- `mcp-ai-wpoos-spa-ui` skill — the SPA UI stack, Pattern B rules, vitest gotchas
- `mcp-ai-wpoos-test-suite` skill — PHPUnit conventions for the new suites
  (`addons/pro/tests/test-sa-toolkit-rest.php`, `test-schedule-presets-rest.php`)
- `examples/nvoos-pro-spa-vite/scripts/serve.mjs` — the proxy core this app ports
- `.github/workflows/{sync-nvoos-schedule-anything-velocity,build-spa-addons,spa-bundle-size}.yml`
