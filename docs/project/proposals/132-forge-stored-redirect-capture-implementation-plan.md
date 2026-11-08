# Proposal 132 — Implementation plan (stored redirect capture)

Companion to `132-forge-stored-redirect-capture-proposal.md`. Shipped
2026-11-08 (bridge `0.3.0` + forge integration).

---

## 1. Scope

1. A read-only bridge route that normalizes **Redirection** and **Rank Math**
   stored rules into `{ source, target, type }`.
2. Operator controls: opt-in toggle + optional shared token, plus provider
   detection on the settings page and in `/health`.
3. Forge integration: merge the stored map beneath the rebuild's URL map,
   fail soft, and report `bridge` / `warnings`.
4. Template build: fetch the export at build time, emit a faithful
   `redirects.json`, and project it onto a portable `_redirects`.
5. Tests at every seam (bridge PHPUnit, forge unit, forge e2e).

Out of scope (declared in the proposal): regex/query/conditional rules,
chain flattening, Yoast Premium redirects, and any write path.

---

## 2. File-by-file

### 2.1 `plugins/nvoos-forge-bridge/includes/rest/class-nvoos-forge-bridge-redirects.php` (new)

- `GET /nvoos-forge/v1/redirects`, `permission_callback` =
  `check_access()` (always true when no token is stored; constant-time
  `hash_equals` otherwise; accepts `X-Forge-Redirects-Token` or a Bearer
  token).
- `active_providers()` — detection order: plugin class/constant → provider
  options row → table existence. Filterable via
  `nvoos_forge_bridge_redirect_providers` (the extension point for another
  adapter).
- `collect_redirection()` — joins `redirection_groups` (a disabled group's
  rules are not live), `status='enabled'`, `match_type='url'`, `regex=0`;
  `action_type='url'` requires a supported `action_code` and reads
  `action_data.url` (JSON **or** PHP-serialized); `action_type='error'`
  accepts 404/410.
- `collect_rankmath()` — `status='active'`; `sources` decoded from
  serialized **or** JSON; only `comparison='exact'` (or absent) patterns;
  `url_to` + `header_code`.
- `normalize_source()` — full/protocol-relative URLs reduce to their path;
  Rank Math patterns gain the leading slash; `/` is refused.
- `normalize_target()` — same-host absolute URLs reduce to a path (+query);
  external URLs stay absolute; relative paths gain the leading slash.
- `append_rule()` — refuses query/fragment-scoped sources (counted, never
  widened) and self-maps.
- `dedupe()` — first provider wins; conflicts counted as `duplicate`.
- Bound: `MAX_ENTRIES = 500`, queried as `LIMIT 501`, sliced + flagged.
- Response is redaction-safe: only `source`/`target`/`type` per rule.

### 2.2 `plugins/nvoos-forge-bridge/includes/class-nvoos-forge-bridge-settings.php`

- `OPTION_REDIRECTS_ENABLED`, `OPTION_REDIRECTS_TOKEN` added to
  `option_keys()` (so `uninstall.php` removes them).
- `redirects_enabled()`, `redirects_token()`.
- `handle_save()` reads the checkbox + the write-only token.
- `render_page()` gains a **Stored redirects** section, provider detection
  (“Detected: redirection, rankmath”), and a warning notice when enabled with
  no provider.

### 2.3 `plugins/nvoos-forge-bridge/includes/rest/class-nvoos-forge-bridge-health.php`

- `redirectsEnabled`, `redirectsAvailable`, `redirectsProviders` (computed
  once). Still no URLs, tokens, or secrets.

### 2.4 `plugins/nvoos-forge-bridge/includes/class-nvoos-forge-bridge-plugin.php`

- `Rest\Redirects` registered alongside `Rebuild`, `Health`, `Forms`.

### 2.5 Version + readme

- `0.2.0` → `0.3.0` in the plugin header + `NVOOS_FORGE_BRIDGE_VERSION`.
- `readme.txt`: tags, short description, a bullet, and a `0.3.0` changelog
  entry.

### 2.6 `plugins/nvoos-forge-bridge/tests/test-redirects.php` (new, 9 tests)

Provider tables are created once per class (DDL is not transactional; DML is
rolled back by the WP suite). Coverage:

| Test | Asserts |
|---|---|
| `route_registered` | `/nvoos-forge/v1/redirects` exists |
| `disabled_by_default` | 200, `enabled:false`, empty list |
| `no_provider_is_empty_not_an_error` | 200, `available:false`, no 500 |
| `redirection_export_filters_and_counts` | 301/302/410 kept; disabled/regex/agent/query skipped + counted; sorted output |
| `redirection_disabled_group_is_skipped` | group gating |
| `rankmath_export_exact_only` | serialized + JSON sources, exact-only, home-host target → path |
| `duplicate_source_is_deduped` | one rule, Redirection wins, `duplicate:1` |
| `token_gate` | 401 without/with a wrong token; header + Bearer accepted |
| `export_is_bounded` | 501 rows → 500 + `truncated:true` |
| `response_is_redacted` | only `source`/`target`/`type`; no ids/hits/groups/timestamps |

### 2.7 `examples/nvoos-astro-forge/lib/wp-client.ts`

- `transport()` — exposes `{ baseUrl, fetchImpl, userAgent }` so the bridge
  fetcher reuses the client's transport in tests and production.

### 2.8 `examples/nvoos-astro-forge/lib/redirects.ts`

- `RedirectStatus` (`301|302|303|307|308|404|410`) + widened `RedirectEntry`.
- `fetchBridgeRedirects(profile, options)` — endpoint = explicit override →
  `profile.forms.endpoint` → `<profile.url>/wp-json/nvoos-forge/v1`; token
  from `options.token` or `NVOOS_FORGE_REDIRECTS_TOKEN`; 10 s timeout;
  404 = silent “not installed”; every other failure = one warning. Validates
  and re-types the contract (drops `/`, self-maps, unknown statuses, empty
  targets for redirect codes).
- `generateRedirects(profile, client, { bridge })` — inserts bridge rules
  first, then the URL-structure pass overwrites conflicts (`bridgeFroms`
  tracks survivors). Report gains `bridge` + `warnings`.
- `{ bridge: false }` skips the bridge entirely.

### 2.9 `examples/nvoos-astro-forge/mcp/tools/content.ts`

- `redirects_generate` description + message mention stored rules and
  warnings.

### 2.10 `examples/nvoos-astro-forge/templates/blog/src/pages/redirects.json.ts`

- A build-time `fetchStoredRedirects()` (env-first endpoint resolution:
  `NVOOS_FORGE_REDIRECTS_ENDPOINT` → `WP_SITE_URL` → manifest URL; optional
  `NVOOS_FORGE_REDIRECTS_TOKEN`). 404 silent, other failures warn. The map
  is built bridge-first, then the collection pass overwrites.

### 2.11 `examples/nvoos-astro-forge/templates/blog/scripts/postbuild.mjs`

- Faithful `redirects.json` → portable `_redirects` projection:
  `303/307→302`, `308→301`, `410→404`; unknown statuses omitted; 404/410
  targets become `/`. A leading `#` note documents the projection and the
  Cloudflare 404/410 caveat. The log line reports the projected count.

### 2.12 `examples/nvoos-astro-forge/lib/scaffold.ts`

- `.env.example` gains `NVOOS_FORGE_REDIRECTS_ENDPOINT` and
  `NVOOS_FORGE_REDIRECTS_TOKEN` with the enable/override notes.

### 2.13 Fixture + tests

- `scripts/wp-fixture-server.mjs` serves
  `/wp-json/nvoos-forge/v1/redirects` with a 301 and a 302.
- `tests/redirects.test.ts` — 9 tests: URL map, self-map/no-op, bridge
  absent, merge + conflict precedence, bridge truncation, network failure,
  `bridge:false`, endpoint/token propagation, invalid JSON.
- `tests/forge-e2e.test.ts` — the built `redirects.json` and `_redirects`
  carry both stored rules; `redirects_generate` reports `bridge: 2` and
  accepts the widened status set.

---

## 3. Merge precedence (the one rule to remember)

```
stored (bridge) rules  →  inserted first
URL-structure rules    →  overwrite the same `from`
```

The rebuild knows where it actually serves content; a stored rule only fills
the gaps it does not claim. A stored self-map or `/` rule is dropped before
the merge.

---

## 4. Validation (actually run)

| Gate | Command | Result |
|---|---|---|
| PHP syntax | `php -l` on every touched PHP file | clean |
| PHP coding standard | `vendor/bin/phpcs --standard=phpcs.xml.dist .` | **0 errors, 0 warnings** |
| Bridge PHPUnit | `vendor/bin/phpunit -c phpunit.xml.dist --no-coverage` | **58 tests, 167 assertions, OK** |
| Forge typecheck | `npm run typecheck` | clean |
| Forge unit suite | `npm test` | **551 tests / 42 files, all pass** |
| Forge e2e | `npm run test:e2e` | **31 tests, all pass** |

Notes:

- The bridge suite runs against the real WordPress test library + a local
  MySQL `wordpress_test` database, booting the plugin through
  `plugins_loaded` (the production path).
- The e2e suite scaffolds the production template, runs `astro check`, builds
  against the hermetic WP fixture (which now serves the bridge export), and
  asserts both the structured map and the materialized `_redirects`.
- One test-suite finding was fixed during validation: Rank Math stores
  patterns without slashes, so the export adds only the leading slash and
  keeps the rest faithful.

---

## 5. Operator runbook

1. Install/upgrade **NV oOS Forge Bridge** to `0.3.0`.
2. **Settings → Forge Bridge → Stored redirects** → check *Enable redirect
   export*. The section reports which provider was detected.
3. Optional: set an **export token** if the URL history should not be
   publicly readable; then export `NVOOS_FORGE_REDIRECTS_TOKEN` in the build
   environment.
4. Rebuild. `redirects_generate` and the generated `dist/redirects.json`
   now include the stored rules; `dist/_redirects` carries the portable
   projection.
5. Verify on a staging host: request a handful of legacy paths and confirm a
   single 301/302 to the final URL (no chains).

---

## 6. Known limits (unchanged by design)

- Regex, query-scoped, and conditional (referrer/cookie/IP/role) rules are
  **counted, not exported** — see `skipped` in the response.
- Chain flattening is not performed; a stored rule pointing at a URL that is
  itself redirected stays two hops. `verify --redirects` is the natural home
  for that check later.
- Rank Math patterns without a trailing slash stay that way. Netlify
  normalizes trailing slashes; Cloudflare Pages matches exactly — add an
  explicit rule per variant if you deploy there.
