# Changelog — NV oOS WiFi

All notable changes to this addon. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [Semantic Versioning](https://semver.org/).

## [0.1.0] — 2026-11-16

Phase 0 (portability spike). Proposal 150.

### Added

- **Domain layer** (`includes/Domain/`) — zero WordPress, zero I/O, PHP stdlib only.
  - `Result` — success/failure outcome with stable error codes; the domain may not
    return `WP_Error`, so each host maps this at its boundary.
  - `Venue` — tenant root with IANA-timezone and data-residency validation.
  - `Window` — half-open `[from, to)` in UTC epoch seconds.
  - `Device_Identity` — opaque identity per 802.11bh-2024 with **MAC rejection**
    (`mac_not_allowed`) and a `weak` flag for probe-hash identities.
  - `Kpi_Schema` — 18-KPI vocabulary modelled on Wi-Fi Alliance Data Elements,
    with `identity_sensitive` flags driving the method requirement.
  - `Kpi_Sample` — enforces `method_required` for entity counts; `null` is
    preserved as absence, never coerced to `0`.
- **Ports** (`includes/Interfaces/`) — controller adapter, clock, id generator,
  KPI sink. `identity_method()` lets an adapter declare what identity it can supply,
  so entity counts are withheld rather than guessed when it can supply none.
- **`Class_Kpi_Service`** — the Phase 0 exit condition. Normalises adapter-native
  telemetry to canonical KPIs, deterministic ordering, persists idempotently.
- **Mock controller adapter** — deterministic and offline, derived from
  `crc32( seed | venue_id | native_key )`, so the parity fixture can be committed.
  Ships before any real adapter.
- **WordPress infrastructure** — system clock, random id generator, option-backed
  KPI sink (capped, idempotent), venue repository, and a `Result` → `WP_Error`
  mapper with a distinct HTTP status per error code.
- **REST** — `GET /wp-json/nvoos-wifi/v1/venues/{id}/kpis`, `manage_options`,
  passing the domain payload through unchanged.
- **Tool** — `wifi_health_check`, registered through the Base
  `wp_mcp_ai_default_tools` filter at file scope.
- **Tests** — `tests/standalone-domain-checks.php` (28 checks, runs with plain PHP,
  no WordPress and no vendor/) and `tests/Unit/DomainTest.php` (pure PHPUnit).
- **Parity fixture** — `tests/fixtures/kpis-venue-1-window-1.json`, the shared
  target for the WordPress and Laravel parity assertion.
- **Purity gate coverage** — `bin/check-domain-purity.php` now scans
  `Domain/`, `Services/` and `Interfaces/` at **strict** severity. Current state:
  123 files, 0 strict violations.
- **Laravel host** (`docker/laravel/app-skeleton`, T-0.10) — `WifiServiceProvider`,
  `Http/Controllers/Wifi/KpiController`, `config/wifi.php`, and four port
  implementations (`Clock`, `IdGenerator`, `CacheKpiSink`, `VenueRepository`).
  Route `GET /api/v1/wifi/venues/{venue}/kpis` behind `can:manage_options`. Reuses
  the addon's mock adapter and `Class_Kpi_Service` rather than reimplementing them.
- **Two-sided parity fixture** (T-0.11) — each runtime pins its own committed copy
  of the same bytes (`addons/…/tests/fixtures/` and
  `docker/laravel/app-skeleton/tests/fixtures/wifi/`), so a service change fails both
  suites until both are regenerated.
- **`bin/check-wifi-host-adapters.php`** (`composer run quality:wifi-host`) —
  verifies Laravel port conformance and exact key-order payload parity without
  booting Laravel or Docker. This is the check that caught the timestamp bug below.
- **CI** (`.github/workflows/nvoos-wifi.yml`, T-0.12) — three jobs on PRs touching
  the addon, the gate, the sniffs or the Laravel host:
  - `portability` — PHP 8.1 (the addon's floor), **no dependencies**: strict scoped
    purity gate, standalone domain checks, Laravel host conformance, fixture-drift
    `diff`, `php -l`.
  - `static` — addon phpcs with the four WPMCPAI sniffs, guarded by an assertion that
    all four sniff files resolve (an unresolved relative `<rule ref>` is a silent
    no-op in PHPCS).
  - `unit` — `phpunit.unit.xml` with **no bootstrap**, asserted by CI, so the domain
    suite can never quietly acquire a WordPress dependency.
- **`phpunit.unit.xml`** — a WordPress-free PHPUnit config for the addon's domain
  suite. The absence of a bootstrap *is* the portability test.
- **`--only=<path>` on the purity gate** — scopes the scan to matching layers and
  exits 1 when the filter matches nothing, so a CI job cannot silently scan zero
  layers.

### Fixed

- **Mock adapter stamped its measurement on the window's exclusive boundary.**
  `Window` is half-open `[from, to)` and the mock used `fixed_at` verbatim, so a
  sample could land on `to` and then be filtered out by every subsequent read —
  making the persist/read path silently empty. The adapter now clamps into
  `[from, to - 1]`, and a standalone check asserts every sample timestamp falls
  inside the reported window.

### Changed

- **Laravel path repositories are now relative** (`../../../lib/core`,
  `../../../addons/nvoos-wifi`) instead of container-only absolute paths. The lock
  already stored relative URLs, so this makes `composer.json` consistent with its
  own lock, unblocks `composer update` outside the container, and lets CI resolve
  the packages from a plain checkout. `docker/laravel/entrypoint.sh` still rewrites
  all three to absolute paths at runtime and now includes `nvoos/wifi` in its
  fallback update.

### Decisions recorded

- Location is `addons/nvoos-wifi/`, not `plugins/` (which is the Content Graph
  product family) and not `addons/pro/includes/tools/wifi/` (WP-coupled by contract,
  unreachable from Laravel).
- `composer.json` declares `type: library` so `composer/installers` cannot relocate
  the package into `wp-content/plugins` when required from Laravel.
- Uninstall **preserves data by default**; consent records are legal evidence under
  Jamaica's DPA 2020. Removal is opt-in via
  `NVOOS_WIFI_REMOVE_DATA_ON_UNINSTALL`.

### Known gaps

- **No PHPUnit suite has been executed in this checkout.** `vendor/` has no PHPUnit
  and no PHPCS, so `tests/Unit/DomainTest.php`,
  `tests/Integration/KpiParityTest.php` and the Laravel `tests/Feature/WifiKpisTest.php`
  are written, registered and unrun locally. The `unit` CI job now runs the domain
  suite in a real environment; the WP and Laravel feature tests run under their
  existing workflows. What was executed locally: 29 standalone domain checks, 15
  host-adapter conformance checks, and the full `portability` job sequence.
- Phase 0 storage is an option per venue (WP) and a cache entry per venue (Laravel);
  Phase 2 replaces both with a real store once the KPI vocabulary is frozen.
- No portal, consent UI, session policy, coupon surface or cognition layer yet —
  all Phase 1+.
