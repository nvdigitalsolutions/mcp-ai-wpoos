---
name: mcp-ai-wpoos-laravel-runtime
description: "Operational guide for the NV oOS Laravel runtime — docker/laravel/app-skeleton vs the gitignored app/ bind mount, the oos-laravel-* containers (vendor is a volume symlinked into lib/), the three PHPUnit suites and their baselines, WordPress probing without WP-CLI, the WP-role authorization model, and the Laravel gotchas. Use when changing docker/laravel/**, lib/laravel-adapter/**, or lib/core/**, or when a Laravel route, migration, or test fails."
---

# NV oOS — Laravel runtime ops

The Laravel runtime is the second host for the same `lib/core` engine that the
WordPress plugin runs. The console (`examples/nvoos-complete-velocity`, and the
`spa-v2` bundle) is the same React app against either backend.

## 1. Topology (verified 2026-11-13)

```
oos-laravel-app     8001 -> 8000   PHP-FPM/Octane app
oos-laravel-worker                 queue worker
oos-laravel-scheduler              schedule:work (every-minute tick: nvoos:run-schedules, fire-triggers, sample-health)
oos-laravel-db                     MySQL
oos-laravel-redis                  cache + queue
oos-wp              8000           WordPress runtime (the other host)
oos-wp-db                          WordPress MySQL
```

Wrap every `docker exec` in `MSYS_NO_PATHCONV=1` on Windows Git Bash.

Mounts for `oos-laravel-app` (from `docker inspect`):

| Source | Destination |
|---|---|
| `docker/laravel/app` | `/app` |
| volume `oos-laravel_laravel_vendor` | `/app/vendor` |
| volume `oos-laravel_laravel_storage` | `/app/storage` |
| **repo root** | `/workspace/oos` |

**The vendor tree is a Docker volume, not the host directory.** On the host,
`docker/laravel/app/vendor` is empty — do not try to `cp` into it. Inside the
container:

```
vendor/nvoos/core            -> /workspace/oos/lib/core/
vendor/nvoos/laravel-adapter -> /workspace/oos/lib/laravel-adapter/
```

Both are symlinks into the mounted repo, so **edits under `lib/` are live
immediately** — no `composer update`, no rebuild. This is why a `lib/core`
change shows up in `php artisan test` without any install step.

## 2. Source of truth

- `docker/laravel/app-skeleton/` — **committed**. This is the source.
- `docker/laravel/app/` — **gitignored** bind mount. This is the runtime.

Never edit `app/` and expect it to survive. The cycle is:

```sh
cp -R docker/laravel/app-skeleton/app/.      docker/laravel/app/app/
cp -R docker/laravel/app-skeleton/routes/.   docker/laravel/app/routes/
cp -R docker/laravel/app-skeleton/database/. docker/laravel/app/database/
cp -R docker/laravel/app-skeleton/tests/.    docker/laravel/app/tests/
cp -R docker/laravel/app-skeleton/fixtures/. docker/laravel/app/fixtures/

MSYS_NO_PATHCONV=1 docker exec oos-laravel-app php artisan optimize:clear
MSYS_NO_PATHCONV=1 docker exec oos-laravel-app php artisan octane:reload   # long-running workers
```

If a new file is missing at runtime, check the copy step first — the skeleton
is what CI sees, `app/` is what the container sees.

**`optimize:clear` does not reach a warm Octane worker.** A route or panel added
to the skeleton appears in `php artisan route:list` but answers **404 over HTTP**
until `octane:reload` runs — the worker still holds the old route table. Reload,
then re-`curl` the path before debugging the route definition itself.

**Never `rm -rf docker/laravel/app` to force a clean sync.** The directory is
tracked by a single `.gitkeep` while its contents are gitignored, so deleting
the tree stages a deletion of that file — restore it with
`git checkout -- docker/laravel/app/.gitkeep`. A full re-copy also wipes
`app/.env`, and every later `php artisan test` then prints
`file_get_contents(/app/.env): Failed to open stream` warnings that read like
failures. Restore it with
`docker exec -w /app oos-laravel-app sh -lc 'cp -n .env.example .env && php artisan key:generate --force'`.

**Contract fixtures** live in `app-skeleton/fixtures/` and are copied the same
way. `FixtureReplayTest` reads `base_path('fixtures')`, so a fresh checkout in
the container needs the copy above. `php artisan fixtures:capture` writes a
fresh set (it defaults to `base_path('fixtures')`; the repo mount is read-only
inside the container, so capture there and copy back into the skeleton to
commit). `NVOOS_FIXTURES_DIR` overrides the path.

The `.github/workflows/laravel-app.yml` job runs the whole suite on SQLite with
the fixtures already in place — this is the only CI coverage for `docker/**`.

## 3. Validation matrix

| Suite | Command (cwd) | Baseline (2026-11-14) |
|---|---|---|
| App | `docker exec -w /app oos-laravel-app php artisan test` — with `-e NVOOS_PM_FIXTURE=/workspace/oos/tests/fixtures/pm/tools.json -e NVOOS_PM_WRITES_FIXTURE=/workspace/oos/tests/fixtures/pm/writes.json`, or the two PM parity tests fail with "Empty data set" | 694 passed / 4070 assertions (2026-11-15, after the posture defaults landed) |
| Adapter | `php -d extension=fileinfo vendor/bin/phpunit` (`lib/laravel-adapter`) | 180 passed / 678 assertions, **no failures** (2026-11-15, after the two stale status tests were corrected). Run it with **host** PHP: the container's `vendor/nvoos/core` symlink is Windows-absolute and does not resolve on Linux |
| Core | `php -d extension=fileinfo vendor/bin/phpunit` (`lib/core`) | 1751 tests / 6185 assertions (2026-11-15, after the harness port, the chat-path wiring, the PII parity work, server-side system-prompt ownership, the necessity gate and the tool preset catalogue); green apart from the no-coverage-driver warning |
| Lint (Laravel trees) | `php vendor/bin/phpcs --standard=phpcs-laravel.xml.dist --error-severity=1 --warning-severity=8` (repo root) | **807 files, 0 errors** (2026-11-15, after the posture defaults landed). Covers `lib/core/src`, `lib/core/tests`, `lib/laravel-adapter/**` and `docker/laravel/app-skeleton/**`. `lib/core` was added last; it was reformatted from tabs to spaces in #7805. The two indentation sniffs are ruleset-wide excludes, so tabs are not actually forbidden in `lib/core` — see plan §22. If the run is red, check the anonymous-class sniff (`new class (`) first: three such errors reached `alpha-working` via #7813 and #7816 and were fixed in the posture PR — see plan §26 |
| Console smoke (G1) | `npm run test:e2e` (`examples/nvoos-complete-velocity`) | 6 passed / ~30 s (2026-11-14). Builds `.e2e-dist/` (`--mode laravel`) and serves it through `scripts/serve.mjs`; the committed `dist/` is untouched. Needs the stack up and `localhost:4318` in `SANCTUM_STATEFUL_DOMAINS` |

**The console smoke is the only gate that exercises both halves at once.**
It signs in through the branded login (Sanctum cookie + `X-CSRF-TOKEN` via the
proxy), asserts `/api/v1/features` reports `backend: laravel` with an **empty
`endpoint_gaps`**, renders the five T0 pages and the chat composer, and proves
**zero WordPress requests** across the sweep. Run it before tagging a release
that touches `docker/laravel/**`, `lib/laravel-adapter/**` or the console — it
is a local/pre-release gate, not a CI job (the stack is too heavy for
`ubuntu-latest`). If the login answers `419 session_required`, the console
origin is missing from `SANCTUM_STATEFUL_DOMAINS` and the app container must
be recreated.

**CI does run the app suite.** `.github/workflows/laravel-app.yml` installs the
skeleton on SQLite and runs `php artisan test`, so an app-suite regression does
surface on the PR (the check is `App suite (SQLite)`). The remaining gates on
`docker/**` are PSR-12 via `phpcs-laravel.xml.dist` and the licence boundary.
Still quote your local counts in the PR — CI proving "not broken" is weaker
evidence than a count you actually saw.

Run the style gate exactly as CI does, otherwise line-length warnings that CI
ignores will look like failures:

```sh
vendor/bin/phpcs --standard=phpcs-laravel.xml.dist --error-severity=1 --warning-severity=8
```

`Generic.Files.LineLength` is severity 5, so `--warning-severity=8` is what
makes the check pass on the pre-existing long lines in `AppServiceProvider`.

Never run two `docker exec … phpunit` at once — the WordPress suites share the
`wordpress_test` database and collide.

### Running the suites on a bare host or in a fresh worktree

A throwaway worktree has no `.env`, and `artisan test` does not run the suite
in-process. Both traps produce mass failures that read like a code break:

1. **`php artisan test` spawns a child PHP that does NOT inherit `-d
   extension=…`.** On a host whose `php.ini` omits `fileinfo`, the parent boots
   fine and then every storage/fixture test dies with `Class "finfo" not found`
   (64 of 800 failed this way — the suite looks broken). Run
   `vendor/bin/phpunit` directly instead. The DLLs are usually installed even
   when the ini omits them — prove it before reaching for
   `--ignore-platform-req`:
   `php -d extension=fileinfo -d extension=intl -d extension=zip -r "var_dump(extension_loaded('intl'));"`
2. **No `.env` means `MissingAppKeyException`**, and every test touching
   `encrypt()` (e.g. `Tenant`'s `licence_key`) plus a cascade of unrelated
   assertions fail together. Seed one first.

```sh
# from docker/laravel/app-skeleton in a fresh worktree
cp .env.example .env && php artisan key:generate --force
php -d extension=fileinfo -d extension=intl -d extension=zip \
  vendor/bin/phpunit            # NOT `artisan test`
```

`composer install` on such a host still needs
`--ignore-platform-req=ext-fileinfo --ignore-platform-req=ext-intl
--ignore-platform-req=ext-zip` — the CLI does not see `-d` flags.

The two `NVOOS_PM_*_FIXTURE` overrides are a **container-only** requirement.
`ProjectManagement*ParityTest` falls back to
`dirname(__DIR__, 5).'/tests/fixtures/pm/…'`, which from
`docker/laravel/app-skeleton/tests/Feature` is the repo root — correct in CI and
on a host checkout, but not inside `/app`, where only the skeleton is mounted.

## 4. Probing WordPress without WP-CLI

`oos-wp` has no `wp` binary. Bootstrap directly:

```sh
MSYS_NO_PATHCONV=1 docker exec oos-wp php \
  /var/www/html/wp-content/plugins/mcp-ai-wpoos/<probe>.php
```

The probe starts with `require '/var/www/html/wp-load.php';`. The repo root is
mounted at `/var/www/html/wp-content/plugins/mcp-ai-wpoos`, so write the probe
into the repo root (it is visible immediately), run it, then delete it before
committing.

- Bootstrap costs ~30 s (the plugin loads every module). Budget 2–4 minutes,
  not 30 seconds.
- Filter output: `| grep -vE "Notice|Warning|Deprecated|NV oOS|WP MCP AI"`.
- **Restore anything you touched** — options, transients, posts. A dev DB is
  shared state; leave it as you found it.
- The WP suite cannot run locally when the root `vendor/` is `--no-dev`. Use
  the throwaway shim in `mcp-ai-wpoos-test-suite/reference/test-environments.md`
  (§ "No-dev vendor shim"), and delete it before committing.

## 5. Authorization model (Proposal 090-g Phase 5)

Two scopes, one vocabulary. **Enforcement is the point:** before the `can:` gates landed, every authenticated user could `POST /api/v1/assistants`, rewrite settings, retry dead-letter jobs or export the catalogue — the console hid the buttons, the API did not care. Hiding a button is UX, not authorization.

| Scope | Storage | Values |
|---|---|---|
| Site | `users.role` | `subscriber`, `contributor`, `author`, `editor`, `administrator` |
| Team | `team_members.role` | `owner`, `admin`, `manager`, `member`, `viewer` |

- `CapabilityMap` owns the WP role → capability map; `delete_others_posts`
  separates editor from author.
- `Gate::before` answers site abilities and returns **`null`** (never `false`)
  so host policies still decide.
- Admin route groups carry `can:manage_options`; content `can:edit_posts`;
  media `can:upload_files`. The console nav reads the same capability, so the
  button and the endpoint cannot disagree.
- `TeamAccess`: non-member → **404** (no id disclosure), member without the
  ability → **403**, site administrator overrides.
- `/org` routes are **outside** `manage_options` on purpose — membership is
  the authorization there. Moving them inside breaks open-team creation.
- Legacy `role = 'admin'` is normalised to `administrator`.
- **CSRF:** the console sends `X-CSRF-TOKEN` (from `GET /api/v1/session/nonce`) in both the fetch wrapper and the jQuery prefilter. `X-XSRF-TOKEN` is wrong here — Laravel *decrypts* it and it only accepts the cookie value. The WordPress-shaped `AcceptWpNonce` shim is deleted; a bare `X-WP-Nonce` gets `419`. WordPress ignores the extra header, so one client build serves both hosts.
- **Read-only profile:** `ReadOnlyToolGuard` binds as a **monotonic deny-only** guard after every `tools/pre_execute` listener (`NVOOS_READ_ONLY`). It denies `write`/`state_changing`/`mutating`/`destructive`/`external_side_effect` and, stricter than the WordPress chat profile, undeclared tools unless `block_undeclared: false` or an allowlist is set.

## 6. Gotchas (each cost a cycle)

- **`Cache::store('')` throws** `Cache store [] is not defined` — only `null`
  coalesces to the default driver. Store `null` for "default".
- **Migrations must guard and backfill.** `Schema::hasColumn` before adding, and
  promote rows still on the default — a dev DB can grow a column out of band.
- **Route order**: `/assistants/export|import` before `/assistants/{assistant}`;
  `/paper-store/search` before `/paper-store/{collection}`.
- **Paper Store paths are constraints, not string cleaning** — reject invalid
  segments (`.`, `..`, separators) at the route/store boundary; normalising a
  caller's id turns a traversal attempt into a successful write elsewhere.
- **WordPress is always MySQL, Laravel may not be** — wrap every
  `json_extract()` in `json_unquote()` or the raw value comes back quoted and
  every filter silently misses.
- **Octane**: a bound (not singleton) repository is rebuilt per request; do not
  promote stateful readers to singletons or a long-lived worker serves stale
  data.
- **Tests hitting admin routes** need `User::factory()->admin()->create()` —
  the default factory user is a subscriber and now gets 403. Use
  `User::factory()->editor()` for the 403 side of the same test.
- **The app cache is Redis** (`cache.default=redis`), not `database` — a
  `cache()` lookup in a probe hits the real store.
- **`lib/core` is MIT, not GPL.** A new file under `lib/core/src/` declaring
  `SPDX-License-Identifier: GPL-3.0-or-later` fails the **Licence boundary
  intact** check (`lib/core/src contains files declaring GPL (package is
  MIT)`). Copy the header from a neighbour: `@license MIT` +
  `SPDX-License-Identifier: MIT`. The same gate requires `includes/` to be
  GPL-only and `addons/pro/includes` + `addons/canvas` Proprietary-only.
  `bin/**` and `docker/**` are not scanned. Run
  `bash bin/check-license-boundary.sh` before pushing.
- **`EndpointCapabilities` flips a console key the moment its route exists.**
  There is no separate registration: `EndpointCapabilities::ROUTES` maps a key
  to a route fragment, and the map is computed from the live router. Two
  consequences: move the key between the available/unavailable lists in
  `EndpointCapabilitiesTest` (it asserts both directions), and re-read the
  total with
  `php artisan tinker --execute='$m=App\Support\EndpointCapabilities::map(); echo count($m);'`
  (single quotes — a double-quoted `$m` is interpolated by the shell)
  before updating a tracker that cites a true/false count.

- **A console key now carries two facts, not one.** The boolean map says whether
  this runtime serves the key right now; `EndpointCapabilities::REASONS` says
  which *supply class* the key belongs to. Only `route` keys are meant to be
  served here, so `manifest()['gaps']` must stay `[]` — a non-empty list means a
  route was dropped or a key was never classified. The classification is
  duplicated in `contracts/console-endpoints.json` because the console's Vitest
  suite reads that file; the contract test fails if the two copies disagree.
  Vocabulary: `route`, `runtime-adaptable`, `not-installed`, `toolkit-shaped`,
  `connected-service`, `external-product`. `GET /api/v1/features` exposes it as
  `data.endpoint_contract_version`, `data.endpoint_reasons` and
  `data.endpoint_gaps`.
- **WordPress now emits the same manifest.**
  `WP_MCP_AI_Endpoint_Capabilities` (required by `includes/bootstrap/loader.php`)
  is the WordPress twin: `GET /mcp-ai/v1/features` returns `endpoints`,
  `endpoint_contract_version: 2`, `endpoint_reasons`, `endpoint_gaps`,
  `backend: wordpress` and `contract: mcp-ai/v1`, computed from
  `rest_get_server()->get_routes()` with a regex-aware literal-head matcher.
  WordPress overrides one classification (`mediaCc` → `route`, because the
  health snapshot is computed locally; `chatChannels` stays `toolkit-shaped`)
  and downgrades Pro-supplied keys to `not-installed` when
  `WP_MCP_AI_PRO_FILE` is undefined, so a Base install has no gaps. The
  console's `isWordPress` matrix is now only a fallback for older backends.
- **Connected apps are discovered, not ported (090-i Phase 4).**
  `App\Support\ConnectedApps\ConnectedAppRegistry` probes configured product
  bases (`oos.connected_apps.*`: `contentGraph`, `docsHub`, `mediaStudio`,
  `documentEditor`), caches the result (`oos.connected_apps_ttl`, 300 s),
  resolves the hostname and validates **every** address (private/reserved
  ranges refused; CGNAT/benchmarking/documentation ranges are checked
  explicitly because PHP's filter flags miss them), pins the validated address
  with `CURLOPT_RESOLVE`, follows no redirects, and sends an optional per-app
  `token` as a bearer credential. It reports the resolved base through
  `data.endpoint_urls` while flipping the key. Additive: a console that ignores
  `endpoint_urls` behaves exactly as before. The probe accepts 2xx/3xx and
  401/403 as *reached*; a 404/5xx/transport error leaves the key unavailable
  with the `external-product` reason (not a gap).
  **WordPress has the twin now** (`WP_MCP_AI_Connected_App_Registry`, 090-p
  W6b): option `wp_mcp_ai_connected_apps` or the same-named filter, transient
  TTL `wp_mcp_ai_connected_apps_ttl`, HTTPS required (loopback allowed only in
  the `local` environment), the host resolved **once** and those same addresses
  pinned with `CURLOPT_RESOLVE`. `/features` merges it with
  `WP_MCP_AI_Endpoint_Capabilities::apply_connected_apps()`, which also flips
  the matching `features` flag so the two maps agree. The console-side
  same-origin mount is `serve.mjs`'s `/connected/<key>/*`, advertised as
  `connected_proxy: true`.
- **Response envelopes differ per client — read the client.** Most Laravel
  controllers use `{success, data}`, but `WorkflowBuilderClient` reads bare
  arrays/objects (WordPress's `rest_ensure_response()` shape),
  `WorkflowPresetsClient` reads the Pro `{ok, …}` envelope and treats
  `ok === false` as an error, and `DashboardsClient` reads top-level
  `dashboards`/`disabled`/`data`. Serving the wrong one yields a silent empty
  page, not a 4xx. See `mcp-ai-wpoos-spa-rest-contract`.
- **`AbstractTool::success()` nests the payload under `data`.** A tool that
  returns `success( '…', [ 'uuids' => … ] )` produces
  `['success' => true, 'message' => …, 'data' => ['uuids' => …]]`, so a
  workflow/run assertion reads `output.data.uuids`, not `output.uuids`.
- **PHPUnit data providers run eagerly, even under `--filter`.** The two
  `ProjectManagement*ParityTest` cases fail with "Empty data set" on any
  filtered run unless both `NVOOS_PM_*` fixture env vars are passed — they are
  not a regression you introduced.
- **A missing `assistant` breaks agent nodes, not the run.**
  `AgentNodeRunner` resolves `agentId` as a record id or falls back to the
  default assistant; with neither it throws a typed message the executor
  records against that node. Tests that only exercise tool nodes need no
  assistant fixture.
- **Never write a literal `*/` inside a PHP docblock.** `` `*/SKILL.md` `` in a
  comment closes the block early and the rest of the line becomes code —
  `ParseError: syntax error, unexpected token "`"`, reported at the *caller*
  (`Composer\Autoload\…->loadClass`), not at the file. Describe paths as
  "one folder per skill, each holding a SKILL.md" instead.
- **The scheduler is not running by default.** `docker/laravel/entrypoint.sh`
  starts `queue:work redis` for the `worker` role; nothing runs
  `schedule:work`. Anything registered via `->withSchedule()` in
  `bootstrap/app.php` (currently `nvoos:fire-triggers`, every minute) is
  therefore a no-op in the compose stack. Verify with
  `php artisan schedule:list` (shows the next due time) and
  `php artisan nvoos:fire-triggers --dry-run` (lists due triggers without
  firing). A deployment that relies on cron-style triggers must run the
  scheduler container or a `schedule:run` cron.
- **A `composer.lock` written inside the container bakes absolute dist paths.**
  Running `composer install` in `oos-laravel-app` rewrites the nvoos path
  repositories to `dist: /workspace/oos/lib/core`, which exists nowhere else, so
  the next install outside the container dies with
  `Source path "/workspace/oos/lib/core" is not found`. The committed lock must
  read `../../../lib/core` and `../../../lib/laravel-adapter`; verify with
  `grep -c /workspace/oos docker/laravel/app-skeleton/composer.lock` → `0`.
- **Filament's published assets are not gitignored upstream.** `filament:assets`
  — run by the skeleton's post-install script and by `entrypoint.sh` — writes
  ~4.5 MB to `public/{css,fonts,js}/filament`, which surfaces as three untracked
  directories, one `git add -A` away from committing binaries. Only
  `public/.htaccess`, `public/index.php` and `public/robots.txt` are tracked
  under `public/`; keep `/public/css/filament`, `/public/fonts/filament` and
  `/public/js/filament` in the skeleton `.gitignore`.

## 7. Plan map

`docs/project/plans/`:

| Plan | State |
|---|---|
| `090-…-spike…` | Implemented, dev-verified |
| `090-b` admin surfaces | Phases 0–3d shipped; 4.5–4.7 toolkit service + research shipped (#7619–#7663); Phase 5 entitlement/read-only shipped (#7664–#7666) |
| `090-c` research screen | Shipped — `POST /api/v1/pro/toolkits/{slug}/research` wired to the provider router (#7658), WordPress parity via `ToolkitResearchService` (#7663) |
| `090-d` tool port | Cluster 1 in progress: `verify_information`, `validate_tool_schema`, create/update/delete task/project + bulk import, and the MJML builder ported; dependency labels corrected by measurement (#7690) |
| `090-e` CPT/CCT schema | Design only; the unit of work is a family schema, not a tool |
| `090-f` storage bridge | Paper Store contract + task/project write path + the 8 missing runtime tables shipped (#7669–#7679); parity gates complete (#7655) |
| `090-g` identity/org | Shipped — 6 phases, 10/10 gates |
| `090-h` workflow engine | Shipped — H1 (33-preset catalogue), H2 (`mcp_ai_workflow` CRUD + graph validation), H3 (Kahn executor + `nvoos_workflow_runs` + node runner + `trigger → execute`). All four engine keys report `true`. `POST /orchestration/triggers/{id}/fire` runs a trigger now; `nvoos:fire-triggers` handles `cron_schedule` and is registered `everyMinute()->withoutOverlapping()` — but **only fires where the Laravel scheduler runs** (`schedule:work` or a `schedule:run` cron), and the compose worker runs `queue:work` alone |

## 8. v1.2.20 storage and toolkit surfaces

- **Paper Store** — `PaperStoreInterface` + `JsonPaperStore`: `{root}/{collection}/{id}.json`, atomic temp-file + `rename`, store-owned stamps (`id`, `collection`, `type`, `schemaVersion`, `created_at`, `updated_at`), rejects invalid path segments (never normalises), `query()` returns `{records, total}`. The old `nvoos_paper_records` table is dead.
- **Runtime tables** — `nvoos_schedules`, `nvoos_schedule_runs`, `nvoos_triggers`, `nvoos_approvals`, `nvoos_files`, `nvoos_posts`, `nvoos_security_snapshots`, `nvoos_toolkit_records`, `nvoos_workflow_runs` ship as migrations. A fresh `php artisan migrate` must create them; if a surface says "table not found", the migration did not run.
- **Toolkit manifests** — `ToolkitManifest` (`lib/core`) validates `addons/pro/includes/toolkits/manifests/*.json`; the Laravel `Pro\ToolkitsController` serves the same `mcp-ai-pro/v1/toolkit-manifests` envelope as WordPress.
- **Profession catalogue** — `ProfessionRepositoryInterface::catalogueVersion()` + `CachedProfessionRepository` key the cache by version; a catalogue change bumps the version and invalidates without a manual purge. `save_profession` writes through `ProfessionWriteInterface` on WordPress and is deliberately **not** registered on Laravel.
- **MJML** — `MjmlTemplateBuilder` (`lib/core`) builds markup; `compile()`/`validate()` stay WordPress-side because they call the Media Worker sidecar.
- **Workflows** — `mcp_ai_workflow` content records with WordPress's meta keys (`_wp_mcp_ai_workflow_graph`, `_version`, `_tags`), read tolerantly (WP stores graph/tags as JSON *strings*; this runtime writes native JSON). `lib/core/Application/Workflow` holds the executor and the no-eval template/condition resolver. Runs live in `nvoos_workflow_runs`.
- **Agent Skills** — `GET /api/v1/skills` serves `{success, skills, total}` from `config('oos.skills.path')` (default `storage/app/skills`, env `NVOOS_SKILLS_PATH`); one folder per skill with a `SKILL.md`. Read-only: the WordPress manager (install/replace/delete) is deliberately absent, so writes 404. `SkillRegistry::parseSkillMd()` returns name, description, licence and compatibility; the parser is in core and shared.
- **OKF knowledge** — `GET /api/v1/pro/okf/*` (`bundles`, `concepts`, `concept`, `search`, `skills`) reads `config('oos.okf.path')` (default `storage/app/knowledge`, env `NVOOS_OKF_PATH`) through core's `OkfReader` + `FilesystemBundleRoot`. `features.okf` is true and `runtime-config.ts` builds the endpoint for both runtimes, so the chat drawer works. `/skills` is empty by design (no grant store). Descriptors report `conformant`/`issue_count` from parse + broken-link checks only — a documented subset of the WordPress validator.

## 9. Porting a WordPress catalogue to this runtime

Large static catalogues (workflow presets, dashboards, professions) are **data**, and hand-transcribing them guarantees drift. Generate instead of copying:

1. The WordPress catalogue is a static PHP class guarded by `ABSPATH` and calling `__( )` / `sanitize_key()`. Stub those three in a throwaway script, `require_once` the class, and `json_encode` its getter with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
2. Commit the generator (`bin/generate-laravel-*.php`) next to the JSON. A re-run must produce a reviewable diff, so `ksort()` the top-level maps.
3. Load it through a small `App\Support\*Catalogue` class that parses once per process and exposes `all()` / `find()` / `summaries()`. Missing file = empty catalogue, not a 500.
4. **Carry the licence.** If the source is Pro-licensed and the app is MIT, put a `_license` field in the JSON and state the split in the loader's docblock; `docker/**` is outside `check-license-boundary.sh`, so this is on the author, not CI.

Worked example: `bin/generate-laravel-workflow-presets.php` → `resources/workflow-presets.json` → `App\Support\WorkflowPresets`.

## 10. v1.2.21 runtime surfaces (090-b/i/h/j)

Everything below landed **after** the v1.2.20 section, so treat §8 as the storage baseline and this as the current surface map.

- **Remote sites** — table `nvoos_remote_sites`; `RemoteSiteStore`. Columns: `id` (ULID), `name`, `url`, `connection_type`, `auth_type`, `config` (non-secret JSON), `credentials` (sealed JSON — one `Crypt` ciphertext per field), `enabled`, `last_tested_at`, `last_test_status`. **Three invariants:** no read returns a credential (uniform mask + `has_credentials`; a varying mask leaks), `credentialsFor()` is the only opener (runtime-only), and `config` may not hold a secret. The console surface serves exactly two *verifiable* types — `wordpress` (`GET {site}/wp-json/wp/v2/users/me`, Basic auth) and `mcp_server` (`McpAppClient` handshake + `tools/list`) — and answers an unsupported type with `{success:false, message}` at HTTP 200, not a 500. Vendor types are deliberately absent: a Test button that can only fail reads as an outage.
- **QuickBooks Online MCP** — `connection_type: quickbooks_mcp`, default `https://ai-inc.quickbooks.intuit.com/v1/mcp` (or a self-hosted Streamable HTTP wrapper), with Intuit client ID/secret (encrypted), realm, sandbox/production and OAuth/bearer/basic/custom-header auth. Intuit apps are **static clients**, so the OAuth client supports pre-registered confidential clients: HTTP Basic token/refresh per RFC 6749 §2.3.1, case-preserving client IDs, refresh-secret injection, no DCR. WordPress reference: `docs/quickbooks-mcp-connect.md`.
- **MCP Apps** — `GET|PUT /mcp-apps/{assistant_id}`. The response is **top-level** (`success`, `assistant_id`, `apps`, `app_count`) because `McpAppsResponse` reads the body directly — wrapping it in `{success, data}` renders an empty panel while unit tests pass. Storage mirrors Pro (`_wp_mcp_ai_mcp_apps`, `MAX_APPS_PER_ASSISTANT = 10`); the write path whitelists eight fields and never writes `token`/`oauth_data`.
- **Shared MCP policy** — `McpHandshakePolicy`, `OAuthDiscoveryCandidates`, `WwwAuthenticateChallenge` and `McpRequestEnvelope` live in `lib/core` (`Domain/Mcp`). The client tries `server/discover` first and falls back to `initialize` on a legacy rejection; a hinted session tries `initialize` first and falls forward on a stateless rejection. **No retry on 401/429/timeout/500** — that is what stops a throttled server being re-probed, and the hint is a constructor flag, not a cache.
- **Token manager** — `GET /api/v1/token-manager/overview` plus `revoke`, `delete`, `lift` over `personal_access_tokens`. The token **hash is never selected**; only metadata is read (a test captures the hash and asserts it is absent from the payload). `AuthProvider::issueCredential()` gates on `manage_oos` — that gate **did not exist** until this window, so credential issuance was permanently forbidden; it is now in the administrator capability set.
- **Command centre + telemetry** — the overview route reads dedicated telemetry stores (`nvoos_*` migrations) rather than scanning tables at request time; the schema test pins the telemetry and token tables. Nav items whose endpoint is not served are hidden client-side.
- **Slash commands** — `SlashCommandParser` + `ToolShortcutNormaliser` in `lib/core`; the Laravel surface parses and serves shortcuts. Tool shortcuts are shared; the full WordPress command *library* is not ported (deliberate, #7744).
- **Tool readiness (090-M)** — `lib/core/resources/tool-readiness.php` declares **223** classes with a closed vocabulary; `Application\Tool\ToolReadiness` classifies (`classify`, `isServable`, `annotations`, `hash`, `violations`); `ManifestToolRegistrar` registers only servable tools with the constructor resolved from the container; `bin/audit-tool-readiness.php` checks completeness, integrity, shadowing and probe-tier vocabulary and runs in the `Tool Readiness` CI job. **Do not add a tool to a registrar by hand** — add it to the manifest. Nineteen prompt-builder stand-ins stay unregistered because they shared a real tool's slug and returned a synthetic `success`.
- **Workflow executor + triggers** — `WorkflowExecutor` (`lib/core`) walks the graph in **Kahn topological order**, one node at a time, fail-fast (the first failure names itself and downstream nodes record `skipped`). Node types: `tool`, `condition`, `agent`, `delay`, plus input/output pass-throughs; an unknown type without `toolSlug` is a no-op, so a WordPress-authored graph still runs. `WorkflowRunner` is shared by `POST /api/v1/orchestration/triggers/{id}/fire` and `php artisan nvoos:fire-triggers` (`everyMinute()->withoutOverlapping()`) so both produce the same run row (`source` = `manual`/`trigger`, plus `trigger_id`). `config.cron` beats `config.schedule` (WP interval name or five-field expression); `config.every_minutes` is the fallback and an unparseable schedule is simply never due.
- **Endpoint availability contract** — 45 console keys, each reporting route-backed vs blank **with a reason** when it is not; `/features` reports the true/false counts (34/11 after OKF, 33/12 after skills). The contract is guarded on both CIs (`spa-v2/src/api/config.ts` ↔ `EndpointCapabilities::ROUTES`) — adding a route without the key (or the reverse) fails. Adapter migrations are proven to run in the app; do not add a table that only exists in the dev DB.
- **Harness storage** — `HarnessProfileProviderInterface` + `nvoos_harness_trace_records` (one row per assistant, `TracePolicy` retention 10–200). `NullTraceStore` is the default when no storage is bound.
- **Media ops** — health comes from the Media Worker, optimisation is proxied through it, and a sweep is scheduled; the Pro settings matrix must not report `installed` when the worker is unreachable.
- **Assistant + console extras** — `POST` assistant test endpoint, blueprint catalogue + install-as-assistant, advanced assistant-editor fields, dashboards route, chat-client route.

### Gotchas that cost cycles in this window

- **A top-level response wrapped "for consistency" breaks the client silently** (MCP apps). Check the SPA client, not the convention.
- **`WP_Meta_Query` wraps `LIKE` operands in `%…%`.** `ContentStore::likePattern()` must wrap them too; the port surfaced this only because a list tool returned zero rows.
- **`per_page` resets to the default, it does not clamp.** Clamping answers a different page size for the same request; WordPress resets, so the port resets.
- **`manage_oos` had no definition.** A Gate that is missing denies everything forever — assert issuance through the real path, not a fixture insert.
- **A static-client OAuth provider cannot use DCR.** Pre-registered clients mean HTTP Basic token requests and case-preserving client IDs.
