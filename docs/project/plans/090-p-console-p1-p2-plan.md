# Proposal 090-p — Console P1/P2 follow-through

**Status:** Planned 2026-11-16. Shipped in this pass: W1 (professions), W2 (reason
surfaces), W3 (discovery pinning + auth), W4 (fragment golden file), W5
(chatChannels decision + dead-page gate), W7 (org teams on WordPress).
**Deferred with design:** W6 (connected-app proxy), W8 (console e2e in CI).
**Predecessor:** `090-n-console-manifest-parity-plan.md` (manifest parity, merged
as #7831).

---

## 1. Scope

This pass covers the P1 and P2 items from the roadmap that followed #7831:

| Priority | Item | This pass |
|---|---|---|
| P1 | Console renders endpoint reasons instead of only hiding surfaces | **W2 shipped** |
| P1 | WordPress `professions` REST route | **W1 shipped** |
| P1 | Connected-apps hardening (DNS pinning, per-app auth) | **W3 shipped** |
| P1 | WordPress `orgTeams` REST route | **W7 shipped** |
| P2 | One source of truth for endpoint fragments | **W4 shipped** |
| P2 | Console e2e in CI | **W8 deferred** (needs a service stack; recipe below) |
| P2 | `chatChannels` decision | **W5 shipped** (defer toolkit; hide the dead admin page) |
| P0 rider | Chat-channels admin page calls an unregistered route | **W5 shipped** |

The P0 (dead admin page) rides W5 because the decision and the page cannot
disagree: a page whose every request fails is worse than a hidden page.

---

## 2. Industry standards applied

### 2.1 Unavailable surfaces: show the reason, do not fake the data

Nielsen Norman Group and current empty-state guidance agree on the rule used
here: *do not silently remove an option the user expects; explain why it is
unavailable and what would enable it*. Three patterns exist — hide, disable with
an explanation, or offer an alternative. We use:

- **Hide from the nav** (the page would fail), plus
- **a dashboard "Unavailable on this runtime" card** that names the surface and
  its supply class, so the operator can tell "not built yet" from "connect the
  product" from "the addon is not active".

This is the same fact the manifest already carries (`endpoint_reasons`); the
work is presentation, not new protocol.

### 2.2 SSRF: resolve, validate every address, and pin the connection

OWASP's SSRF guidance is explicit that hostname allowlisting alone is
insufficient: **validate the resolved destination IPs and ensure the client
connects to a validated address** (DNS rebinding is a TOCTOU attack). W3
implements that:

1. resolve the hostname (A + AAAA),
2. refuse if *any* resolved address is private, reserved, loopback or
   link-local,
3. pin the validated address for the request via `CURLOPT_RESOLVE`, so the
   client cannot re-resolve to a different address.

Redirects stay disabled (a redirect is the classic pivot). DNS pinning is only
as strong as the CurlHandler; the stream handler ignores `CURLOPT_RESOLVE`, so
the limitation is documented in the class.

### 2.3 Contract tests as a golden fixture

Pact-style consumer-driven testing without a broker: the fixture is the golden
file, the providers assert their own maps against it, and the consumer asserts
the key set. W4 extends the fixture to carry the **route fragments** for both
runtimes, closing the last drift surface (keys and reasons were pinned; paths
were not).

### 2.4 BFF proxies must be allowlisted, not transparent

For W6 (deferred): a same-origin proxy is the standard way to reach a
cross-origin product from a browser app, but it must be keyed by a
server-side allowlist (never an arbitrary URL), must not forward the console's
cookies to the product, must strip the product's `Set-Cookie`, and must be
default-deny. The design is recorded in §5.1; it is deferred because it needs a
product/CORS decision and a security review, and shipping it untested would be
worse than not having it.

### 2.5 E2E gates are a ladder, not a switch

For W8 (deferred): a full Octane + MySQL + Redis + built-SPA Playwright run is
the top rung and the most brittle. The ladder is: (1) PHP feature tests in CI
(already), (2) a backend smoke against `php artisan serve` + SQLite, (3) the
full T0 console smoke. Each rung is independently useful; skipping rungs buys
flakiness.

---

## 3. W1 — WordPress professions route (shipped)

**Files:** `includes/rest/class-wp-mcp-ai-rest-professions-controller.php`,
`includes/bootstrap/loader.php`, contract fixture, console endpoint map,
`addons/pro/assets/spa-v2/src/api/professions.ts` (comment), tests.

**Design**

- `GET /mcp-ai/v1/professions` → `{ professions: [...], total, catalogue_version }`,
  the same shape as `ProfessionController@index` on Laravel.
- `GET /mcp-ai/v1/professions/{slug}` → `{ success: true, data: { … } }`, or a
  404 `WP_Error`.
- Both require `manage_options` (matches the Laravel `can:manage_options`
  group and the console's admin gating).
- Reads the existing `WP_MCP_AI_Profession_Repository`, with the post-meta cache
  enabled for the list (the repository defaults it off for dropdown queries).
- `catalogue_version` is the newest `post_modified_gmt` across the catalogue —
  a stable epoch the console can use to invalidate, without adding an option.

**Contract/console:** remove `professions` from `wordpress_reasons` (it is now
`route` on both runtimes) and drop the `!isWordPress` blank in the console's
endpoint map. The key flips to `true` in the WordPress manifest.

**Acceptance:** list + show + 404 + permission tests; WP manifest reports
`professions` true and `gaps` stays `[]`; console test asserts the WP base.

---

## 4. W2 — Reason-aware console (shipped)

**Files:** `examples/nvoos-complete-velocity/src/availability.ts`,
`runtime-config.ts`, `main.tsx`, `console/nav-features.tsx`,
`console/DashboardPage.tsx`, `console/ConsoleShell.tsx`, tests.

**Design**

- `fetchRuntimeAvailability` already reads `endpoints`; it now also reads
  `endpoint_reasons` into `endpointReasons` (additive, fail-open).
- `nav-features.tsx` gains `unavailableNavItems(features, capabilities,
  endpoints, reasons)`: it returns the surfaces the session *could* use but the
  runtime cannot serve, each with a humanised reason. Capability failures are
  excluded — a permission the user lacks is not "unavailable on this runtime".
- The Dashboard's quick actions now pass `endpoints` to `isNavItemVisible`
  (they previously ignored it, so a quick action could open a blank page).
- A new "Unavailable on this runtime" card on the Dashboard lists the surfaces
  and reasons. It renders nothing when the list is empty, and nothing when the
  map is absent (fail-open).

**Reason copy** (supply class → operator language):

| Code | Copy |
|---|---|
| `external-product` | Supplied by a separate product that is not connected. |
| `toolkit-shaped` | Belongs to a toolkit family that is not built yet. |
| `connected-service` | Needs an external service to be configured. |
| `runtime-adaptable` | Not exposed as a route on this runtime yet. |
| `not-installed` | The addon that supplies this is not active. |
| `route` (false) | The route is missing — report this as a regression. |

**Acceptance:** unit tests for the helper and the card; the dashboard still
renders without the maps; quick actions hide when an endpoint is blank.

---

## 5. W3 — Connected-app hardening (shipped)

**Files:** `app/Support/ConnectedApps/ConnectedAppRegistry.php`,
`lib/laravel-adapter/config/nvoos.php`, `tests/Feature/ConnectedAppsTest.php`.

**Design**

- **Resolution + validation:** `gethostbynamel()` + `dns_get_record(A)`
  (`AAAA`), deduplicated. Refuse when any address is private/reserved
  (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`). `localhost` is
  allowed only in `local`/`testing` and still validated as loopback-safe for the
  http scheme.
- **Pinning:** pass the validated addresses to the request as
  `curl[CURLOPT_RESOLVE]` entries (`host:port:ip`) so the transport cannot
  re-resolve. Guarded by `defined('CURLOPT_RESOLVE')`; the stream handler
  ignores it (documented).
- **Per-app auth:** an optional `token` in `oos.connected_apps.<key>` is sent as
  `Authorization: Bearer`. It is never logged, never part of the cache key, and
  never returned in the manifest.
- **Tests:** injected resolver proves a private resolution is refused with no
  request; a public resolution proceeds; `resolveHost`/`isPublicIp` are pinned
  directly; the existing refusals stay green.

**Acceptance:** all `ConnectedAppsTest` cases pass, including the new ones.

---

## 5.1 W6 — Connected-app proxy (deferred)

**Why deferred:** the console islands fetch their REST base from the browser.
A remote product is a different origin, so either the product sends CORS headers
(and credentials) or the console needs a same-origin proxy. That is a security
surface (an open proxy) and a product-contract decision, not a console tweak.

**Design when it is taken up:**

1. `serve.mjs` exposes `/connected/<key>/*` for the four known keys only.
2. The upstream is resolved server-side from `NVOOS_CONNECTED_APPS` (or the
   backend's public `/features` `endpoint_urls`, cached 5 min); a request can
   never name an arbitrary URL.
3. The forwarded request drops `Cookie`/`Authorization`; a configured per-app
   token is added server-side. The response drops `Set-Cookie` and is
   `Cache-Control: no-store` unless the product opted into a public TTL.
4. Path segments are normalised; `..`, absolute URLs, and encoded separators are
   rejected.
5. The console rewrites a discovered URL to `/connected/<key>` only when the
   proxy advertises itself (a `connected_proxy: true` field on `/features`).
6. Tests: allowlisted upstream reaches a local fixture server; an unknown key is
   404; `..` and absolute-URL paths are 400; cookies are not forwarded; a
   `Set-Cookie` response is stripped.

**Acceptance:** the four product keys mount through the proxy with no CORS
configuration, and the proxy cannot be used to reach a host outside the
allowlist.

---

## 7. W4 — Fragment golden file (shipped)

The contract now carries `fragments.laravel` and `fragments.wordpress`, mapping
every route-backed key to the fragment that must be registered. Both PHP suites
assert their constants equal the fixture; the console suite asserts both maps
cover exactly the route-backed keys. A path change now fails on three CIs.

---

## 8. W5 — chatChannels decision (shipped)

**Decision:** the communications surface stays `toolkit-shaped`. It is not a
controller port; it is a toolkit family (contacts, conversations, delivery
status, takeover) that should be modelled as a manifest-driven family when a
Laravel estate actually sends SMS/email (090-i decision 2). No controller is
added now.

**Consequence for WordPress:** the Pro admin page calls
`mcp-ai-pro/v1/chat-channels` (and `/contacts`, `/conversations/{id}/messages`,
`/contacts/{id}/status`, `/contacts/{id}/takeover`, `/contacts/{id}/tag`) but no
controller registers any of them. The menu is therefore gated behind
`wp_mcp_ai_chat_channels_enabled` (default **false**) so the console and the
admin surface agree: hidden until the surface exists. Re-enable the filter when
the toolkit ships.

---

## 9. W7 — orgTeams on WordPress (shipped)

WordPress now serves the same org-team contract Laravel does, so the console
no longer hides the surface. As built:

- **Store:** a non-public `mcp_ai_org_team` CPT. Membership lives in the
  `_wp_mcp_ai_org_team_members` meta as `user_id => role`. A CPT beats custom
  caps because teams are tenant data, not authorization primitives; the roles
  are validated in the service layer, not registered as WP roles.
- **Roles:** `owner`, `admin`, `manager`, `member`, `viewer`. The service
  enforces a last-owner invariant (the final owner cannot be demoted or
  removed) and per-site slug uniqueness.
- **Routes:** `mcp-ai/v1/org/teams`, `/{team}`, `/{team}/members`,
  `/{team}/members/{user}`. `201` on create/member-add, `204` on delete, `404`
  for a non-member, `403` when the caller lacks the ability.
- **Manifest:** `orgTeams` moved from `runtime-adaptable` to `route`, and the
  key left `wordpress_reasons` in the shared contract. Live WordPress manifest
  is now **41 true / 4 false / `gaps=[]`**.

Remaining `false` keys are all classified: `chatChannels` (toolkit-shaped),
`contentGraph` / `mediaStudio` / `documentEditor` (external-product).

---

## 10. W8 — Console e2e in CI (deferred)

The T0 smoke needs MySQL, Redis, Octane/FrankenPHP and a Laravel-profile bundle.
The proposed ladder:

1. **Now (CI):** `laravel-app.yml` already runs the PHP feature suite on SQLite.
2. **Next:** a `console-contract` job that boots `php artisan serve` (SQLite,
   sync queue) on :8001, builds the console with `--mode laravel`, serves it via
   `scripts/serve.mjs`, and runs a **narrow** Playwright spec (features envelope
   + one authenticated page). This avoids Octane/FrankenPHP in CI.
3. **Later:** the full T0 smoke against the Docker stack, manual/pre-release.

Acceptance for rung 2: the job fails when `/api/v1/features` loses a required
field, when an endpoint key disappears, or when the console build cannot boot.

---

## 11. Sequencing and validation

| Order | Workstream | Gate |
|---|---|---|
| 1 | W1 professions + contract/console flip | WP PHPUnit, console Vitest |
| 2 | W4 fragments | Laravel + WP + console contract tests |
| 3 | W2 reasons | console Vitest, typecheck |
| 4 | W3 pinning/auth | Laravel feature tests |
| 5 | W5 decision + gate | PHPUnit (filter default), docs |
| 6 | W7 org teams | WP PHPUnit (14 cases), console Vitest, live manifest |

Validation actually run is recorded in the PR body, not here.

## 12. Risks

- **W1 permission:** the route is admin-only; an editor cannot read the
  catalogue until WordPress grows an editor-scoped read (the console already
  hides the page for non-admins).
- **W3 pinning depends on the CurlHandler.** Documented; the validation still
  refuses a private resolution before the request.
- **W5 hides an existing admin menu.** That is the intent — the page cannot
  succeed — and the filter is the rollback.
- **W2 copy drift.** The reason→copy map lives in one module and is unit-tested
  against the vocabulary.
