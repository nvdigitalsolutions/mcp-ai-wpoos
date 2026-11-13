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

**Contract fixtures** live in `app-skeleton/fixtures/` and are copied the same
way. `FixtureReplayTest` reads `base_path('fixtures')`, so a fresh checkout in
the container needs the copy above. `php artisan fixtures:capture` writes a
fresh set (it defaults to `base_path('fixtures')`; the repo mount is read-only
inside the container, so capture there and copy back into the skeleton to
commit). `NVOOS_FIXTURES_DIR` overrides the path.

The `.github/workflows/laravel-app.yml` job runs the whole suite on SQLite with
the fixtures already in place — this is the only CI coverage for `docker/**`.

## 3. Validation matrix

| Suite | Command (cwd) | Baseline (captured 2026-11-13) |
|---|---|---|
| App | `docker exec oos-laravel-app php artisan test` | 158 passed / 982 assertions at #7654 — later PRs add the Paper Store, PM-write and toolkit suites, so re-capture before citing a number |
| Adapter | `php -d extension=fileinfo vendor/bin/phpunit` (`lib/laravel-adapter`) | 175 passed / 640 assertions (after #7679) |
| Core | `php -d extension=fileinfo vendor/bin/phpunit` (`lib/core`) | 1105 tests / 3982 assertions (after #7689); **5 known pre-existing** `ToolRegistryIntegrationTest` errors — do not chase |

**No CI job runs `php artisan test`.** The only gate on `docker/**` is PSR-12
via `phpcs-laravel.xml.dist` plus the licence boundary. A green local run is
the only evidence the app works — say so in the PR rather than implying CI
covered it.

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
  the default factory user is a subscriber and now gets 403.
- **The app cache is Redis** (`cache.default=redis`), not `database` — a
  `cache()` lookup in a probe hits the real store.

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

## 8. v1.2.20 storage and toolkit surfaces

- **Paper Store** — `PaperStoreInterface` + `JsonPaperStore`: `{root}/{collection}/{id}.json`, atomic temp-file + `rename`, store-owned stamps (`id`, `collection`, `type`, `schemaVersion`, `created_at`, `updated_at`), rejects invalid path segments (never normalises), `query()` returns `{records, total}`. The old `nvoos_paper_records` table is dead.
- **Runtime tables** — `nvoos_schedules`, `nvoos_schedule_runs`, `nvoos_triggers`, `nvoos_approvals`, `nvoos_files`, `nvoos_posts`, `nvoos_security_snapshots`, `nvoos_toolkit_records` ship as migrations. A fresh `php artisan migrate` must create them; if a surface says "table not found", the migration did not run.
- **Toolkit manifests** — `ToolkitManifest` (`lib/core`) validates `addons/pro/includes/toolkits/manifests/*.json`; the Laravel `Pro\ToolkitsController` serves the same `mcp-ai-pro/v1/toolkit-manifests` envelope as WordPress.
- **Profession catalogue** — `ProfessionRepositoryInterface::catalogueVersion()` + `CachedProfessionRepository` key the cache by version; a catalogue change bumps the version and invalidates without a manual purge. `save_profession` writes through `ProfessionWriteInterface` on WordPress and is deliberately **not** registered on Laravel.
- **MJML** — `MjmlTemplateBuilder` (`lib/core`) builds markup; `compile()`/`validate()` stay WordPress-side because they call the Media Worker sidecar.
