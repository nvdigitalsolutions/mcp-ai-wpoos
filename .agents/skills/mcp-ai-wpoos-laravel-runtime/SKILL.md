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
| App | `docker exec -w /app oos-laravel-app php artisan test` — with `-e NVOOS_PM_FIXTURE=/workspace/oos/tests/fixtures/pm/tools.json -e NVOOS_PM_WRITES_FIXTURE=/workspace/oos/tests/fixtures/pm/writes.json`, or the two PM parity tests fail with "Empty data set" | 472 passed / 3117 assertions (after the OKF drawer) |
| Adapter | `php -d extension=fileinfo vendor/bin/phpunit` (`lib/laravel-adapter`) | 175 passed / 640 assertions (after #7679) |
| Core | `php -d extension=fileinfo vendor/bin/phpunit` (`lib/core`) | 1264 tests / 4537 assertions (after the skills catalogue); green apart from the no-coverage-driver warning |

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
