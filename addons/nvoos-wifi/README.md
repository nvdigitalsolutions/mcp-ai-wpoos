# NV oOS WiFi

AI-powered social WiFi and Wi-Fi observability for the NV oOS ecosystem —
branded captive portal, per-purpose consent, device-identity-aware sessions,
standards-shaped KPIs, and an MCP tool surface.

**Status: Phase 0 (portability spike).** One working tool, one REST route, a
deterministic mock controller adapter, and a domain layer that provably runs
without WordPress.

- Proposal: [`docs/project/proposals/150-nvoos-wifi-proposal.md`](../../docs/project/proposals/150-nvoos-wifi-proposal.md)
- Research: [`docs/project/proposals/150-nvoos-wifi-research.md`](../../docs/project/proposals/150-nvoos-wifi-research.md)
- Implementation plan: [`docs/project/proposals/150-nvoos-wifi-implementation-plan.md`](../../docs/project/proposals/150-nvoos-wifi-implementation-plan.md)

---

## What this addon is, and is not

It is **three product families over one domain spine**, not three products:

| Family | Question it answers | Data basis |
|---|---|---|
| **WiFi Social** | Who is here, and did they consent? | Portal + identity + consent |
| **WiFi X-Ray** | What is the radio actually doing? | Controller telemetry, DE-shaped KPIs |
| **WiFi Cognition** | What does it mean, what should we do? | Inference over the above, **labelled as inference** |

> **Brand rule:** X-Ray states measurements; Cognition states inferences and never
> presents one as the other. 802.11bf standardises *measurement* — claiming
> measurement is defensible, claiming perception is our own assertion.

Phase 0 ships only the spine. There is no portal, no consent UI and no cognition
yet, by design — see the implementation plan's exit condition.

---

## Why an addon, and why `type: library`

The owner's requirement was portability into **both** the WordPress plugin and the
Laravel runtime. "Make it an addon" is necessary but not sufficient: a Pro toolkit
under `addons/pro/includes/tools/wifi/` would be addon-shaped and still unportable,
because a Pro toolkit is WordPress-coupled **by contract** (`WP_Error` returns,
`get_option()` in `init.php`, all eleven registration surfaces are `wp-admin`
internals).

Portability therefore rests on one property: **the domain layer never learns what
host it is running in.** That property is enforced mechanically, not by review —
see [Validation](#validation).

`composer.json` declares `"type": "library"` deliberately. With
`type: wordpress-plugin` and `composer/installers` present, Composer would relocate
this package into `wp-content/plugins` when required from the Laravel app — a
silent, confusing failure. The WordPress host loads `includes/Plugin.php` directly
from `nvoos-wifi.php`; it does not need Composer at all.

---

## Layering contract

```
includes/
├── Domain/          ← ZERO WordPress, ZERO I/O, PHP stdlib only
├── Interfaces/      ← ports: what the outside world must provide
├── Services/        ← use-cases; injected ports only
├── Infrastructure/  ← the only place that may touch WP, HTTP or a controller
│   ├── Wp/          ←   WordPress port implementations
│   └── Providers/   ←   controller adapters (mock first, real later)
├── REST/            ← REST controllers
└── Tools/           ← MCP tool classes (base registry, no Pro required)
```

| Layer | May | May not | Enforced by |
|---|---|---|---|
| `Domain/` | Pure PHP, value objects, invariants | `WP_*`, `$wpdb`, `get_option`, I/O, `WP_Error` | `quality:domain-purity` **strict** |
| `Interfaces/` | Declare signatures | Contain logic | `quality:domain-purity` strict |
| `Services/` | Orchestrate domain + ports | Reach a global or an option | `quality:domain-purity` strict |
| `Infrastructure/` | Call WordPress, HTTP, controllers | Contain business rules | phpcs |
| `Tools/` | Canonical envelope, two-gate sanitisation | `array('success'=>false)` | `WPMCPAI.Tools.*` sniffs, sev 5 |

**Note the deliberate absence of `ABSPATH` guards in `Domain/`, `Interfaces/` and
`Services/`.** A guard would make them unusable outside WordPress, defeating the
entire design. The guard belongs in `nvoos-wifi.php` and WordPress-facing files
only.

### The `Result` rule

The domain cannot return `WP_Error` — that is a WordPress class. It returns
`NvoosWifi\Domain\Result`, and each host maps it at the boundary:

- WordPress: `Class_Result_Mapper::to_wp_error()` → `WP_Error` with a distinct
  HTTP status per error code (auth vs unreachable vs rate-limited are *not*
  collapsed into one message).
- Laravel: exception / `JsonResponse`.

---

## Public surface

### MCP tool

| Slug | Capability | Flags | Phase |
|---|---|---|---|
| `wifi_health_check` | `manage_options` | read-only, local-only, idempotent, cacheable | 0 |

Registered through the **Base** filter `wp_mcp_ai_default_tools`
(`array<class_name => absolute_file_path>`), so WiFi tools work without a Pro
licence. The filter is added at **file scope** in `nvoos-wifi.php`, not on
`plugins_loaded` — the registry may consume it before a late callback runs.

### REST

```
GET /wp-json/nvoos-wifi/v1/venues/{id}/kpis?from=<epoch>&to=<epoch>
```

`permission_callback` requires `manage_options` (Phase 1 replaces this with a
per-venue capability once venue ownership exists). The handler adds **no logic of
its own** — it calls `Class_Kpi_Service::for_venue()` and returns the domain
payload, because the Laravel host must be able to produce identical bytes.

### Options

| Option | Autoload | Contents |
|---|---|---|
| `nvoos_wifi_settings` | yes | Adapter selection (`mock_seed` in Phase 0) |
| `nvoos_wifi_venues` | yes | Venue records keyed by id |
| `nvoos_wifi_kpi_<md5(venue_id)>` | **no** | KPI samples, capped at 500/venue |

Uninstall **preserves** all of it by default — consent records are legal evidence
under Jamaica's DPA 2020. Opt in to deletion with
`define( 'NVOOS_WIFI_REMOVE_DATA_ON_UNINSTALL', true );`.

---

## Design decisions worth not re-litigating

| Decision | Reason |
|---|---|
| **No MAC identity anywhere** | 802.11bh-2024 introduced Device ID; MACs randomise by design (RFC 9724) and inflate unique counts 300–500%. `Device_Identity::make()` **rejects MAC-shaped values**. |
| **Entity counts require a `method`** | A number without a counting method is not a measurement. `Kpi_Sample::make()` fails with `method_required`. |
| **Identity KPIs are withheld, not guessed** | When an adapter reports `identity_method() === null`, those KPIs are skipped and listed in `skipped` with reason `no_identity_method`. |
| **`null` is not `0`** | A missing denominator is an absence. No divide-by-zero, no fake 100%. |
| **Mock adapter ships before the real one** | Pattern from `@subashgautam/router-mcp`: tests, demos and CI must not need hardware. |
| **Adapters report native keys + a map** | Normalisation lives in one place, so a second controller is a small job. |
| **FreeRADIUS is the AAA authority** | `radius` on npm is a 2018 codec with no EAP state machine. We configure and observe RADIUS; we do not reimplement 802.1X. Non-goal. |
| **Sensing is Phase 4** | 802.11bf standardises measurement; inference is our liability and a different buyer. |

---

## Laravel host

Proposal 150's portability claim is only real if a second host consumes the same
domain. The Laravel half lives in the app skeleton and is deliberately tiny — five
small classes plus a provider:

| Piece | File | Role |
|---|---|---|
| Provider | `app/Providers/WifiServiceProvider.php` | Binds the four ports; mirrors `includes/Plugin.php` |
| Controller | `app/Http/Controllers/Wifi/KpiController.php` | The twin of `includes/REST/Class_Kpi_Controller.php` |
| Config | `config/wifi.php` | Venue records (the WP host uses the `nvoos_wifi_venues` option) |
| Clock | `app/Wifi/Clock.php` | `time()` — UTC epoch only |
| Id generator | `app/Wifi/IdGenerator.php` | `dev_` / `ses_` random tokens |
| Sink | `app/Wifi/CacheKpiSink.php` | Cache-backed, same idempotency rules as the WP sink |
| Venue lookup | `app/Wifi/VenueRepository.php` | Reads config, hydrates the same portable `Venue` |

Route: `GET /api/v1/wifi/venues/{venue}/kpis` inside the `can:manage_options`
group.

**What is reused, not reimplemented:** `Class_Mock_Controller_Adapter` (pure PHP, so
both hosts get identical deterministic telemetry), `Class_Kpi_Service`, every
`Domain\*` value object and every port. That reuse is what makes the parity fixture
meaningful rather than decorative.

### Two-sided fixture contract

Each runtime pins its **own committed copy** of the same bytes:

- WordPress: `addons/nvoos-wifi/tests/fixtures/kpis-venue-1-window-1.json`
- Laravel: `docker/laravel/app-skeleton/tests/fixtures/wifi/kpis-venue-1-window-1.json`

The duplication is the mechanism: a change to the portable service fails **both**
suites until both fixtures are regenerated, so neither runtime can drift silently.

```bash
php addons/nvoos-wifi/tests/standalone-domain-checks.php --write-fixture
cp addons/nvoos-wifi/tests/fixtures/kpis-venue-1-window-1.json \
   docker/laravel/app-skeleton/tests/fixtures/wifi/
```

### ⚠️ Laravel code must not touch the WordPress trees

`NvoosWifi\Infrastructure\Wp\*`, `NvoosWifi\REST\*` and `WP_MCP_AI_Tool_*` all carry
an `ABSPATH` guard that `exit`s. Referencing one from Laravel terminates the request
silently. The package's `exclude-from-classmap` keeps them out of an optimised
classmap, but PSR-4 can still resolve them — the standing rule is the real guard, and
it is restated at the top of `WifiServiceProvider`.

---

## Continuous integration

`.github/workflows/nvoos-wifi.yml` runs on any PR touching the addon, the gate, the
sniffs, or the Laravel WiFi host. Three jobs:

| Job | Runs | Why it exists |
|---|---|---|
| `portability` | PHP **8.1**, **no dependencies** | The addon's declared floor is the WordPress host's, not Laravel's 8.2. Runs the strict gate, the standalone domain checks, the Laravel host conformance check, a fixture-drift `diff`, and `php -l`. |
| `static` | PHP 8.2 + Composer | Addon phpcs with the four WPMCPAI tool sniffs. **Asserts the four sniff files exist first** — a relative `<rule ref>` that does not resolve is a silent no-op that passes while enforcing nothing. |
| `unit` | PHP 8.1 + Composer | `vendor/bin/phpunit -c addons/nvoos-wifi/phpunit.unit.xml` — and **fails if that config ever gains a `bootstrap=`**, because a WordPress bootstrap in the domain suite would make the portability guarantee an illusion. |

### Why the gate is scoped, and why `--strict` alone would not work

`composer run quality:domain-purity:strict` cannot run repo-wide: `--strict` fails on
*any* finding, and the transitional `includes/services/` + `includes/repositories/`
layers already carry **1,295 advisory findings**. A job using it would be red from day
one and the flag would get quietly removed.

The gate therefore supports a layer filter:

```bash
php bin/check-domain-purity.php --only=addons/nvoos-wifi --strict
# ✓ domain purity: 11 file(s) scanned, 0 strict violations
```

`--only` restricts the scan to layers whose path contains the filter, so pre-existing
debt elsewhere cannot fail this job. A filter that matches **no** layer exits **1** —
because a job that silently scans zero layers looks exactly like a passing job.

### Keeping the two fixture copies honest

The `portability` job also runs a plain `diff` between
`addons/nvoos-wifi/tests/fixtures/kpis-venue-1-window-1.json` and
`docker/laravel/app-skeleton/tests/fixtures/wifi/kpis-venue-1-window-1.json`. The
duplication is the contract, but the copies should still be identical *when the
service has not changed* — this step catches a hand-edit to one side.

---

## Validation

```bash
# 1. Portability gate — strict for Domain/, Services/, Interfaces/
composer run quality:domain-purity; echo "exit=$?"

# 2. Domain checks with NO WordPress, NO PHPUnit, NO vendor/
php addons/nvoos-wifi/tests/standalone-domain-checks.php

# 3. Laravel host: port conformance + payload parity, without booting Laravel
composer run quality:wifi-host

# 4. Regenerate the dual-runtime parity fixture
php addons/nvoos-wifi/tests/standalone-domain-checks.php --write-fixture

# 5. Addon phpcs, including the four WPMCPAI sniffs at sev 5
vendor/bin/phpcs --standard=addons/nvoos-wifi/phpcs.xml.dist addons/nvoos-wifi/

# 6. PHPUnit — both hosts
vendor/bin/phpunit --filter 'DomainTest|NvoosWifiKpiParityTest'
docker exec oos-laravel-app php artisan test --filter=WifiKpis
```

Current state, verified on 2026-11-16:

| Check | Result |
|---|---|
| `quality:domain-purity` | **123 files, 0 strict violations** (112 core + 11 addon) |
| `quality:domain-purity --only=addons/nvoos-wifi --strict` | **11 files, 0 violations** — the CI invocation |
| `standalone-domain-checks.php` | **29 checks, 0 failures** |
| `quality:wifi-host` | **15 checks, 0 failures** — including exact key-order parity |
| `php -l` across the addon | 25 files, 0 failures |
| `sh -n docker/laravel/entrypoint.sh` | clean |
| `composer validate` (root + Laravel app) | valid, lock consistent |
| `.github/workflows/nvoos-wifi.yml` | valid YAML, 3 jobs |

### Unverified locally

`vendor/` in this checkout has no PHPUnit and no PHPCS, so **no PHPUnit suite has
been executed** — `tests/Unit/DomainTest.php`,
`tests/Integration/KpiParityTest.php` and the Laravel
`tests/Feature/WifiKpisTest.php` are written and registered but unrun. The addon
phpcs run is likewise unexecuted.

What *was* executed: the 29 standalone domain checks and the 15 host-adapter
conformance checks, which together cover the same assertions the PHPUnit suites make
at the domain level. The unexecuted part is HTTP/auth plumbing (Sanctum, the
`can:manage_options` gate, route registration) — close it with `composer install`
then the two commands in step 6 above.

---

## What Phase 1 needs next

1. Run the full suites once `composer install` lands in the dev checkout; the CI jobs
   now cover the domain half continuously.
2. Phase 1 proper: consent, portal, sessions, coupon surface, red-team suite.

---

## Neighbours

| Neighbour | Relationship |
|---|---|
| Base plugin `includes/tools-init.php` | Reference for the `wp_mcp_ai_default_tools` side-loader pattern |
| `addons/nvoos-astro-bridge/` | Structural template (PSR-4 `includes/`, own ruleset, `uninstall.php`) |
| `lib/core`, `lib/laravel-adapter` | The Laravel host's engine and adapter |
| `addons/pro/` | Pro console — WiFi contributes a manifest via `wp_mcp_ai_pro_toolkit_manifests` when Pro is present, and degrades gracefully when it is not |

## Context files to load alongside

`.context/conventions.md` · `.context/security-checklist.md` · `.context/rest-api.md` ·
`.context/tool-registry.md` · `.context/testing.md`

## Licence

GPL-3.0-or-later, matching the base plugin.
