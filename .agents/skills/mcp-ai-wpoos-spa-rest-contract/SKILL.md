---
type: Skill
name: mcp-ai-wpoos-spa-rest-contract
description: Audit NV oOS console SPA clients (addons/pro/assets/spa-v2/src/api/*.ts) against their WordPress REST controllers and fix contract drift — phantom fields, ignored params, missing routes/methods, admin-gated route registration (front-end SPA gets core 404s), envelope mismatches, field-name drift, settings gates, route collisions (last registrant silently wins), and empty-then-loaded loading-state flashes. Covers the audit path (config key → PHP emission → route → boot path → handler → response shape), the live REST-index route-presence cross-check, fix decision rules, the 2026-10-21 baseline + registration pass, parallel sub-agent delegation, and the validation matrix. Use when a console page shows blank or wrong data, when adding a console surface, or when asked to "audit the REST contracts".
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.2.11"
  plugin-version-tested: "1.2.11"
  last-updated: "2026-10-24"
---

# NV oOS Console SPA ↔ REST Contract Audit

Playbook for keeping the console SPA clients (`addons/pro/assets/spa-v2/src/api/*.ts`)
in contract parity with the WordPress REST controllers they consume. Canonized
from the 2026-10-21 audit (PR #7166) that found **nine** mismatches, including
the assistants edit form being completely broken (no GET/PUT single route;
the form read fields the directory never returned).

## When to use this skill

- A console page renders blank/wrong/empty fields (the classic symptom)
- Adding or porting a console surface (Proposal 084) — audit before shipping
- Asked to "audit the REST contracts", "check for mismatches", or "continue
  the contract parity pass"
- Reviewing a new `api/*.ts` client against its controller

## The bug classes (all real, from the 2026-10-21 audit)

1. **Phantom fields** — client reads what the server never returns.
   `assistants.ts` read `system_prompt` + `capabilities` from the directory
   summary, which only has `tools`/`description`. Symptom: form fields always
   empty.
2. **Ignored params** — client sends what the server ignores. The assistant
   form posted `capabilities`; the create handler accepts `tools`.
3. **Missing routes/methods** — client called `GET /assistants/{id}` /
   `PUT /assistants/{id}` / `GET /slash-commands/list` — none registered.
   Symptom: 404 toast on save.
4. **Envelope mismatch** — create returns the directory summary directly
   (201) but the client unwrapped `{success,data}` → `{}`. Also: bare-array
   list responses vs `{items}` envelopes vs `{success,data}` wrappers — all
   three exist across the codebase; check each.
5. **Field-name drift** — chat-channels client typed `message`/`sender`;
   server emits `content`/`contact_name`. The vitest contract test baked in
   the phantom fields, so it passed while the UI rendered blank.
6. **Settings gates** — `rest_enable_assistant_create` (default **off**) 403s
   the console "Add Assistant" until enabled in Settings → NV oOS →
   Authentication. Always check the permission callback for settings gates.
7. **Query-string typing** — `filters` sent as a JSON query string vs server
   arg `type => 'object'` → WP 400 `rest_invalid_type` (sanitize runs before
   validate; without a `sanitize_callback` a string never passes object
   validation).
8. **Registration-context gating** — the controller exists and is
   contract-clean, but its `::init()` is only called from an admin-gated
   path, so the routes never register on front-end REST requests (the Pro
   SPA renders on a front-end page; `is_admin()` is false there). Symptom:
   the WHOLE route family 404s with "No route was found matching the URL and
   request method" while other `mcp-ai-pro/v1` routes work — the 2026-10-21
   post-audit pass found the six Proposal 084 console bridges (ops overview,
   workflow-presets, workflow-builder node exec, records, dashboards,
   media-cc) registered inside `wp_mcp_ai_pro_load_admin_sections()`, which
   only runs under the `admin_sections` module's `context => 'admin'` gate.
   Fix: boot REST controllers from a context-free registry module (they now
   live in `rest_console_bridges`) — each controller is idempotent and only
   hooks `rest_api_init`, so per-request booting is safe. Regression test:
   `tests/rest-api/test-rest-console-bridges.php` boots the registry and
   asserts the routes exist after a fresh `rest_api_init` — the per-controller
   tests masked this bug with a manual-registration fallback, so pin the real
   boot path, not the class in isolation.
9. **Route collision — two controllers register the same namespace + route
   + method; the last registrant silently wins.** WP REST stores one handler
   per route+method (`$routes[$route][$method] = $endpoint` — a later
   `register_rest_route()` REPLACES the earlier one), so whichever controller
   registers last on `rest_api_init` owns the route. Real case (2026-10-22):
   the scheduled-result controller's picker and the schedule CRUD controller
   both registered `GET /mcp-ai-pro/v1/schedules` — the picker returns a bare
   `[{ id, name, … }]` array while the console + SA SPA expect the CRUD
   `{ ok, schedules, total }` envelope. Whichever won, the other surface
   silently broke (schedules list empty/blank while presets — a different
   route — kept working). Diagnosis: grep the REST tree for duplicate route
   strings (`grep -rn "'/schedules'" addons/pro/includes/rest/ includes/`),
   check the module-registry boot order (`resolve_order()` preserves insertion
   order for dep-free modules — later modules overwrite earlier ones), and
   inspect `rest_get_server()->get_routes()` for the surviving handler. Fix:
   move the lesser-consumed handler to its own literal sub-path (picker →
   `/schedules/selectable`, its block-editor caller follows) so both shapes
   always resolve; pin with a regression test that registers the conflicting
   controller in the *losing* order (require the file after the other
   controller registered) and asserts the surviving envelope
   (`addons/pro/tests/test-pro-schedule-result-rest.php`).
10. **Empty-then-loaded flashes — pages seed `useState([])`/`null` and render
    the empty state before the fetch resolves.** Symptom: "shows empty for a
    sec then loads in". Fix discipline: keep a loading flag distinct from the
    data length (`schemaLoading` vs `entities.length === 0` — a genuinely
    empty schema must never spin forever), gate the empty state on
    `! loading`, and render a loading placeholder while in flight. Detail
    forms are worse than cosmetic: the assistants edit form seeded
    `system_prompt: ''` from the list row until `GET /assistants/{id}`
    landed, so a premature save overwrote the stored prompt — disable form
    saves (and show a "Loading…" cue) until the full record arrives. Swept
    surfaces (2026-10-22): assistants edit form, RecordsPage, DashboardsPage
    (schema + data), ChatChannelsPage. Already-safe patterns to copy:
    MediaOpsPage's `bootstrapLoading || loading` early return, TeamsPage's
    `loadingMembers` ternary, the console DashboardPage's `assistantsLoaded`
    + `LoadingDash` stat placeholder.
11. **Wrong-store meters — a displayed metric measures a different store than
    the data actually lives in.** Real case (2026-10-23): the status-bar
    "Sessions: X KB" figure summed same-origin localStorage keys
    (`wp_mcp_ai_*`, `nvoos-chat-spa*`, `nvoos-pro-spa*`), designed around the
    legacy base chat writing history to localStorage. The Pro SPA stores
    transcripts **server-side** (JetEngine CCT via `/chat-transcripts`), so
    the meter read ~0 bytes and rendered "0 KB"; the standalone
    nvoos-complete-velocity app runs on a different origin where the base
    chat's keys can never exist at all. Fix pattern (2026-10-23, PR against
    alpha-working): server-extend an aggregate into the list envelope — the
    transcripts list now carries `storage_bytes` (sum of payload lengths for
    the user, 0 in the transcripts-unavailable branch) — and have the client
    prefer the server figure, falling back to the localStorage meter only
    when the server value is unknown (older installs / endpoint down). When
    auditing a displayed metric, first verify *where the underlying data
    lives* and which origin the app runs on, before trusting the meter.
12. **Provider class loaded only in admin context — routes register fine but
    handlers silently degrade.** The sibling of bug class 8 one layer down:
    the controller boots context-free, but the DATA/PROVIDER class it wraps
    is only `require_once`d by `wp_mcp_ai_pro_load_admin_sections()` (the
    admin-gated `admin_sections` module) or inside `is_admin()` branches, so
    on front-end-originated REST requests `class_exists()` fails and the
    handler degrades — often silently. Real cases (2026-10-23, Complete
    console live repros): `GET /workflow-presets` returned `ok: true` with an
    EMPTY catalogue (Presets tab: "No presets available", zero error);
    `POST /workflow-builder/nodes/execute` always 501'd `builder_unavailable`
    (canvas "Run node"); the `/ops/overview` webhook list was silently
    `array()` (Operations page empty webhook table); `/command-center/overview`
    returned `success: false` (console Dashboard hid the whole Command
    center section — the client treats it fail-soft, so no error surfaced).

    **Two fix patterns, pick by constructor side effects:**
    - **Eager require in the `rest_console_bridges` module** when the
      provider file self-instantiates but the constructor only registers
      `admin_menu` / `admin_enqueue_scripts` / `wp_ajax_*` hooks (all dormant
      on front-end requests). Applied to: workflow presets registry, workflow
      builder page (`class-wp-mcp-ai-pro-workflow-builder-page.php`), webhook
      status page. Same rationale as the blueprints page module
      (commit `1444de08d0`).
    - **Lazy `load_provider()` in the controller** (mirror the media-cc
      controller) when the provider's constructor registers LIVE hooks that
      fire during normal request handling — the agent command center registers
      `wp_mcp_ai_after_tool_execution`, `wp_mcp_ai_after_chat_response`,
      `wp_mcp_ai_session_started/ended`, so eager loading would silently flip
      activity recording on for every front-end chat/tool event. Load inside
      the handler only.

    **Audit every bridge's provider class, not just its routes** — grep the
    controller for `class_exists` / `WP_MCP_AI_*::` references, then grep the
    provider file's load path (`grep -rn "provider-file.php" addons/pro
    includes/`); it must land in a context-free module, a self-require in the
    controller, or the bridge module's own eager require. Degraded-missing
    branches should return a 501 `WP_Error` (never `ok: true` empty or
    `success => false` envelopes — the canonical-envelope sniff flags those).
    Known-safe patterns to copy: records (extractor self-required), dashboards
    + model-comparison (self-require per page class), media-cc
    (`load_provider()`), SA REST + schedule REST (context-free modules load
    their adapters).

    **Test-masking trap:** `tests/bootstrap.php` registers
    `wp_mcp_ai_register_pro_classmap_fallback_autoloader()`, which resolves
    ANY `WP_MCP_AI_*` class from `addons/pro/includes` on demand — so
    `class_exists()` guards pass in PHPUnit even when production would never
    load the file. The honest pin asserts the provider file is EAGERLY
    included right after the registry boot (`get_included_files()` scan in
    the test, before any handler runs) — and is verified by reverting the
    production fix (`git stash push -- <file>`) and watching the pin fail.
    `tests/rest-api/test-rest-console-bridges.php` carries these pins as a
    static data provider (`provider_bridge_class_files()` — PHPUnit 11
    requires `public static` providers).

## Audit path per client (4 hops, verify every hop)

1. Client: endpoint key + methods + request fields + parsed response fields.
2. Config emission: `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php`
   (what URL the key actually gets; note `$is_admin && ! $guest` localization).
3. Route: `includes/rest/*.php`, `addons/pro/includes/rest/*.php`,
   `includes/class-wp-mcp-ai-rest.php` — method + permission + arg schemas.
   **Then find the boot path** (`grep 'Controller::init' addons/pro/includes/
   addons/pro/mcp-ai-wpoos-pro.php`): the registration must not sit behind an
   admin-context gate (`wp_mcp_ai_pro_load_admin_sections()`, a registry module
   with `context => 'admin'`, or an `is_admin()` guard) — bug class 8. **Then
   find every PROVIDER class the handler references** (`class_exists` guards,
   `WP_MCP_AI_*::` statics, `new ...`) and verify its file loads outside
   admin context — context-free module, self-require, or the bridge module's
   eager require — bug class 12.
4. Handler: the exact response array. Quote decisive lines; don't trust the
   docblock.

### Route-presence cross-check (live index)

Fetch the namespace index and diff its route map against the client list —
this is the only check that catches registration-context gating:

```bash
curl -s https://<site>/wp-json/mcp-ai-pro/v1 | jq '.routes | keys'
curl -s https://<site>/wp-json/mcp-ai/v1 | jq '.routes | keys'
```

A route that 404s with the core "No route was found…" body is **unregistered**;
401/403 (or a plugin-specific 404 envelope) means it registered fine and the
failure is auth or data. In tests, the same check is
`rest_get_server()->get_routes( 'mcp-ai-pro/v1' )` after a fresh
`rest_api_init` — never trust a controller test that registers the routes
manually as a fallback (see bug class 8).

## Fix decision rules

- **Server is the source of truth** when other consumers exist (chat drawer,
  MCP clients, wp-admin) — align the client and update its vitest contract
  test to the real field names.
- **Server-extend** when the seam is cheap and admin-only: add the field to
  the existing response (teams `description`, presets `icon`, records
  research `title`), or add the route when the console legitimately needs it
  (assistants GET/PUT — full record incl. system prompt, `manage_options`-
  gated; PUT accepts the create handler's field names).
- **Promote the gatherer** — never re-implement a query in a controller;
  expose the wp-admin page's private gatherer as a public static seam.
- **Client-derive** for presentational data with no server source (tools
  `category` from the slug prefix; verb-prefix set falls back to "Core").
- REST callbacks return `rest_ensure_response(...)` (or WP_Error) — raw
  arrays break `->get_data()` consumers and are wrong for WP core.
- **Provider classes (bug class 12):** eager-require in `rest_console_bridges`
  when the provider's self-instantiation registers only admin-dormant hooks;
  lazy `load_provider()` in the controller when the constructor registers
  live tracking hooks. A missing-provider branch must return a 501
  `WP_Error` — never an `ok: true` empty catalogue or a `success => false`
  envelope (both mask the outage as "no data"; the canonical-envelope sniff
  flags the latter).

## Known-good baseline (2026-10-21 audit)

Verified contract-clean: settings, security, diagnostics, operations,
token-manager (credentials controller), orchestration-stats, pro-ops,
schedule-manager (+ presets), portability, approvals, hitl, chatProfile,
memory, threads, transcripts, toolShortcuts, slashCommands (chat drawer),
triggers, okf, workflow-builder, workflow-presets, records list, media
(wp/v2), config envelope. Re-audit only if one of these changes.

**2026-10-22 additions:** the features map (`GET /mcp-ai/v1/features` →
`WP_MCP_AI_Feature_Flags::all()`) now carries toolkit enable toggles —
`fnb`, `crm`, `project_management`, `calendar_booking` — and the console nav
+ route guards gate `/fnb`, `/crm`, `/pm`, `/calendar` on them (fail-open
when the map is absent). The dashboards catalogue (`/dashboards/schema`) is
server-filtered by
`WP_MCP_AI_Pro_Dashboards_REST_Controller::is_dashboard_available()` —
disabled toolkits (health/wellness, vitals, eca, law, cre, registration) are
omitted from the schema and their `/dashboards/{id}/data` returns 404
`dashboards_unavailable`; re-audit tests must seed the `wp_mcp_ai_settings`
toolkit flags (toolkits are default-off). `GET /mcp-ai-pro/v1/schedules` is
owned exclusively by the schedule CRUD controller (`{ ok, schedules, total }`);
the scheduled-result picker lives at `/schedules/selectable` (bare array) —
bug class 9.

**2026-10-24 contract change (#7193 — supersedes the "omitted" note above):**
`GET /mcp-ai-pro/v1/dashboards/schema` now ships a **`disabled` map**
(label/icon/description + the `wp_mcp_ai_settings` flag that gates each
dashboard) via the new `effective_flag_key()` seam shared with
`is_dashboard_available()` (vitals BC fallback preserved) — disabled
dashboards are described, not omitted, so the SPA can render a guided empty
state. The SPA client changed `DashboardsClient.schema()` → `catalogue()`
and returns both lists; `/dashboards/{id}/data` still 404s
`dashboards_unavailable` for disabled toolkits. Re-audit expectations that
assert the disabled dashboards are absent from the schema must now assert
their presence in `disabled` instead.

**2026-10-23 addition:** the chat-transcripts list envelope
(`GET /mcp-ai/v1/chat-transcripts`) now includes `storage_bytes` — the
aggregate byte size of the user's stored transcripts (repository
`WP_MCP_AI_Transcript_Repository::get_storage_bytes()`, seam
`WP_MCP_AI_REST::get_transcript_storage_bytes()`) — plus the Pro SPA
StatusBar prefers it over the same-origin localStorage meter (bug class 11).
Re-audit the transcripts client with this field in mind; it is absent on
older base-plugin installs (client falls back).

**Provider-loading pass (2026-10-23, same session as bug class 11):** four
console bridges degraded on the Complete console because their provider
classes were only loaded by the admin-gated `admin_sections` module —
workflow-presets (empty catalogue), workflow-builder node exec (501),
ops-overview webhooks (silent `array()`), command-center overview
(`success: false`). Fixed per the bug-class-12 rules above (eager for the
three inert-constructor providers, lazy `load_provider()` for the agent
command center whose constructor registers live tracking hooks); pinned in
`tests/rest-api/test-rest-console-bridges.php` with the eager-include
provider + end-to-end contract tests. Re-audit any bridge's PROVIDER class
load path when touching it, not just its routes.

**Registration pass (same-day follow-up):** the six Proposal 084 console
bridges (ops overview, workflow-presets, workflow-builder node exec, records,
dashboards, media-cc) were contract-clean but admin-gate-registered — the
live console 404'd all six (bug class 8). They now boot from the context-free
`rest_console_bridges` module and are pinned by
`tests/rest-api/test-rest-console-bridges.php`. When re-auditing any of them,
verify route *presence* (live index or `rest_get_server()->get_routes()`),
not just shape.

## Parallel delegation pattern

Split the client list into disjoint groups and spawn read-only audit
sub-agents; keep one coordinator for fixes + final gates.

- Disjoint **write** scopes if agents fix too; never let two agents edit the
  same file (e.g. the big `includes/class-wp-mcp-ai-rest.php`).
- Never run Docker PHPUnit concurrently (shared `wordpress_test` DB) — the
  coordinator runs the PHPUnit matrix at the end.
- Never run bundle rebuilds concurrently (shared `assets/dist/` +
  `dist/`) — coordinator rebuilds once after all spa-v2 edits.
- Agents verify with `php -l`, per-file phpcs, narrow `npx vitest run <file>`,
  `npm run typecheck`; they do NOT commit, push, or touch dist/.

## Validation matrix (coordinator)

- phpcs: `phpcbf <files>` then `phpcs --standard=phpcs.xml.dist
  --error-severity=1 --warning-severity=8 --report=summary <files>`; EXIT 0
  clean. Ignore false-positive warnings on `meta_key`/`tax_query`/
  `self::POST_TYPE` string literals. phpcbf edits the bind-mounted file —
  re-run `php -l` on the host afterwards.
- PHPUnit on **both** WP 6.9 and WP 7.1 (Docker commands in
  `mcp-ai-wpoos-test-suite`; use the `mcp-ai-wpoos-vendor` volume).
- spa-v2: `npm run typecheck`, `npx vitest run` (full), `npm run lint:a11y`,
  then `npm run build` (esbuild).
- Console: `npx tsc --noEmit`, `npx vitest run`, `npm run build` (vite) in
  `examples/nvoos-complete-velocity`; commit the hashed dist renames.
- Tests that need real rows must create real posts (tools validate via
  `get_post()`); zero-network envelopes are the norm.

## Gotchas

- Contract tests that bake in phantom fields **mask** real drift — rewrite
  them from the server's actual response, not the client's types.
- `_fields` filtering is applied after caching in the assistants/tools list
  handlers; don't assume a filtered key exists in the cached shape.
- Two token-manager controllers coexist (`/token-manager/*` used by the SPA
  vs `/users/{id}/token-tier`) — don't patch the wrong one.
- **The test bootstrap's Pro fallback autoloader masks bug class 12.**
  `wp_mcp_ai_register_pro_classmap_fallback_autoloader()` (in
  `tests/bootstrap.php`) resolves any `WP_MCP_AI_*` class from
  `addons/pro/includes` on demand, so a controller's `class_exists()` guard
  loads the provider file in PHPUnit even when production never would — the
  end-to-end handler test passes while the live console still degrades. Pin
  the EAGER include instead (scan `get_included_files()` right after the
  registry boot, before any handler runs) and prove the pin by reverting the
  production fix (`git stash push -- <file>`) and watching it fail.
- PHPUnit 11 requires data providers to be `public static` — a non-static
  provider errors at suite construction, not at the test body.
