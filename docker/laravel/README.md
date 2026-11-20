# NV oOS Laravel Spike (FrankenPHP / Octane)

Dev-only environment proving `nvoos/core` + `nvoos/laravel-adapter` on
Laravel Octane with FrankenPHP, MySQL, and Redis — no WordPress in the
request path.

Evidence and pass criteria: [`docs/project/plans/090-laravel-adapter-spike-report.md`](../../docs/project/plans/090-laravel-adapter-spike-report.md).

## Start

```bash
docker compose -f docker/docker-compose.laravel.yml up -d --build
```

Services:

| Service | Role |
|---|---|
| `setup` | One-shot: copy `app-skeleton`, Composer install, migrate, exit |
| `app` | Octane/FrankenPHP on <http://localhost:8001> |
| `worker` | `queue:work redis --queue=oos` |
| `scheduler` | `schedule:work` — one tick a minute: `nvoos:run-schedules` (due Schedule Manager rows), `nvoos:fire-triggers`, `nvoos:sample-health`, the optional media sweep |
| `db` | MySQL 8 |
| `redis` | Redis 7 (cache + queue) |

The runtime app is written to `docker/laravel/app/` (gitignored). The
committed source is `docker/laravel/app-skeleton/`.

## Verify

```bash
bash bin/laravel-spike-smoke.sh --no-build
```

Endpoints:

| Endpoint | Auth | Purpose |
|---|---|---|
| `GET /health` | none | Bindings, tools, providers, worker runtime, `ABSPATH` guard |
| `POST /dev/tools/{slug}` | Sanctum | Execute a core tool (dev/testing only) |
| `POST /chat/stream` | Sanctum | SSE chat stream |
| `POST /mcp` | Sanctum | MCP Streamable HTTP (modern + legacy eras) |

Get a token:

```bash
docker compose -f docker/docker-compose.laravel.yml exec -T app php artisan nvoos:spike-token
```

## Soak / benchmark

```bash
TOKEN=$(docker compose -f docker/docker-compose.laravel.yml exec -T app php artisan nvoos:spike-token | tail -n1 | tr -d '\r')
SPIKE_TOKEN="$TOKEN" php bin/laravel-spike-soak.php --streams=10 --seconds=1800

OCTANE_MAX_REQUESTS=500 docker compose -f docker/docker-compose.laravel.yml up -d --force-recreate app
php bin/laravel-spike-bench.php --url=http://localhost:8001/health --requests=200
OCTANE_MAX_REQUESTS=1 docker compose -f docker/docker-compose.laravel.yml up -d --force-recreate app
```

`OCTANE_MAX_REQUESTS=1` is the dev reload mode; use `500` for benchmarks.

## Notes

- `config/octane.php` sets `max_execution_time=0` for long SSE streams.
  Production must use a bounded value.
- The adapter's `SettingsStore` has no in-memory cache; `ProviderRouter` is
  a singleton, while `ChatOrchestrator` and `SseHandler` are `scoped`.
- Tests force SQLite/sync in `tests/bootstrap.php` so Docker's MySQL/Redis
  environment cannot leak into `RefreshDatabase`.
- The `mcp/sdk` fallback is not needed: `laravel/mcp` v1.2 serves both
  `2026-07-28` (`server/discover`) and `2025-06-18` (`initialize`).

## Deploying to Cloudways (Flexible PHP app + Git)

The compose stack above is dev-only (FrankenPHP/Octane). A Cloudways **PHP
Flexible** application runs the same app under PHP-FPM. Deploy with
[`bin/cloudways-deploy.sh`](../../bin/cloudways-deploy.sh); this section is the
runbook that script assumes.

### Why the docroot needs a front controller

Two constraints decide the layout:

1. **Cloudways' Git deployment path is relative to the docroot.** An empty
   path deploys to `public_html/`; `repo/` deploys to `public_html/repo/` —
   there is no supported way to deploy above `public_html` (Cloudways' own
   GitLab guide states the empty-path default; the Cloudways API Git-pull
   action documents the same relative rule).
2. **Laravel must be served from `public/`.** Serving from the project root
   exposes `.env`, `vendor/` and `lib/` — the Laravel deployment docs say this
   explicitly.

So the checkout lives at `public_html/repo/`, a generated shim at the docroot
requires the real front controller, and the generated `.htaccess` denies
`/repo`.

### Layout

```
applications/<app-slug>/
└── public_html/
    ├── index.php        # generated shim: sets SCRIPT_NAME, requires the app
    ├── .htaccess        # generated: deny /repo and dotfiles, route to index.php
    ├── css/ js/ fonts/  # published from app-skeleton/public
    ├── storage -> repo/docker/laravel/app-skeleton/storage/app/public
    └── repo/            # Cloudways Git checkout (the WHOLE monorepo)
        ├── lib/ addons/ # path repositories, three levels above the app
        └── docker/laravel/app-skeleton/
            ├── .env     # created once, gitignored, denied by .htaccess
            └── vendor/
```

The app is installed **in place**. `composer.json` points at
`../../../lib/core`, `../../../lib/laravel-adapter` and
`../../../addons/nvoos-wifi`, which resolve only while the checkout keeps the
monorepo shape — so never deploy `app-skeleton/` on its own, and never copy the
app root elsewhere without rewriting those three path repositories.

On a host where the docroot is not `public_html` (or the checkout does not live
under it), set `NVOOS_PUBLIC_ROOT`. The script refuses to run when publishing
would land inside the source tree.

### Cloudways "Laravel" application type (custom webroot)

Cloudways' Laravel app type provisions a different shape: the application root
is `public_html/` and the webroot is a **nested** `public_html/public/`. There
the checkout belongs beside the webroot, not inside it:

```
public_html/
├── public/            # docroot: generated shim + published assets + storage link
└── repo/              # checkout — outside the served tree
```

```sh
cd "$HOME/public_html/repo"
NVOOS_PUBLIC_ROOT="$HOME/public_html/public" \
NVOOS_PHP_BIN="$HOME/.cw/bin/php" \
bash bin/cloudways-deploy.sh
```

- **`NVOOS_PUBLIC_ROOT` is mandatory here.** The script's sibling-`repo`
  heuristic would choose `public_html`, which is *not* the docroot.
- **No `.htaccess` protection is needed** — `/repo` is outside the docroot
  (`curl -s -o /dev/null -w '%{http_code}' https://<host>/repo/` → 404), so an
  nginx-only stack is fine and the generated `.htaccess` is inert.
- **The checkout can be cloned over HTTPS**: the GitHub repo is public, so
  `git clone -b alpha-working https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git repo`
  needs no deploy key. Cloudways' Git panel is then unnecessary — its key lives
  in a root-owned `~/.ssh`, so the app user cannot reuse it anyway.
- **The web runtime follows `.php_version`, not `php` in `PATH`.** Switch PHP in
  Application Settings, then make `cat .php_version` and
  `php -r 'echo PHP_VERSION;'` agree before deploying. `/etc/php/8.3` and
  `/usr/bin/php8.3` are present on a Cloudways box, which lets you run the CLI
  steps before the FPM pool is switched.

### One-time setup

1. **Application:** PHP Flexible, PHP **8.3** (the lock's floor is 8.2, but CI
   pins 8.3 for `laminas-diactoros` 3.10 and `pint` 1.32), **Apache** for the
   Flexible layout (the generated `.htaccess` needs `AllowOverride`, and `/repo`
   sits inside the docroot); the Laravel app type below is fine on nginx because
   the checkout stays outside the webroot. MySQL 8.
2. **Extensions:** `pdo_mysql`, `mbstring`, `fileinfo`, `intl`, `zip`, `ctype`,
   `curl`, `dom`, `openssl`, `tokenizer`, `xml`, `session`, `opcache`; plus
   `redis` if you use it and `pcntl` if you want `queue:work --timeout`
   enforced. `composer check-platform-reqs --lock --no-dev` is the preflight
   (it names `intl` via filament/support, `zip` via openspout, `fileinfo` via
   flysystem-local).
3. **Deployment via Git:** SSH remote only (Cloudways does not accept HTTPS
   remotes), branch, deployment path **`repo/`** — relative to `public_html`,
   trailing slash required.
4. **First run:** `bash ~/applications/<app>/repo/bin/cloudways-deploy.sh`.
   It creates `.env` from `.env.example` and exits `2` with a checklist —
   before installing anything or touching the docroot. Fill `.env` in and run
   it again: that run installs dependencies, generates `APP_KEY`, migrates,
   warms the caches, and only then swaps the docroot.
5. **SSH user:** the application's own SSH user, not `master` — the files must
   stay owned by the PHP-FPM user.
6. **CLI PHP:** if the server's default `php` is not the app's version, pass
   `NVOOS_PHP_BIN=/usr/local/bin/php83` (or wherever it lives). The script
   prints the binary it will use and refuses anything below 8.2.

### `.env`

```dotenv
APP_NAME="NV oOS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.com

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
# Cloudways names the database after the db user.
DB_DATABASE=<dbname>
DB_USERNAME=<dbuser>
DB_PASSWORD=<dbpass>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
# Omit for the exact host.
SESSION_DOMAIN=.example.com

CACHE_STORE=database
QUEUE_CONNECTION=database

# Only needed for extra browser origins: Sanctum already trusts the APP_URL
# host through Sanctum::currentApplicationUrlWithPort().
# SANCTUM_STATEFUL_DOMAINS=app.example.com

# The dev default `allow` entitles every feature. Use `config` or `license`.
NVOOS_PRO_ENTITLEMENT_DRIVER=config
```

With the Cloudways Redis add-on, set `CACHE_STORE`, `QUEUE_CONNECTION` and
`SESSION_DRIVER` to `redis` and fill `REDIS_HOST` / `REDIS_PASSWORD` /
`REDIS_PORT`; the `database` drivers need no extra setup because the stock
`sessions`, `cache` and `jobs` tables ship with the migrations.

`NVOOS_TOKENS_PATH` needs no override: its default
(`base_path('../../../examples/nvoos-astro-velocity/src/tokens')`) resolves
from the checkout.

### After every deploy

| Need | Cloudways |
|---|---|
| Scheduler (`nvoos:fire-triggers`, `nvoos:run-schedules`, `nvoos:sample-health`, rollups, pruning) | Cron Job Manager, every minute: `cd <app> && php artisan schedule:run >> /dev/null 2>&1` |
| Queue worker (`oos` queue) | Application → Supervisor, command `php <app>/artisan queue:work --queue=oos --tries=3 --backoff=5 --max-time=3600`, `stopwaitsecs` ≥ the longest job |
| No Supervisor on the plan | `QUEUE_CONNECTION=sync`, or a per-minute cron `queue:work --stop-when-empty` |

The script already runs `queue:restart` (required after new code lands) and
`schedule:interrupt` (needed if sub-minute tasks are ever added).

### Operational notes from a live deployment

- **Log rotation.** `LOG_STACK=single` grows without bound. Set
  `LOG_CHANNEL=daily` and `LOG_DAILY_DAYS=14` (Laravel's documented default) —
  and re-run the deploy, because `config:cache` freezes the previous value.
- **Do not add `trustProxies(at: '*')` on Cloudways.** Apache's `mod_remoteip`
  already restores the client IP into `REMOTE_ADDR`, and the edge strips a
  forged `X-Forwarded-For`; behind Cloudflare the same holds for
  `CF-Connecting-IP`. Trusting every proxy would let a client choose its own
  rate-limit key.
- **Attaching a custom domain invalidates the `*.cloudwaysapps.com` host**, since
  the certificate follows the primary domain. Set `APP_URL` to the new origin
  and add it to `SANCTUM_STATEFUL_DOMAINS`; without both, console sign-in answers
  `419 session_required`. Re-run the deploy so `config:cache` picks them up.
- **`artisan tinker` can fail on a hardened home directory** (psysh cannot
  create `~/.config/psysh`): run it as
  `XDG_CONFIG_HOME="$HOME/tmp" php artisan tinker --execute='…'`.

### First administrator

`DatabaseSeeder` is dev-only by design, and the `nvoos` operator estate is
seeded **only outside production** — a deploy must not manufacture staff
access. Create both by hand:

```sh
php artisan tinker
```

```php
$u = App\Models\User::create([
    'name' => 'Ops', 'email' => 'ops@example.com',
    'password' => bcrypt('<strong-password>'), 'role' => 'administrator',
]);
$u->tenants()->syncWithoutDetaching(['default' => ['role' => 'owner']]);   // /admin

$estate = App\Models\Tenant::firstOrCreate(
    ['id' => 'nvoos'],
    ['name' => 'NV oOS (operator)', 'slug' => 'nvoos', 'is_operator' => true]
);
$estate->users()->syncWithoutDetaching([$u->id => ['role' => 'owner']]);  // /ops
```

### Verification

```sh
curl -fsS https://<host>/health          # ok:true, abspath:false
curl -fsS https://<host>/api/v1/features # 200, endpoint_gaps: []
php artisan schedule:list
php artisan nvoos:doctor

# Locally, before deploying. Hermetic: stubs PHP/Composer/rsync, writes to a
# temp docroot, and restores app-skeleton/.env. The Laravel App CI job runs it.
bash bin/cloudways-deploy-smoke.sh
```

### Standards applied

| Practice | Source | Where |
|---|---|---|
| Docroot is `public/`, never the project root | Laravel 12 deployment | shim + generated `.htaccess` |
| `php artisan optimize` (config + events + routes + views) after deploy | Laravel 12 deployment | script step; route caching is safe here — `route:cache` succeeds on the pinned 12.72 (closure routes are serialised), and this app names no routes |
| `queue:restart` after new code | Laravel 12 queues | script step |
| One `schedule:run` cron entry per minute | Laravel 12 scheduling | Cron Job Manager |
| Supervisor with `stopwaitsecs` ≥ longest job | Laravel 12 queues | Supervisor entry |
| `composer install --no-dev -o`, verify with `check-platform-reqs` | Composer CLI docs | preflight + install |
| Build/release/run separated; config comes from the environment | 12-factor V | the script is the release step; it starts no processes |
| `.env` never web-reachable | Laravel deployment warning | `/repo` denied at the docroot |

### Gotchas

| Symptom | Cause | Fix |
|---|---|---|
| `Source path "/workspace/oos/lib/core" is not found` | `composer.lock` written inside the dev container | regenerate the lock outside Docker; the script refuses to deploy with it (`grep -c /workspace/oos composer.lock` must be `0`) |
| Every request 500s with `No application encryption key` | `.env` missing `APP_KEY` | `php artisan key:generate --force` |
| Panels render unstyled / 500 | Filament assets not published | re-run the script (`filament:assets`) |
| `/ops` answers 403 | No operator estate — production never seeds one | create it (above) |
| Generated URLs contain `/repo/…` | The shim is missing, so `SCRIPT_NAME` is the checkout path | re-run the script; do not point the vhost at `repo/…/public` |
| `.env` is downloadable at `/repo/docker/laravel/app-skeleton/.env` | `.htaccess` not honoured | Apache + `AllowOverride` (or the `/repo` deny rule is gone) |
| `NVOOS_TOOL_READINESS` is ignored | `config:cache` stops `.env` loading, and `ToolReadiness` reads it with `getenv()` | set it in the Supervisor/Apache environment, not `.env` |
| Queue jobs never run / schedules never fire | no worker, no cron | the two entries above |
| `/dev/tools/*` 404s | `EnsureSpikeEnvironment` blocks anything outside `local`/`testing` | intended; do not widen it |
| `nvoos:spike-token` asked for | it creates `spike@example.com` / `password` | never run it in production |
| SSE (`POST /chat/stream`) stalls | proxy buffering or PHP `max_execution_time` | the handler already sends `X-Accel-Buffering: no`; raise the PHP limit for that path |
