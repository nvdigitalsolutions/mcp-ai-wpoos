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

**`oos-laravel-app` is bound to ONE checkout, not to "the repo".** In a worktree
the container usually serves a *different* checkout, so "the container runs my
code" is an assumption, not a fact — read the mounts first:

```sh
MSYS_NO_PATHCONV=1 docker inspect oos-laravel-app \
  --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}'
```

To actually browse the code in this worktree, add a **second** app container
instead of editing the running one: copy the env from
`docker/docker-compose.laravel.yml`'s `x-spike-environment`, change `APP_URL`
and `SANCTUM_STATEFUL_DOMAINS` to the new port, add `-p 8002:8000 --network
oos-laravel_default`, and mount this worktree's `docker/laravel/app` at `/app`
plus the shared `laravel_vendor` / `laravel_storage` volumes. Two things a
freshly built `docker/laravel/app` lacks, and neither fails loudly — the panel
just renders unstyled or 500s: the published Filament assets (`public/css`,
`public/js`, `public/fonts`) and Octane's `public/frankenphp-worker.php`. Copy
them from a checkout that has run `filament:assets`, or run it in the container.

Local login is `spike@example.com` / `password` (`nvoos:spike-token` creates that
user; `DatabaseSeeder::seedOperatorMembership()` attaches administrators to the
operator estate, which is what makes `/ops` reachable at all).

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

**`docker compose up` binds the checkout it is run from, not the one already
running.** The compose file's volumes are relative (`../:/workspace/oos`,
`./laravel/app:/app`) and Compose resolves them against the compose file's own
directory. Run `up` from a linked worktree and Docker recreates the container
pointing at *that* worktree — silently, with no error, and the container stays
healthy.

The symptom is a run against the wrong tree: `/workspace/oos` is where the
repository "is", yet `git -C /workspace/oos status` shows another branch, and
`/app` is a copy taken from that other checkout's skeleton — so a method you
just added is reported as undefined. Check the bind source before debugging a
missing class:

```sh
docker inspect oos-laravel-app --format '{{range .Mounts}}{{.Source}} => {{.Destination}}{{"\n"}}{{end}}'
```

If it names a different worktree, either rerun Compose from the intended
directory or accept the swap deliberately and sync the skeleton into `app/`
(the cycle above). Do not start by "fixing" the test: the first run against a
stale `app/` looks exactly like a genuine failure, and the second run after a
sync looks like a flake.

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

**`/app` is a synced copy, not the source (2026-11-17).** `docker inspect oos-laravel-app`
shows `docker/laravel/app` (gitignored) mounted at `/app`; the tracked source is
`docker/laravel/app-skeleton`. `vendor/nvoos/{core, laravel-adapter, wifi}` are
symlinks into `/workspace/oos/lib/…`, so `lib/` edits are live — `app/`,
`tests/`, `routes/`, `contracts/`, `resources/`, `database/` are not. Sync before
a run, or you are testing a copy that lags by however long it has been since
someone last installed:

```sh
docker exec oos-laravel-app sh -lc \
  'for d in app database routes tests contracts resources config public; do \
     cp -a /workspace/oos/docker/laravel/app-skeleton/$d/. /app/$d/; done'
```

Two failure shapes this produces, both of which read like code breaks:

- a test you just wrote is **not found** (the copy predates it);
- **stale files from another worktree** produce `No tests found in class
  "Tests\Feature\X"` for a class that does not exist in `app-skeleton`. Confirm
  the file exists in the skeleton before investigating the failure. `cp -a`
  never deletes, so the stale copy only grows.

**A scoped store plus an un-migrated dev database is a third.** After adding a
`tenant_id` migration, run `php artisan migrate` in the container before judging
anything: the running app serves its **own** database, and a scoped
`where tenant_id` against a table that lacks the column returns 500s. The console
smoke then fails its page renders while `/features` — which touches no scoped
store — stays green, so the failure looks like a UI regression rather than a
schema one. Seen on 2026-11-17 with `nvoos_memories` and `nvoos_approvals` pending:
4/6 failing, 6/6 after `migrate`.

**The CLI default is 128 MB and the suite exceeds it.** `Phase3dTest` dies with
`Allowed memory size of 134217728 bytes exhausted` and takes the run with it.
The container command is therefore:

```sh
docker exec -w /app oos-laravel-app php -d memory_limit=1G vendor/bin/phpunit
```

**Docker is the preferred runner.** Use the host only when the container is down
or has no vendor tree — and expect `Class "PHPUnit\TextUI\Application" not found`
there, because this worktree's committed `vendor/composer` map is no-dev (see the
test-suite skill's no-dev shim recipe). `-d` on `artisan test`'s parent does not
reach the child PHP process either, so a full-suite run needs `vendor/bin/phpunit`
directly inside the container as well.

| Suite | Command (cwd) | Baseline (2026-11-14) |
|---|---|---|
| App | `docker exec -w /app oos-laravel-app php -d memory_limit=1G vendor/bin/phpunit` — after the sync above. `artisan test` is fine for a filtered run but spawns a child; use phpunit directly for the full suite. The two `NVOOS_PM_*_FIXTURE` overrides are a container-only requirement | **920 tests / 5,125 assertions** (2026-11-17, after the console-tenancy slice); 6 failures were pre-existing scratch-container gaps, not code |
| Adapter | `vendor/bin/phpunit` (`lib/laravel-adapter`) — scratch copy or container, see §19 | **348 tests / 1,825 assertions**, OK (2026-11-23). The only gate that catches a broken **package** binding: the app binds most of the same contracts itself, so a provider binding that lands on a nonexistent class name passes the app suite |
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
surface on the PR (the check is `App suite (SQLite)`). Still quote your local
counts in the PR — CI proving "not broken" is weaker evidence than a count you
actually saw.

**The PSR-12 gate is configured but was unreachable.** It lives inside the
`WordPress Coding Standards (WPCS 3.0)` job in `php-linting.yml`, *after* `Get
changed PHP files` (`tj-actions/changed-files`), which hung `in_progress` for
70+ minutes on three PRs while every later step stayed `pending`. A `pending`
check does not fail a PR, so six style errors merged into `alpha-working`
(#7862–#7864). #7865 moves both ruleset checks above that step. Until the merged
shape reflects that, a green PR is **not** proof the Laravel tree was linted —
confirm the step, not the check label:

```sh
gh api repos/<org>/<repo>/actions/jobs/<job-id> \
  --jq '.steps[] | {n:.number, name, status, conclusion}'
```

Run the style gate exactly as CI does, otherwise line-length warnings that CI
ignores will look like failures:

```sh
vendor/bin/phpcs --standard=phpcs-laravel.xml.dist --error-severity=1 --warning-severity=8
```

`Generic.Files.LineLength` is severity 5, so `--warning-severity=8` is what
makes the check pass on the pre-existing long lines in `AppServiceProvider`.

A **path-scoped** run is the same ruleset at narrower scope, not a different
gate: passing `docker/laravel/app-skeleton` reports ~300 files, while CI's
no-path run also covers `lib/core` and `lib/laravel-adapter` (~800+). Say which
one you ran — the two counts are not comparable.

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
  not a regression you introduced. Point them at the **repo** capture
  (`/workspace/oos/tests/fixtures/pm/tools.json` and `…/writes.json`), not at
  `/app/fixtures/pm/`, which does not exist: the skeleton's `fixtures/` holds
  `mcp-ai-pro-v1` and nothing else. A wrong path is the same failure as no path.
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
- **Filament's published assets are build output, and their absence renders the
  panel unstyled.** `filament:assets` writes ~4.5 MB to
  `public/{css,fonts,js}/filament`, so the skeleton `.gitignore`s them and a
  runtime copy has none. `entrypoint.sh` publishes them **only when
  `public/css/filament/filament/app.css` is absent**, and swallows the outcome
  (`>/dev/null 2>&1 || true`) — so a failed publish is invisible, and the next
  boot does not retry, because a *page* still answers 200.
  **Symptom:** every panel page renders with no styling; the browser's network
  log shows `/css/filament/filament/app.css`, `/js/filament/**` and
  `/fonts/filament/**` all **404**.
  **Diagnose:** `curl -o /dev/null -w '%{http_code}' http://localhost:8001/css/filament/filament/app.css`.
  **Fix:** `docker exec -w /app oos-laravel-app php artisan filament:assets`.
  It is **panel-wide, not per-page** — confirm on a second panel route before
  suspecting the page you just wrote. The three directories must never be
  committed: they are one `git add -A` away from 4.5 MB of binaries.
- **`tenant_id` is not fillable on `Assistant`.** `$fillable` deliberately omits
  it ("the tenant is never supplied by a request body"), so
  `Assistant::query()->create(['tenant_id' => 'estate-b', …])` **silently drops
  the value** and the row takes the column default. The failure mode is the
  opposite of what you expect: a fixture that should be foreign appears on the
  current estate's page, which reads like a scope leak. Set it on the instance
  (`$a = new Assistant([...]); $a->tenant_id = …; $a->save();`) — and check
  `$fillable` before trusting any scoped fixture.
- **PHPCompatibility cannot parse enum bodies.** `PHPCompatibilityWP` 2.1.8
  treats every method inside an `enum` as a plain function and rejects `$this`
  (`PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext`).
  It is not about `match`: `switch ($this)` and a bare `$this->value` fail too,
  and the identical code passes in a `class`. Because `PHP Compatibility Check`
  only inspects **changed** files, an enum fails the first time any PR touches
  it. `app/Filament/Admin/Navigation/AdminNavigationGroup.php` carries a
  file-scoped `phpcs:disable` for that one sniff with the probe recorded; copy
  that pattern rather than refactoring the enum, and delete both when the
  standard learns enums.
- **The runtime containers have no `ps`, `kill`, `pkill` or `pgrep`.** A
  detached `docker exec -d … phpunit` cannot be stopped from inside. Get the pid
  with `docker top oos-laravel-app -o pid,args` from the host; if you cannot kill
  it, `docker restart oos-laravel-app` (a `posix_kill` from inside does not
  reach it). **Never run two full suites at once** — they share `storage/` and
  produce failures that look like regressions.

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
- **Token manager** — `GET /api/v1/token-manager/overview` plus `revoke`, `delete`, `lift` over `personal_access_tokens`. The token **hash is never selected**; only metadata is read (a test captures the hash and asserts it is absent from the payload). `AuthProvider::issueCredential()` gates on `manage_oos` — that gate **did not exist** until this window, so credential issuance was permanently forbidden; it is now in the administrator capability set. It also **persists `expires_at`** when `expires_in_seconds` is passed; before Wave 2 it only reported the expiry on the returned entity, so a token that looked time-limited never expired (`CredentialExpiryTest`).
- **Command centre + telemetry** — the overview route reads dedicated telemetry stores (`nvoos_*` migrations) rather than scanning tables at request time; the schema test pins the telemetry and token tables. Nav items whose endpoint is not served are hidden client-side.
- **Slash commands** — `SlashCommandParser` + `ToolShortcutNormaliser` in `lib/core`; the Laravel surface parses and serves shortcuts. Tool shortcuts are shared; the full WordPress command *library* is not ported (deliberate, #7744).
- **Tool readiness (090-M)** — `lib/core/resources/tool-readiness.php` declares **223** classes with a closed vocabulary; `Application\Tool\ToolReadiness` classifies (`classify`, `isServable`, `annotations`, `hash`, `violations`); `ManifestToolRegistrar` registers only servable tools with the constructor resolved from the container; `bin/audit-tool-readiness.php` checks completeness, integrity, shadowing and probe-tier vocabulary and runs in the `Tool Readiness` CI job. **Do not add a tool to a registrar by hand** — add it to the manifest. Nineteen prompt-builder stand-ins stay unregistered because they shared a real tool's slug and returned a synthetic `success`.
- **Toolkit renderer (Wave 3, plan §56)** — `/admin/{estate}/toolkits` lists the entitled
  manifests; `/admin/{estate}/toolkits/{toolkit}/{screen}` renders `settings`,
  `entities` (`?entity=…`) and `dashboard` from the manifest through
  `App\Support\Pro\ToolkitEngine`. No toolkit name appears in the PHP or Blade —
  a new family is a manifest commit. Setting types are
  `text|textarea|number|boolean|select|secret`; `textarea` takes an optional
  `rows` 2–20 and a list value is a newline-separated string (the CRM schema
  check in `docs/project/proposals/149-toolkit-manifest-schema-check.md`).
  **`entities` is optional**: omitting it declares a settings-only toolkit
  (CRM, chat channels — their records live on CPT/REST surfaces); an explicitly
  empty list is still rejected, and `ToolkitContractTest` skips the entity
  lifecycle for such a family. `research`/`consolidate` are console-only and
  answer **404** on the panel; an unlicensed toolkit is absent from the catalogue
  and also 404s (indistinguishable from unknown, R4). The contract row is
  `toolkits: subset`: list/search/armed-delete are here, create/edit stay in the
  console workbench. `bin/audit-admin-manifests.php` gates manifest validity and
  screen coverage in `laravel-app.yml`; it fails on an unknown/unserved screen,
  a slug/filename mismatch, or a manifest with no screens.
  - **A Filament 5 custom page slug may carry route parameters** —
    `protected static ?string $slug = 'toolkits/{toolkit}/{screen}'` registers
    `admin/{tenant}/toolkits/{toolkit}/{screen}` and `Page::getUrl(['toolkit'=>…,
    'screen'=>…])` fills both; set `$shouldRegisterNavigation = false` or the
    sidebar asks for a URL with no parameters. Extra params become a query string,
    which is how `?entity=` rides the same route.
  - **`@js()` is banned in panel Blade**, and `AdminPanelBladeTest` enforces it:
    the component tag compiler stringifies the attribute before the directive runs.
    Use `wire:click="method({{ \Illuminate\Support\Js::from($value) }})"` inside
    **double** quotes.
  - **`Filament::setTenant()` needs an authenticated user.** The `TenantSet` event
    takes the current user, so a test that calls it in `setUp()` before
    `actingAs()` throws a `TypeError`; sign in first, then pin the tenant.
- **Workflow executor + triggers** — `WorkflowExecutor` (`lib/core`) walks the graph in **Kahn topological order**, one node at a time, fail-fast (the first failure names itself and downstream nodes record `skipped`). Node types: `tool`, `condition`, `agent`, `delay`, plus input/output pass-throughs; an unknown type without `toolSlug` is a no-op, so a WordPress-authored graph still runs. `WorkflowRunner` is shared by `POST /api/v1/orchestration/triggers/{id}/fire` and `php artisan nvoos:fire-triggers` (`everyMinute()->withoutOverlapping()`) so both produce the same run row (`source` = `manual`/`trigger`, plus `trigger_id`). `config.cron` beats `config.schedule` (WP interval name or five-field expression); `config.every_minutes` is the fallback and an unparseable schedule is simply never due.
- **Endpoint availability contract** — 45 console keys, each reporting route-backed vs blank **with a reason** when it is not; `/features` reports the true/false counts — **39 true / 6 false / `endpoint_gaps: []`** measured 2026-11-16, so the runtime answer matches the contract declaration key for key. The six are `chatChannels` (`toolkit-shaped`), `mediaCc` (`connected-service`: the route exists but `oos.media_worker.url` is unset) and `contentGraph`/`docsHub`/`mediaStudio`/`documentEditor` (`external-product`: `oos.connected_apps` is unset). WordPress reads **41/4** on the same estate because the docs-hub plugin registers `nvoos-docs` locally. A discovered app is merged by `EndpointCapabilities::applyConnectedApps()`, which **recomputes `gaps`** — flipping a key without recomputing reports a surface as both available and missing. The contract is guarded on both CIs (`spa-v2/src/api/config.ts` ↔ `EndpointCapabilities::ROUTES`) — adding a route without the key (or the reverse) fails. Adapter migrations are proven to run in the app; do not add a table that only exists in the dev DB.
- **Harness storage** — `HarnessProfileProviderInterface` + `nvoos_harness_trace_records` (one row per assistant, `TracePolicy` retention 10–200). `NullTraceStore` is the default when no storage is bound.
- **Media ops** — health comes from the Media Worker, optimisation is proxied through it, and a sweep is scheduled; the Pro settings matrix must not report `installed` when the worker is unreachable.
- **Conversation import (`/ops`)** — the page **names its target estate** (selector, initial `default`), because the operator panel has no ambient estate. The import pins `TenantContext` for the action only and restores it in `finally`, refuses an unknown id before any write, and records the estate on the audit row. Do not re-introduce an implicit `default` fallback.
- **Assistant + console extras** — `POST` assistant test endpoint, blueprint catalogue + install-as-assistant, advanced assistant-editor fields, dashboards route, chat-client route.
- **Admin panels (Proposal 149)** — `/ops` (operator) and `/admin` (tenant-scoped) are Filament 5.11 panels; resources live under `app/Filament/{Operator,Admin}/` so the two panels cannot share one. Access is `AdminAccessPolicy`: `/ops` = member of a `tenants.is_operator` estate **plus** `manage_options` (never `users.role` — a customer's estate administrator is an administrator), `/admin` = non-viewer membership. `AuditAdminRequests` writes one row per mutating panel request to `nvoos_admin_audit` (failures included; credentials redacted at any depth), and the `RecordsAdminChanges` observer trait adds a **record row** (`resource`/`record_id`/`action`/`before`/`after`) for every administrable model — `resource IS NULL` is what tells a request row from a record row, and a resource write produces one of each. Resources: `/ops/estates` (`EstateResource` over `Tenant`; `TenantPolicy` denies create/delete, `is_operator` and `slug` are not editable, the licence key is write-only — blanked with `afterStateHydrated()` because a password input still ships the value) and `/admin/{estate}/teams` (`TeamResource`; `TeamPolicy` composes estate membership → **record ownership** → team role, and writes go through `TeamServiceInterface` so tenant, slug and creator-as-owner stay in one place). `/ops/queue` is a page rather than a resource (`QueueMonitor`: retry pushes the original payload back through `FailedJobProviderInterface` and forgets the row, forget/flush never requeue, and every action writes its own audit row because Livewire traffic is excluded from the request-level audit). `/ops/credentials` rotates assistant tokens (`Credentials`: **issue first, revoke second** via the captured ids, so a failed issuance leaves the old credential working; the new plaintext lives in a page property — never a form field, the audit row, or a notification, which is cache-backed — and only `oos-assistant-{id}` names are counted). The palette is `AdminTheme::FILAMENT_COLORS`, generated from the storefront DTCG tokens by `php artisan admin:theme` and drift-gated by `AdminThemeDriftTest`.

- **Admin panels, Wave 2 close-out** — two more resources, and the seams they introduced. `/admin/{estate}/assistants` (`AssistantResource`) puts every assistant write through **`AssistantWriter`** (`app/Services/`): the extraction of the five invariants `AssistantController` used to own inline — estate assignment from `TenantContext` (`tenant_id` is not fillable), per-estate slug uniqueness (`-2`, `-3`… mirroring the `(tenant_id, slug)` index; a blank slug derives from the title), `primary_role_slugs` → `meta`, the 14-key `advanced` whitelist (absent keys untouched, present-but-empty removed), and `is_default` exclusivity scoped to the estate. The controller and the resource both delegate, so the REST suite and the panel cannot disagree. `/ops/scheduler` is a **diagnosis, not a Schedule Manager** (`Schedule` model over `nvoos_schedules`, read-only; health states `paused`/`untimed`/`overdue`/`scheduled`; only `bootstrap()` and `due()+run()` are offered — creating and editing stay in the console's `SchedulesController`, which would otherwise be one page twice). It exists because `nvoos_schedules` is advanced only by `nvoos:run-schedules`, which only fires where the `scheduler` role runs `schedule:work` — a dead tick leaves every console page looking healthy. Estate ownership is **`EstateScope`** (`currentId()`/`owns()`), shared by `TeamPolicy` and `AssistantPolicy`; it fails closed on an unresolved estate rather than falling back to the `TenantContext` default.

### Gotchas that cost cycles in this window

- **A top-level response wrapped "for consistency" breaks the client silently** (MCP apps). Check the SPA client, not the convention.
- **`WP_Meta_Query` wraps `LIKE` operands in `%…%`.** `ContentStore::likePattern()` must wrap them too; the port surfaced this only because a list tool returned zero rows.
- **`per_page` resets to the default, it does not clamp.** Clamping answers a different page size for the same request; WordPress resets, so the port resets.
- **`manage_oos` had no definition.** A Gate that is missing denies everything forever — assert issuance through the real path, not a fixture insert.
- **A static-client OAuth provider cannot use DCR.** Pre-registered clients mean HTTP Basic token requests and case-preserving client IDs.
- **`Panel::getTenant()` is not the current tenant in Filament 5.** It resolves a tenant *by key* (`getTenant(string $key): Model`) and throws `ArgumentCountError` with no argument; the current tenant is `Filament::getTenant()`. This sat undetected in `AdminAuditLogger::tenantId()` until the first tenant-scoped resource wrote a record row (teams), because the operator panel has no tenancy and every Wave 0 audit test used panel-less routes.
- **Filament fills a form from `$record->attributesToArray()`.** A field backed by `meta` (the assistant profession selection) therefore needs `$appends = ['primary_role_slugs']` plus a `getPrimaryRoleSlugsAttribute()` accessor — without it the edit form hydrates empty and the first panel save silently wipes the selection. `toRecord()`/`toBundleEntry()` are explicit, so the console wire shape is unaffected.
- **`assertTableActionHidden($action, $record)` is the wrong assertion for cross-tenant.** When the record is absent from the tenant-scoped table Filament raises `ActionNotResolvableException` instead of reporting "hidden". Assert `assertCanSeeTableRecords` / `assertCanNotSeeTableRecords`: "this estate cannot see that record" is both the real rule and the stable one.
- **`callTableAction(..., data: [...])` mounts first, then overlays only the keys you pass.** The mount is what hydrates the form, so a partial `data` array is a genuine "edit preserving everything else" test — which is how the `$appends` defect above surfaced.
- **A panel-provider change needs a container restart.** `->favicon()` / `->colors()` live on the `Panel` object built at boot; `OCTANE_MAX_REQUESTS=1` did **not** pick up a favicon change — the page kept rendering with no `<link rel="icon">` until `docker restart`.
- **`git archive HEAD <dir>` can return an empty tree.** `export-ignore` in `.gitattributes` filters it, and `docker/laravel/app-skeleton` is affected. Restore individual files with `git show HEAD:path > path` instead of archiving.
- **The entrypoint only installs Composer deps when `vendor/autoload.php` is absent.** A newly added path package (e.g. `nvoos/wifi`) is therefore never linked into an existing volume — run `composer update <vendor/pkg> --with-dependencies` by hand. The committed `composer.json` also uses host-relative path repos (`../../../lib/core`) that the entrypoint rewrites to `/workspace/oos/…`; if that rewrite did not stick, Composer fails with "The `url` supplied for the path … does not exist" — rewrite the three `../../../` prefixes.

---

## 11. Console API estate resolution (149 §21)

`TenantContext` was pinned **only** by the panel's `SetTenantContext`, so every
`/api/v1` request resolved `default`. That was a cross-tenant leak for non-members
and a silently wrong answer for anyone in several estates.

**`App\Http\Middleware\ResolveApiTenant`** now resolves the estate in middleware,
following the OWASP Multi-Tenant Security Cheat Sheet's rule that a client-supplied
tenant id is a **selector only**:

| Request | Response |
|---|---|
| `X-Tenant-Id` naming a membership | that estate |
| `X-Tenant-Id` naming anything else | **404** `tenant_not_found` (R4 — indistinguishable from a missing estate) |
| no header, one membership | that estate |
| no header, no memberships | **403** `tenant_unprovisioned` — never a fallback to `default` |
| no header, several memberships | **409** `tenant_required` |

- Mounted on the authenticated console group; **`/api/v1/session/me` sits outside
  it** and returns `tenant` + `estates[]`, because it is how a client learns what
  to select. `ApiTenantResolutionTest` has a **route-coverage gate**: every
  `api/v1` route except a named allowlist must carry the middleware, so a new
  endpoint cannot silently bypass it.
- The context is cleared in `finally`. The queue worker is a long-lived process
  that fires no Octane events, and `dispatchSync` inside a request would otherwise
  inherit the request's estate.
- A refused selection logs `api.tenant_selection_refused` (user id, requested
  estate, path) — never the estate's name or data.
- **Fixtures must be provisioned.** `UserFactory::create()` puts the user in
  `default` with the role derived by `CapabilityMap::estateRoleFor()`; use
  `inEstate($id, $role)` for a single-estate actor and `withoutEstate()` for the
  one test that pins the 403. Without this, every authenticated API test fails
  with 403 and the suite looks broken (356 failures on first run).
- **Client gap — closed 2026-11-18.** The console now sends the header and asks
  when it must. `spa-v2/src/api/tenant.ts` owns the selection (localStorage
  `nvoos-console.tenant`, max 64 chars, whitespace rejected) and the boot
  decision: zero memberships → proceed (WordPress or unprovisioned; a Laravel
  session reporting none also **clears** a stored id, which cannot be valid),
  one → proceed without a selector, several and no usable stored id → the
  estate picker, a stored id that is no longer a membership → the picker
  (never a permanent 404). `EstatePicker` runs before the shell; the sidebar footer
  gains a switcher when there is more than one estate and **reloads** on
  switch so no spa-v2 store paints one estate's data under another's name.
  Sign-out clears the selection.
  - The header is injected in the console's single fetch wrapper
    (`src/fetch-wrapper.ts`) and its jQuery prefilter — read **per request**,
    scoped to same-origin `/api/v1/` paths, and **skipped under
    `/api/v1/session/`** (identity endpoints must report the server's own
    resolution so the picker can validate the stored id). WordPress gets no
    selector at all: `installFetchWrapper` only receives `tenantId` for a
    non-WordPress backend.
  - `serve.mjs`'s proxy micro-cache key includes `x-tenant-id`. Without it a
    tenant-scoped GET that happens to be publicly cacheable would be replayable
    across estates — the multi-tenant cache-key rule.
  - Pinned by `tenant-selection.test.ts`, `fetch-wrapper.test.ts`,
    `runtime-config.test.ts`, and the console smoke's *sends the selected
    estate on console API requests only* test (the seeded e2e user is in two
    estates, so the picker itself is exercised by the auth setup).

## 12. Settings: two layers, one writer (149 §16–§17)

| Layer | Table | Scope | Written by |
|---|---|---|---|
| Platform | `nvoos_settings` (key = PK) | `operator` | `/ops/settings` |
| Estate | `nvoos_tenant_settings` (PK `tenant_id, key`) | `tenant` / `user` | `/admin/{estate}/settings`, console |

- **`SettingsStore` takes a tenant RESOLVER closure, not an id.** `ProviderRouter`
  is a singleton that constructs twelve provider clients with one injected store
  during boot, before tenant middleware; a fixed id would pin every provider call
  to the boot-time estate. The closure reads `TenantContext::current()` per `all()`.
  Bind it with `bind`, not `scoped`, for the same reason.
- **`App\Support\Settings\SettingsService` is the one writer** (149 R1). It routes
  each field by its `scope`, and maps the *surface* key to the *storage* key the
  runtime reads: `google_api_key` → `gemini_api_key`, `ollama_endpoint` →
  `ollama_endpoint_url`. Those two mismatches shipped as real defects — the form
  wrote a key nothing read.
- **`forSurface()` decides what is RENDERED; `writableFor()` decides what is
  ACCEPTED.** `admin` renders operator-scope fields (disabled, with the S-1 hint)
  but accepts only tenant/user scope; `ops` renders and accepts operator scope
  only; `console` gets its visible set minus `readonly`.
- **`->disabled()` does not stop dehydration** in Filament. A read-only field needs
  `->dehydrated(false)` as well, and the service must refuse the key anyway.
- **Generate the form from the schema**, never author it: adding a field is then
  one edit and both panels stay in step. `app/Filament/Concerns/BuildsSettingsForm`
  is shared by both pages.
- `/ops` must **not** render estate fields. That panel has no estate selected, so
  a `default_model` field there would edit the `default` estate while looking like
  a platform setting — a failure that appears to work.

## 13. Filament 5 page/form facts (verified against `vendor/`)

- Namespaces moved in v4/v5: `Filament\Schemas\Components\{Section,Tabs,Tab}`
  (not `Forms\Components`), `Filament\Schemas\Schema`; fields stay
  `Filament\Forms\Components\{TextInput,Select,Toggle}`.
- A custom page implements `Filament\Forms\Contracts\HasForms` and uses
  `Filament\Forms\Concerns\InteractsWithForms`; override
  `form(Schema $schema)` and set `->statePath('data')` plus
  `public ?array $data = []`.
- `$this->form` works through
  `Filament\Schemas\Concerns\ResolvesDynamicLivewireProperties::__get` →
  `getSchema('form')`; there is no `$form` property to declare.
- **A table needs an Eloquent model**, not a query builder. `QueueMonitor`-style
  read-only log pages should add a model whose `booted()` registers a `tenant`
  global scope, so the panel and the REST reader share one isolation rule.
- Blade views use **inline styles**, not Tailwind utilities: the panel's compiled
  CSS has no arbitrary utilities and a missing class silently collapses layout.
- Page URLs are tenant-prefixed on `/admin` (`/admin/{slug}/…`); `/ops` has no
  tenant segment.
- **A 200 is not a rendered page, and a snapshot is not a screenshot.** The route
  can answer 200 with every node in the right place while the panel's CSS and JS
  are 404 (§6): an accessibility snapshot is *structure*, so it looks perfect.
  Before reporting a panel page as working, assert the asset —
  `curl -o /dev/null -w '%{http_code}' http://localhost:8001/css/filament/filament/app.css`
  must be 200 — or take a screenshot and look at it. Reporting a page green off a
  status code and a snapshot is how an unstyled panel reaches the person who has
  to use it.
- **A navigation-group icon requires a collapsible sidebar.** Filament throws
  `Navigation group [X] has an icon but one or more of its items also have
  icons` from `sidebar/group.blade.php` unless the group renders as a dropdown,
  which only happens when the panel calls `->sidebarCollapsibleOnDesktop()`.
  Declaring an icon on *every* case of a navigation-group enum (the `/admin`
  shape) and wiring that enum into the other panel therefore makes **every page
  in that panel a 500** — and the navigation tests keep passing, because they
  read `Panel::getNavigation()`, which builds data and renders nothing. Guard it
  with a real render (`$this->get('/ops')->assertOk()`), not a data assertion.
- **`searchable()`/`sortable()` treat a dotted name from another table as a JSON
  path.** `getJsonSafeColumnName()` keeps a name that starts with the model's own
  table (`personal_access_tokens.created_at` survives) and rewrites anything else
  by joining segments with `->`, so `searchable(['users.name'])` emits
  `json_extract("users", '$."name"')` and the table dies with
  `SQLSTATE[HY000]: malformed JSON`. Joined columns need the `query:` closure
  form, which bypasses name resolution entirely. Escaping has to be ported too:
  the helpers are `@internal`, and `like ? escape '!'` is the portable spelling
  (SQLite has no default `like` escape character, so MySQL's backslash form
  breaks the suite).
- **`->toggleable(isToggledHiddenByDefault: true)` is not `hidden()`.** A
  hidden-by-default column still contributes its search constraint, so it stays
  findable and sortable while not occupying width — which is how a seven-column
  audit table is made to fit without losing a filter.
- **`AdminAuditLogger` redacts any payload key containing `token`** (deliberately
  over-redacting). A `tokens => 2` count is stored as `[redacted]`; name the key
  `count` and the figure survives.

## 14. Surface-ownership audits that found real bugs (149 §22–§24)

Three slices in this window each found a contradiction in
`contracts/surface-ownership.json` rather than a missing page. Read the row as
an assertion about the code, and check it before building.

- **`writer` is present tense.** If it names a layer whose write path is not
  registered on this runtime, the row is lying. `professions` said
  `writer: admin` while its own note said `save_profession` stays WordPress-side
  — correct it to `none`. Ask: *which class actually persists?* If the answer is
  "none here", so is the writer. `shared` is only true when two real writers
  exist.
- **`subset` is BUILT.** `bin/audit-surface-ownership.php` omitted it from
  `$builtStates`, so the first row whose only panel presentation is a subset
  (`credentials.estate`) reported as console-only, and `settings.platform`
  (admin: subset, the S-1 rendered-disabled decision) was under-counted too.
  When you ship the first row in a new state, check the script's vocabulary
  against the contract's vocabulary — the contract test only enforces the
  contract.
- **A reference catalogue is not a tenant record set.** For `tools` and
  `professions` the honest projection is "no reduction, and that is the
  decision", because reducing the payload breaks the console's picker/selector.
  Say so in `projection`, or the next reader "fixes" it.
- **A page with actions is not proved read-only by a forms assertion.** The
  `InteractsWithForms` / `HasForms` check proves "accepts no payload" for a
  browse page. For a page with an action (revoke), pin the *action surface*
  (`assertDontSee('Issue credential')`) and re-resolve the estate at action
  time, so armed Livewire state cannot reach across estates.

### Panel tenancy and `TenantContext` (corrected 2026-11-17)

`App\Http\Middleware\SetTenantContext` is registered on the **customer panel**
as tenant middleware (`->tenantMiddleware([...], isPersistent: true)`), so it
pins `TenantContext` to `Filament::getTenant()` on every `/admin` request. That
is why `WorkflowRunLog` and `AutomationCatalogue` can read ambient state and be
correct. Three exceptions, and they are the ones that bite:

1. **Livewire component tests do not run middleware.** `Livewire::test(...)` on a
   panel page leaves `TenantContext` at `'default'` unless the test sets it.
2. **The operator panel deliberately does not pin a tenant** — an operator
   inspects every estate, so `/ops` pages must not filter by one.
3. **Console/API code needs an explicit id**, because `ResolveApiTenant` sets it
   per request and a shared reader (e.g. `EstateCredentials`) is called from both
   shapes. A shared reader takes an **explicit estate id** and fails closed on an
   empty one; a panel-only reader may read ambient state and say so in its
docblock.

Do not "fix" a panel page by adding `TenantContext::set()` — the middleware
already did it, and a second write fights the Octane/queue resets.

`Assistant` has **no** tenant global scope (unlike `WorkflowRun`), so
`where('tenant_id', $id)` is the only filter — passing the estate id is required,
not belt-and-braces.

### `Sanctum::actingAs()` breaks panel tests

`Sanctum::actingAs()` changes the **default** guard; Filament resolves its tenant
against the panel guard. A fixture helper that seeds data with `Sanctum::actingAs()`
leaves a later `/admin` request looking unauthenticated — the page answers **302**
(a login redirect) and every `assertSee` fails against HTML that has nothing to do
with the page. Use `$this->actingAs()` in panel tests, even for the API call that
creates the fixture.

Sanctum credentials are linked to an estate only through the token **name**
(`oos-assistant-{id}`), because `personal_access_tokens` has no tenant column and
`tokenable` is the issuing user. Scope by the literal name list (indexed) rather
than a `like` scan.

### Editing `surface-ownership.json` safely

A single `\` inside a JSON string is an invalid escape. `php -r` one-liners that
rewrite this file mangle the replacement under this shell, and the failure is
silent until PHPUnit parses the contract. Symptom:
`Invalid \escape: line N column M` from `json.loads`, while `json_decode`
returns `null` with no position.

Write a `.py` or `.php` **file** for the edit, use `App\\Support\\Foo` in the
JSON, and assert `json.loads(...)` succeeds before committing. Do not trust a
`str_replace` that reported "fixed".

### Diagnosing suite noise in the container

- `Empty data set provided by data provider` + `No tests found in class`
  = the provider's **corpus file is absent** (e.g.
  `ProjectManagement*ParityTest` needs the WordPress capture). It is not the
  stale-copy trap, and it fires at collection time even when `--filter`
  excludes the class. Check the provider's `is_file()` guard before touching
  anything.
- `AuthProvider::issueCredential()` refuses to run unauthenticated — tests must
  `actingAs()` a user before minting, and that user becomes `tokenable_id`.
- **A full-suite count smaller than the last recorded one means missing FILES,
  not passing tests.** A sync that copies
  `app config database resources routes bootstrap contracts blueprints fixtures public`
  and omits `tests` leaves the runtime holding 136 of the skeleton's 165 test
  files: the suite runs, is green, and reports 1269 where CI reports 1575. Two
  empty data providers accounted for 15 of the difference without failing
  anything (149 §106.9). Reconcile with
  `diff -rq docker/laravel/app-skeleton/tests <runtime>/tests` before quoting a
  number, and read `Only in <skeleton>` as evidence, not noise.

## 15. Wave 2 tail: slices 25–38 (149 §25–§38)

The last thirteen slices were mostly **dispositions and disclosures**, not new
CRUD. Read this before adding a panel page: much of what looks missing was
deliberately not built, and the reason is recorded.

**Scoreboard:** `php bin/audit-surface-ownership.php` (`--json` for tooling) —
47 surfaces · 38 panel-built · 29 console-served · 6 console-only · 0
planned-only · 1 inert, measured 2026-11-20 after 149 §106. Re-run it before
quoting a number; it reads the same contract the CI gate reads.

### Panel traffic is not audited by the request middleware

`AuditAdminRequests` skips `/livewire/update` as UI mechanics, so **every panel
action needs its own `AdminAuditLogger::recordResource()` call** — destructive
ones especially. §25 found the credential revoke left no trace at all. A resource
write produces **two** rows (request + record); `resource IS NULL` is the request
row. `/ops/audit-log` renders both kinds on purpose: request rows answer "who
tried and was refused", record rows answer "what changed". The page is `LIMIT`
200 with the ceiling printed in the header — never a silent truncation.

### The token surfaces are three, and the name is the separation

| Surface | Names it touches | Scope |
|---|---|---|
| `/ops/credentials` (assistant, rotate) | `oos-assistant-{id}` | estate |
| `/admin/credentials` (estate, list/revoke) | `oos-assistant-{id}` | estate |
| `/admin/personal-tokens` | `oos-personal:*` | **user** |

- **Sanctum abilities are not enforced anywhere** — no `tokenCan()`, no
  `abilities:` middleware. Authority comes from the user's *current* role via
  `CapabilityMap`, so a personal token stores `['*']` (`['edit_posts']` would
  assert a restriction nothing applies) and the UI offers no ability selector.
- Personal-token routes sit **outside `ResolveApiTenant`** (allowlisted in that
  test with the reason): a token belongs to a person across every estate, so
  requiring an estate would 409 a multi-estate user out of their own credentials.
- Expiry is mandatory and bounded (1–365 days). The plaintext is shown once,
  never audited, and cleared when the armed token is revoked.

### Cross-estate stores: the scope belongs in one helper

Two tables shipped with **no tenant column** while their routes already ran
inside `ResolveApiTenant` — the estate was resolved and then ignored
(`nvoos_remote_sites` §27, `nvoos_triggers` §29). Rule: **one `query()` helper per
store/controller that every method starts from.** Adding `->where('tenant_id', …)`
to six call sites is a change one forgotten line undoes.

- The dangerous verbs are the ones that *act* with a foreign row: `test` sends
  that row's sealed credentials to the named URL; `fire` runs a workflow under the
  caller's identity.
- **404, not 403, for a foreign id** — identical to a nonexistent id.
- System callers stay unscoped and carry the scope per row. `TriggerDispatcher::due()`
  must see every estate; `nvoos:fire-triggers` pins `TenantContext` per trigger and
  resets it in `finally` (a `continue` in the catch block would skip a reset
  placed at the end of the loop body).
- `down()` does nothing on these migrations — dropping the column re-exposes
  every estate's rows.
- **A scope can be a KEY, not just a column.** `nvoos_transcripts` had no
  `tenant_id` *and* a globally unique `session_id`, so two estates that generated
  the same session key overwrote each other's conversation on `save()` (§39).
  Adding the column alone converts a silent overwrite into a constraint failure —
  the unique has to become `(tenant_id, session_id)`. Whenever a store upserts by
  a client-generated id, check the unique index before scoping it.
- **Retention jobs stay unscoped.** `TranscriptStore::prune()` deletes across every
  estate on purpose, like `TriggerDispatcher::due()`: a job with no request estate
  must not inherit one. Audit records and asset registers that compute per request
  are the other side of the same rule — they declare `writer: none`.
- **Five stores have now shipped unscoped; assume the next one is.**
  `nvoos_remote_sites` (§27), `nvoos_triggers` (§29), `nvoos_transcripts` (§39),
  `nvoos_memories` (§40) and `nvoos_approvals` (§41) all had the same shape: routes
  already inside `ResolveApiTenant`, a store or controller naming the table
  directly, and no `tenant_id`. Before adding a page over a store you have not
  touched, `grep -n "DB::table" <store>` — if the table has no estate column, the
  schema is the first problem and the page is the second.
- **Scope the verbs that ACT, not just the ones that list.** `resolve` on
  `nvoos_approvals` (§41) let one estate approve or deny another estate's gated
  tool call; `test` on a remote site (§27) sent that row's sealed credentials to a
  URL; `fire` on a trigger (§29) ran a workflow under the caller's identity. When
  you scope a controller, enumerate its verbs and ask which one has an effect
  outside the request.
- **A filter is not a boundary.** `listByAgent( agent_id )` (§40) reads like one
  and is not: the id arrives as a query parameter. Estate scope belongs in the
  query helper, not in the argument list.

### `writer: none` when nothing persists

The §23 rule fired four more times. `site-health`, `assets` and the audit log all
declare `writer: none` because they compute per request. `reports` is the one row
where `writer` and `state` diverge: it writes no report data (`read-only`) but
each download records an audit row (`writer: ops`). Ask *which class persists* —
if the answer is "none here", so is the writer.

### Derive registers; never store a snapshot

The information-asset register (§33) is **computed per request**. A stored
compliance snapshot goes stale on the next migration, which is worse than no
register. Carry-ons:

- **Presence, never value.** Seed a real key, then assert the secret appears in
  neither the register nor the rendered page.
- **Vocabulary copied verbatim** from the WordPress constants (4 ISO
  classifications, 8 asset types) so one set of report labels survives a runtime
  move — `reports` keys off them.
- Omit a section rather than emit it empty: a "Risks" heading with no rows is a
  claim. `AdminComplianceReportTest` asserts the omission.

### Two presentation traps

- **`fputcsv()` quotes any field containing whitespace**, so a sectioned report's
  heading is written `"Audit trail (newest 1000)"`. Assert the bytes the writer
  produces or parse the rows; do not assert a bare heading.
- **`cp -a` never deletes.** Removing a page from the source tree leaves the stale
  copy in the container and `SurfaceOwnershipTest` fails on an undeclared Filament
  surface. When a slice *removes* files, delete them on both sides.

### What is deliberately absent — do not reopen without the recorded reason

| Key | State | Why |
|---|---|---|
| `chat-profiles` | `absent` | Proposal 015 is WordPress-only: registry, user-meta preference and the `wp_mcp_ai_before_tool_execution` gate have no counterpart here. A toggle with no enforcement is worse than no page. A real port needs the **gate first**, then the preference (`SettingsService` already has `user` scope), then the page. |
| `onboarding` | `absent` | Its two writes already have homes (`/ops/settings` for provider keys, `/admin/assistants` for presets). A first-run experience would be a derived checklist, not a wizard. |
| `datasets` | `planned` | The eleven HuggingFace tools are in `lib/core` but are now explicitly **`unavailable`** (#7944) — they call `datasets-server.huggingface.co` (≤100 rows per request, gated datasets need a token), and serving them without evidence was the objection. `unavailable` and `unverified` are both non-servable, so registry behaviour is unchanged; what changed is that the blocker is now **evidence** (per-tool tests + a documented token path), not classification. `ToolReadinessClassificationTest` pins the consequence — flipping one to `ready` requires tests, not a string edit. The dataset browser itself is not built (a browser needs a servable catalogue to browse). |
| `identity-provider` | `planned` | Nothing to port — no SSO implementation exists on either runtime. Needs a scope decision (OIDC, or OIDC+SAML) before any code. |

### Branding (§38)

`App\Support\Brand\BrandMark` is the one lockup definition for both panels, and
asset paths are **root-relative** (`asset()` bakes in `APP_URL` and 404s on
another host or port). The wordmark SVG is a **black core with a white highlight
rim**: six white shapes plus six unclassed copies offset `+3,+3` that inherit
black. So the bare wordmark is solid black on light and nearly invisible on dark;
the dark variant is produced mechanically by adding `fill="#fff"` to the root
`<svg>`, with no path data touched. The mark needs no variant.
`PanelBrandingTest` pins geometry parity between the two variants and parses every
committed SVG.

- A panel-provider change needs `docker restart` — `OCTANE_MAX_REQUESTS=1` does not
  pick up a favicon or logo change.
- The tab favicon is still a **different mark** from the lockup icon (known
  inconsistency, plan §38.6). Changing it is an explicit decision, not a side
  effect of a layout change.

## 16. The catalogue, the ledger and the export (149 §60–§81, v1.2.22)

The admin-parity surface closed with three data-layer subsystems. All three share
one failure mode: **something is wired, running, and reading nothing.**

### The settings catalogue is the source of truth for endpoint names

`docker/laravel/app-skeleton/resources/settings-catalogue.json` is generated from
the WordPress settings surface and is authoritative for **names**. The adapters
were wrong, not the catalogue: `getApiBaseUrl()` read `gemini_api_base_url` while
the surface writes `gemini_base_url` (same shape for `lm_studio_*`, `nvidia_nim_*`,
`ollama_*`), so four base URLs silently never applied and three overrides
(Hugging Face, Baseten, Crawl4AI) were unreachable.

- **`bin/check-endpoint-wiring.php` is the gate** — 52 checks, both directions,
  wired into `composer run ci:all` (`quality:endpoint-wiring`) and the
  `laravel-app` workflow. It fails 2 checks against the pre-fix mapping, so it is
  not vacuous.
- Keep a `$legacyMap` fallback when renaming a storage key: rows written under the
  old name stay readable, and the surface key wins when both exist.
- **`writableFor()` is the write authority**, not the form. A tenant posting an
  operator key gets a server-side refusal; `/ops` reads operator secrets masked.
- A page that prints a literal default instead of `stored_options()` posts that
  default straight back over the saved value on the next save — silent data loss
  with no error. `test-toolkit-settings-persistence.php` has **four** directions;
  the display one is the newest and the one that catches it.

### The model catalogue is seeded, not authored

`resources/model-catalog.json` is generated from the plugin's
`includes/data/model-catalog.json` by `bin/generate-laravel-model-catalog.php`
(**per 1M** here; the source is per 1K). It seeds `nvoos_model_configs`, and the
table is the working copy.

- **Identity is `(provider, model_id)`.** A `unique(model_id)` migration silently
  dropped one of 251 rows: the catalogue publishes `gpt-4o` twice (an Azure
  deployment and a deprecated OpenAI model). WordPress's id-keyed option store
  collapses the pair; this runtime keeps both and prefers the servable row for a
  label.
- **Sync is additive** (`insertOrIgnore`, chunks of 100, never an update) — the
  JSON is the seed and an operator's edited price must survive a re-sync.
- `ModelCatalogueLookup` implements `ModelRoutingCatalogueInterface`
  (`fallbackFor()`, `serves()`) and `ModelLimitCatalogueInterface` (`limitFor()`).
  **Null is the answer that matters**: no catalogue, no row, an unlisted id or a
  self-referential fallback all mean "leave the request alone". Only a definite
  `false` from `serves()` drops a model — a fine-tune or deployment name is
  unknown to the catalogue and must keep its override.
- **`nvidia_nim` ↔ `nvidia`.** The client reports `nvidia_nim`, the catalogue
  stores `nvidia`; untranslated, every NVIDIA fallback was silently dead. Accept
  either spelling in the adapter.
- **`setTokenBudgetManager()` existed and was never called** — every client's
  budget was null, so pre-flight context-window validation returned immediately.
  It is now bound once in `NvoosServiceProvider` and attached by
  `ProviderRegistrar`. When adding a client, confirm it receives the manager.
- The seed lives under `resources/`, not `database/`, and 13 settings selects read
  `options_source: "model_catalog:<provider>"` — a new text-model select without
  that marker will not list a catalogue-only id.

### Token usage: write, prune, roll up — in that order

- `RecordTokenUsage` handles `CostCalculated` (fires on **both** chat paths and
  carries the turn's accumulated totals). **Do not add `Event::listen()`**: the app
  auto-discovers `app/Listeners`, and an explicit registration double-writes (two
  rows per turn). A test asserts exactly one listener.
- Retention (`nvoos:prune-token-usage`, 03:30) **clamps to the last rolled-up
  day** and deletes nothing when no rollup ran — the same direction as "never
  delete unexported rows". The consequence belongs in the config comment:
  disabling the rollup disables pruning.
- `nvoos:rollup-token-usage` (03:10) upserts on `(day, provider, model_id)`, never
  rolls up today, and keeps seven days of overlap to heal a skipped run.
  Aggregate in PHP over id-batched reads, not SQL: extracting `metadata.provider`
  differs between MySQL and SQLite, and the suite runs on SQLite while production
  runs on MySQL.
- `TokenUsage::trend()` falls back to the aggregate for a day the raw rows no
  longer cover.

### Routing export is at-least-once, and the id is the contract

- **Two operator pages read the chain end to end:** `/ops/token-analytics` (#7942 — `TokenAnalyticsReport` over the rollup table) and `/ops/observability` (#7943 — `TraceRuns` over harness traces). Both are read-only report pages; a report class is the right seam because the page and any future exporter must read the same figures.
- Every event carries `nvoos.routing.event_id` (OTLP `intValue`, string on the
  wire) / `id` (flat export). A crash between the collector's 2xx and the
  watermark write resends the batch; **the collector dedupes on that id**, and a
  collector that ignores it double-counts. That belongs in
  `docs/operations/routing-export.md`, not only in the plan.
- `RoutingExportStore::backoffState()` is the single source for the command gate,
  the page sentence and the `routing_export` API block. A second copy of the
  `backoffUntil() < now()` rule will drift.
- Gauge shape: `retry_after` is `null` whenever `backing_off` is false (so a
  scraper never compares against its own clock), and an unconfigured exporter
  reports `configured: false` with zeroes — "not backing off" alone is
  indistinguishable from healthy.
- **There is no `NVOOS_ROUTING_EXPORT_HEADERS` env var.** Headers are the
  `oos.routing.export.headers` config map; a malformed JSON env value would fail
  at request time rather than at boot.

### Scheduled mail: three traps, and one fixture name

- **`Mail::fake()` records the mailable WITHOUT applying recipients.** `getTo()`
  is undefined on the recorded instance (`BadMethodCallException`), and
  `assertSent(fn ($m) => $m->to[0]['address'] === ...)` fails for the same reason.
  `$mailable->hasTo('a@b.test')` is the assertion that works; the recipient is
  only attached when the real transport sends.
- **`Mail::fake()` also does not render.** A broken Blade template passes every
  structural assertion. Call `$mailable->render()` inside the `assertSent`
  closure and assert on the body, or the test proves nothing about the mail a
  person receives.
- **A scheduled command must be inert before its migration ran.** Guard with
  `Schema::hasTable()` on *every* table it touches and return `SUCCESS`, not
  `FAILURE` — a schedule entry failing every morning at 08:00 is an alert nobody
  can action.
- **The seeded default estate is named "Default estate".** A test helper that
  does `Tenant::query()->firstOrCreate(['id' => $id], ['name' => 'Estate '.$id])`
  gets that name for `default` and its own fallback for every other id, so a
  subject-line assertion written against the fallback fails only on `default`.

### A contract row's `files` may only name Filament page classes

`SurfaceOwnershipTest::test_every_filament_surface_file_is_declared_and_exists`
asserts **set equality** between the union of every row's `files` and the files
actually present under `app/Filament/`. Two consequences:

- Adding a `resources/views/...blade.php`, an `app/Mail` or an
  `app/Console/Commands` class to a row's `files` fails the suite — that list is
  "the Filament surfaces", not "everything this row touches".
- Adding a page without a row fails it too, which is the intended direction.

Name supporting files in the row's `notes` (as prose) instead.

**A new Filament page is three edits, not one.** The class, the
`OperatorNavigationGroup::items()` entry (or the admin equivalent), and the
`contracts/surface-ownership.json` row. Two of the three fail loudly:
`OperatorNavigationTest` compares the rendered sidebar to the enum's ordered
`items()`, so the page's `navigationSort` must produce the order the list
declares; `SurfaceOwnershipTest` fails on an undeclared file. The third has no
test — a page without its nav entry renders at its slug but is invisible in the
sidebar. 2026-11-21: the `/ops` machine-tools picker shipped without the contract
row and took the 1,749-test suite red on exactly that assertion.

## 17. When a feature looks unbuilt: read the code, not the filenames

- **A negative result from a filename search is a statement about filenames.**
  2026-11-20: I reported the JIT-provisioning slice (I5b) unstarted because
  `ls database/migrations | grep estate_domains` found nothing. The migration is
  `2026_11_20_170000_create_jit_provisioning_tables.php` and creates
  `nvoos_estate_domains` **and** `nvoos_jit_provisioning_requests`; three files
  reference the table by name, so `grep -rl nvoos_estate_domains` would have found
  it immediately. Grep contents, then read the migration.
- **Check the plan for a section that already claims the work before rebuilding
  it.** §110 was titled *"Just-in-time provisioning — the gate, the list, and the
  dead end made visible"* and listed its four gates; the claim was one `grep` of
  the plan away. A plan section is cheap evidence and a rebuild is not.
- **When it does turn out to be built, audit rather than move on.** §116 came out
  of auditing §110 against published JIT guidance and found exactly one
  decided-but-unbuilt item (158 D3 rule 3, the subdomain-inheritance flag,
  recorded in §110.3 as *"the flag is not built"*) plus two stale references — a
  `{@see self::verify()}` naming a method that does not exist, and prose still
  saying the flag was unbuilt. A decision written down and silently not
  implemented is the defect a reader of the decision will not find.

## 18. Line endings: CRLF looks like a style regression (2026-11-21)

On this Windows setup, files written by tooling (shell heredocs, Python) can land as
CRLF even though the repo is LF-only. `.gitattributes` (`* text=auto eol=lf`,
`*.php text eol=lf`) normalises the committed blob, so **CI is unaffected** — but
`phpcs` lints the *working tree*, so `Generic.Files.LineEndings.InvalidEOLChar` fires
on every file you touched and reads as style debt you introduced.

- Python's text-mode write translates `\n` to `os.linesep` (CRLF on Windows). Pass
  `newline=''` whenever the file must stay LF:

  ```python
  s = io.open(p, encoding='utf-8', newline='').read().replace('\r\n', '\n')
  io.open(p, 'w', encoding='utf-8', newline='').write(s)
  ```
- **Attribute phpcs errors to lines, not totals.** A run reported 24 errors across
  six touched files; every one sat on a line the change had not touched
  (`__DIR__.'/…'`, `$path.'/{id}'`, `self::HINT.' '.$reason`). Read the reported line
  numbers against your own diff before saying either "pre-existing" or "clean" — the
  count alone supports neither.
- Verify with `php -l` and a re-run of phpcs after normalising; a heredoc-written
  file also needs the EOL pass, not just Python edits.

## 19. The adapter suite, and the bug class only it catches (2026-11-23)

Neither `lib/core` nor `lib/laravel-adapter` commits a `vendor`, so the package
suite cannot run from the tree as checked out. Copy the two directories (13 MB) to
a scratch location that preserves the `../core` path-repo layout, then let the
container resolve and run:

```sh
mkdir -p <scratch> && cp -r lib/core lib/laravel-adapter <scratch>/
docker run --rm -v "<scratch>:/pkg" -w /pkg/laravel-adapter \
  -e COMPOSER_HOME=/tmp/composer oos-laravel-app \
  sh -c 'composer update --no-interaction --prefer-dist --no-progress \
         && vendor/bin/phpunit --no-coverage'
```

`oos-laravel-app` has PHP 8.3 and Composer 2.10, and packagist is reachable from
it, so `composer update` is the whole setup (~1 min, Testbench included). One
test resolves `tests/../../../docker/laravel/app-skeleton/resources/settings/
catalogue.json`, so a scratch tree also needs the repo's `docker/` mounted at
`/docker` or two `SettingsStoreEndpointWiringTest` cases fail for the wrong
reason.

**Why the setup is worth it: a shadowed binding hides a broken default.** The
app's `AppServiceProvider` binds many of the same contracts the package binds, so
a package binding that lands on a *nonexistent class name* is never asked for in
the app and the app suite stays green. The package suite is the only gate that
boots `NvoosServiceProvider` on its own.

**The bug class: a lost `use` line is silent.** PHP resolves an unimported
`Foo::class` to `<current namespace>\Foo` with no error and no autoload, so
deleting an import turns a binding into a no-op. The failure is delayed and
alphabetical: the registry still builds, then the first tool whose constructor
*declares* the real contract cannot be constructed, and every slug after it never
registers. The tells in `ToolReadinessRegistrationTest`:

- `nvoos.tools.skipped` reads `{"<tool>": "service not bound: <Contract>"}`;
- `testRegisteredSetMatchesTheManifest` diffs from mid-alphabet to `z`, not on a
  single slug.

Detect in one pass: every `<Alias>::class`, `new <Alias>` and `<Alias> $param` in
the provider must have a matching `use`, because `Nvoos\Laravel\ServiceProvider\
Foo` never exists. The sweep that found this one also found two more: an
unimported `TenantResolverInterface` (so `AgentOrchestration` always namespaced
its cache under `default`, whatever estate was active) and `Domain\Exception\*`
imports in the workflow controller (the classes live under `Domain\Error\*`, so a
refused write answered 500 instead of 403).

**Run long suites against a snapshot.** Another agent editing
`docker/laravel/app-skeleton/tests` during a 20-minute run kills PHPUnit at
suite-build with `classNameFromFileName(): Argument #1 ($suiteClassFile) must be
of type string, false given` — it scanned a file that was then deleted. Copy the
skeleton (`cp -a docker/laravel/app-skeleton <scratch>/appsnap`) and mount that;
the vendor volume's `nvoos/*` symlinks still point at `/workspace/oos/lib/…`, so
`lib/` edits stay live. Mount `oos-laravel_laravel_storage` for speed: without it
the suite runs 2–3× slower (~4.5 s/test).


## 20. Machine principals, estate scope, the root key and the file registry (v1.2.23)

Moved to `reference/machine-principals-and-estate-scope.md` — the `nvoos:machine-token`
refusals (`*`/`any`, estate-bound assistant scopes, the 90-day PAT baseline), the tenant
columns behind estate scope, `BudgetGuard`'s **429 `budget_exceeded`** /
**503 `budget_unverifiable`** contract, the root key's **423** semantics and why the
gate lives in `SettingsService::write()` rather than middleware, the `nvoos_files`
visibility/owner/slug model with `GET /api/v1/files/{id}`, the skill manager's write
rules, per-tool quotas, the estate-overlay profession catalogue, magic-link/Auth0
identity, and the media worker's two-step video contract with path-segment validation.
