---
type: Skill
name: mcp-ai-wpoos-toolkit-creation
description: "End-to-end playbook for creating a new NV oOS Pro toolkit under addons/pro/includes/tools/<toolkit> — folder layout, service layer, tool class conventions (canonical envelope, two-gate sanitisation, capability flags), ALL eleven registration surfaces (Pro module registry loader, central registry, admin Tools section + Features subtab, admin settings page/side menu, settings status map, both WP-CLI commands, schedule presets, workflow presets, SA Toolkit Manager, coverage manifests), fixture/oracle testing with a standalone smoke harness, and the phpcs gotcha checklist. Use when asked to build a new Pro toolkit, add a toolkit to the plugin, scaffold toolkit tools, register toolkit presets/toggles, or debug why a new toolkit's tools, toggle, or admin menu don't appear. First case study: food-beverage (F&B Management, 21 tools, 2026-10-08, 20/20 Check-totals oracle verified). Complements mcp-ai-wpoos-toolkit-audit (hardening existing toolkits) and mcp-ai-wpoos-test-suite (repair)."
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.99"
  plugin-version-tested: "1.1.99"
  last-updated: "2026-10-12"
---

# NV oOS Pro Toolkit Creation Loop

operational playbook for creating a brand-new Pro toolkit end-to-end — from
folder scaffold to registered, tested, and preset-wired. The first full run was
**food-beverage** (2026-10-08: 7 services, 21 tools, 13 reports, 20/20 oracle
verification — see [Case study](#case-study-food-beverage)). CI feedback from
the same build added the workflow-preset `edges` contract and the tool-class
coverage-manifest surface (§4.9 and §4.11), and two post-merge follow-ups
caught the surfaces that first pass missed — the Features-subtab toggle wiring
(#7010) and the module-registry + admin-menu registration (F&B's settings
page) — both now called out in §4.

## When to use this skill

- "Build/create a new Pro toolkit", "add a `<vertical>` toolkit"
- "The `<toolkit>` tools/presets/toggle don't show up — where do I register them?"
- Scaffolding `addons/pro/includes/tools/<new-toolkit>/` for the first time
- A plan/proposal (e.g. `docs/project/proposals/06x-*.md`) is being implemented

## 1. Plan first, then build

- Follow the proposals convention: `docs/project/proposals/0NN-<slug>-proposal.md`
  + `0NN-<slug>-implementation-plan.md` (see 062-fnb-* for a worked example).
- If a client spec workbook exists (`*.xlsx`), dump every sheet to text first
  and treat the spec's metric/report definitions as the code contract — do not
  paraphrase them.
- Decide storage before coding: Google Drive/Sheets (via
  `WP_MCP_AI_Pro_Google_Drive_Client`), CSV fixtures, or Phase-9-style native
  CPT/CCT — the metric/report layer must stay storage-agnostic behind one
  reader interface.

## 2. Folder layout

```
addons/pro/includes/tools/<toolkit>/
├── init.php                                  # requires services; settings-defaults filter
├── README.md                                 # Purpose/Tier/Public Surface/Inputs-Outputs-Neighbors/Conventions/Tests/Also Load
├── TOOLS_LIST.txt / TOOL_INDEX.md            # one slug per line / table with anchors
├── class-wp-mcp-ai-<slug>-settings.php       # wp_mcp_ai_<slug>_settings option wrapper
├── class-wp-mcp-ai-<slug>-table-reader.php   # reader interface (ONE object structure per file — WPCS error otherwise)
├── class-wp-mcp-ai-<slug>-data-source.php    # table registry, header normalisation, transient caching
├── class-wp-mcp-ai-<slug>-fixture-reader.php # CSV adapter (tests + no-network fallback)
├── class-wp-mcp-ai-<slug>-google-sheets-reader.php # Drive adapter (CSV export via client request_raw)
├── class-wp-mcp-ai-<slug>-metrics.php        # deterministic calculation engine (one method per metric)
├── class-wp-mcp-ai-<slug>-report-builder.php # report layouts, figures only via the engine
├── class-wp-mcp-ai-<slug>-tool-base.php      # abstract base: guard(), arg helpers, requires the services
├── class-wp-mcp-ai-tool-<slug>-*.php         # one file per tool
└── tests/fixtures/*.csv                      # exported from the source data
```

The admin settings page does NOT live in this folder — it goes in
`addons/pro/includes/admin/class-wp-mcp-ai-<slug>-settings-page.php`
(registers the side admin menu; see §4.4).

Conventions: every file has the ABSPATH guard + PHPDoc header
(`@package WP_MCP_AI_Pro`, text domain `mcp-ai-wpoos-pro` for Pro-toolkit
strings, `mcp-ai-wpoos` for base-plugin admin strings).

## 3. Tool class conventions

```php
class WP_MCP_AI_Tool_Foo extends WP_MCP_AI_Foo_Tool_Base // implements WP_MCP_AI_Tool_Interface,
                                                         // WP_MCP_AI_Tool_Capability_Flags_Interface,
                                                         // WP_MCP_AI_Tool_Usage_Guidance_Interface
```

- `get_slug()/get_name()/get_description()/get_parameters_schema()` +
  `get_usage_guidance()` (when_to_use / when_not_to_use / related_tools /
  notes) — give EVERY method a `{@inheritdoc}` or full docblock; WPCS
  WordPress-Docs errors on missing doc comments (the #1 first-pass failure).
- `get_required_capability()` (`read` for read tools), `requires_base_pro()`
  returns true, `get_capability_flags()` e.g.
  `array( 'pro', 'read-only', 'cacheable', 'local-only', 'idempotent' )`.
- `execute( $arguments, $context )`: canonical envelope — `array( 'success' => true, ... )`
  or `WP_Error`, never `array( 'success' => false )`. Two-gate rule: sanitize
  every `$arguments[...]` at entry (`sanitize_text_field`, `sanitize_key`,
  `absint`), escape at exit.
- Make the abstract base require the toolkit services via `require_once
  dirname( __FILE__ )` so tools work when the registry loads them directly
  (the central registry requires the tool files itself; it does NOT require
  your init.php — init.php only loads via the Pro Module Registry, §4.1).
- Keep tools thin: sanitize args → delegate to a service → merge `success`.

## 4. The ELEVEN registration surfaces (missing any one = "tools don't show up" / "no admin menu")

Two surfaces are the most commonly missed — both bit the F&B build AFTER
merge (see [Case study](#case-study-food-beverage)): the **Pro Module Registry
loader** (nothing loads your init.php without it) and the **admin settings
page / side menu** (the user sees no admin surface even though the tools
register and work fine). Wire both in the same PR as the tool map.

1. **Pro Module Registry loader** — `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php`:
   add your slug to BOTH `$conditional_toolkits` (`'<slug>' => 'Label'` — the
   registry derives the `enable_<slug>_toolkit` key from it) AND `$dir_map`
   (`'<slug>' => '<toolkit-dir>'`). This is what requires
   `tools/<dir>/init.php` when the toggle is on. Skip it and the init's
   settings-defaults filter and admin-page load never run — the F&B build
   shipped with tools + presets but no registry entry, so init.php was dead
   code and the side admin menu never appeared even when enabled.
2. **Central registry** — `addons/pro/mcp-ai-wpoos-pro.php`,
   `wp_mcp_ai_pro_register_tools()`: add a gated block
   `if ( ! empty( $settings['enable_<slug>_toolkit'] ) ) { $pro_tools = array_merge( $pro_tools, $map ); }`
   with class → file pairs.
3. **Admin Tools section** — `includes/admin/sections/class-wp-mcp-ai-section-tools.php`:
   `'enable_<slug>_toolkit' => array( 'type' => 'checkbox', 'label', 'checkbox_label', 'description', 'default' => false )`
   AND add the key to the **"Pro Features" subtab group** in `get_subtab_groups()`
   (`'features' => array( ..., 'fields' => array( ..., 'enable_<slug>_toolkit', ... ) )`)
   — the checkbox renders only when BOTH are wired (F&B miss #1, fixed in
   #7010; regression-pinned by `tests/test-fnb-toolkit-toggle.php`). Also add
   the memory estimate to `get_toolkit_memory_requirements()`.
4. **Admin settings page / side menu** — new file
   `addons/pro/includes/admin/class-wp-mcp-ai-<slug>-settings-page.php`
   (minimal worked example: `class-wp-mcp-ai-fnb-settings-page.php`).
   Register the toolkit's Settings API form (its own option or the shared
   one) and add the menu in `init()` via `add_action( 'admin_menu', ... )` —
   a submenu under `wp-mcp-ai-dashboard` (Vision Analysis / Ext. Cognition
   pattern) for settings-only toolkits, or a top-level menu
   (`class-wp-mcp-ai-crm-admin-menu.php` pattern) for CPT-heavy toolkits.
   Load it from the toolkit init.php inside the CRM-style enable gate:

   ```php
   $settings      = get_option( 'wp_mcp_ai_settings', array() );
   $is_enabled    = ! empty( $settings['enable_<slug>_toolkit'] );
   $is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
   $is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );
   if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
       // ...require services...
       if ( is_admin() ) {
           require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-<slug>-settings-page.php';
           WP_MCP_AI_<Slug>_Settings_Page::init();
       }
   }
   ```

   Regression-test the surface: `addons/pro/tests/test-<slug>-settings-page-registration.php`
   (submenu under the NV oOS menu + settings registered + sanitizer
   round-trip + init-gate load; mirror
   `test-fnb-settings-page-registration.php`) and a base+pro init-gate pin
   under `tests/basepro/` (mirror `test-basepro-fnb-init.php`).
5. **Settings status map** — `includes/admin/class-wp-mcp-ai-pro-settings.php`:
   the toolkit label map (~line 224, `enable_<slug>_toolkit => __( 'Label', 'mcp-ai-wpoos' )`)
   AND the status block (`'<slug>_toolkit' => array( name, description, enabled, category, tools_count, tools[] )`).
6. **WP-CLI toolkit command** — `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-toolkit-command.php`:
   `TOOLKITS` const entry.
7. **WP-CLI status command** — `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-status-command.php`:
   `$toolkit_keys` entry.
8. **Schedule presets** — `addons/pro/includes/class-wp-mcp-ai-pro-schedule-presets.php`:
   add `self::get_<slug>_presets()` to the `get_presets()` merge AND implement it
   (`toolkit => '<slug>', schedule_type => 'assistant_run'|'task',
   schedule => 'daily'|'weekly'|'hourly', schedule_data => assistant_config.message
   or hook).
9. **Workflow presets** — `addons/pro/includes/class-wp-mcp-ai-pro-workflow-presets.php`:
   merge + method with `nodes` (types: input/tool/condition/output; tool nodes
   carry `toolSlug` + `arguments` with `{{input.key}}` template vars).
   **Every preset needs ALL of: `name, description, category, icon, tags,
   nodes, edges`** — `edges` is an array of `{id, source, target, sourceHandle}`
   entries whose source/target must reference existing node IDs (enforced by
   `tests/test-pro-workflow-presets.php`; missing `edges` fails CI).
10. (If schedule-anything is relevant) **SA Toolkit Manager** —
    `addons/schedule-anything-platform/includes/class-sa-toolkit-manager.php`:
    `TOOLKIT_FLAGS` (`'<slug>' => 'enable_<slug>_toolkit'`) + `TOOLKIT_ORDER`.
11. **Tool-class coverage manifests** — run
    `bin/generate-tool-coverage-manifest.sh` and commit the updated
    `tests/tools/.coverage-manifest.txt` (base) and
    `addons/pro/tests/tools/.coverage-manifest.txt` (Pro). CI
    (`tests/test-tool-registry-coverage.php`) fails with "run
    bin/generate-tool-coverage-manifest.sh" for every unlisted class — the
    manifests list one FILE BASENAME per line (`class-*.php` basename minus the
    `class-` prefix and `.php`), and only additions of your new classes should
    appear in the diff (sort-order churn can look like deletions).

## 5. Testing: fixtures, oracle, smoke harness

- **Fixtures**: export the source data to `tests/fixtures/*.csv` (commit the
  CSVs, not the workbook). Give the fixture reader a sheet-name → table-ID map
  and a `WP_MCP_AI_<SLUG>_USE_FIXTURES` constant that the data source honours.
- **Oracle**: if the source data ships a "Check totals" / golden-values sheet,
  generate `tests/expected-values.php` from it (a `return array(...)` of
  key => value) and assert the engine reproduces every row (±1 on sums,
  ≤0.0002 on percentages). This is the single strongest correctness gate —
  differential testing against the data's own maths.
- **Verify formulas BEFORE writing PHP**: prototype the joins/aggregations in a
  quick Python check against the CSVs and confirm they match the golden values.
  (F&B lesson: stock-used cost was value-based COGS — opening value + purchases
  − closing value — not qty × unit price; reverse-engineering saved a rewrite.)
- **`smoke-run.php`**: a standalone harness that stubs WP functions
  (`WP_Error`, `is_wp_error`, `get_option`, transients, `__`, sanitizers,
  `wp_parse_args`, `untrailingslashit`, `wp_mkdir_p`, `wp_json_encode`,
  `sanitize_title`, …), defines `ABSPATH` + the fixture constant, loads the
  services, and prints PASS/FAIL per oracle row. Gotcha: any repo file with an
  `ABSPATH` guard silently exits if the constant is missing — define it FIRST.
- **WP_UnitTestCase** (`tests/pro/tools/<toolkit>/test-*.php`): require the
  class files in `setUp()`, `maybe_skip()` when unavailable, factory admin user
  for capability checks. Run in Docker per `mcp-ai-wpoos-test-suite`.
  (F&B lessons: build dynamic oracle keys with
  `str_replace( '-', '_', $month )` — the file's keys use underscores, month
  strings use hyphens; and compare sum rows with
  `assertEqualsWithDelta( ..., 1.0 )`, never exact `assertEquals` on floats —
  the engine rounds to 2 dp while the oracle carries 3 dp.)

## 6. phpcs gotcha checklist (WordPress standard)

- Run `vendor/bin/phpcbf --standard=WordPress <paths>` first; only hand-fix the rest.
- Every method needs a doc comment (`WordPress-Docs`) — `{@inheritdoc}` is fine
  for interface implementations; `execute()` needs the `@param` lines.
- `Only one object structure is allowed in a file` — split interfaces into
  their own file (e.g. `class-wp-mcp-ai-<slug>-table-reader.php`).
- `Doc comment long description must start with a capital letter`.
- `@param $default does not match actual variable name` — rename reserved
  `$default` params to `$fallback` (both signature and docblock).
- `Use Yoda Condition checks, you must` + loose-comparison warnings — and for
  floats use `0.0 !== $x` (plain `0 !== $x` is always true for floats — type
  juggling bug in disguise).
- `Avoid function calls in a FOR loop test part` / `count() inside a loop
  condition` — hoist to a variable before the loop.
- `Variable assignment found within a condition` (`while ( false !== ( $data = fgetcsv( $h ) ) )`)
  — keep the idiomatic loop but add
  `// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard CSV read loop.`
  (the sniff code is `Generic.CodeAnalysis.AssignmentInCondition`, not the
  Universal one).
- `Superfluous parameter comment` — if a builder method drops formal params in
  favour of `func_get_args()`, strip the stale `@param` lines.
- Generated files (like `expected-values.php`) will need a `@package` tag and a
  phpcbf pass.

## 7. Data-normalisation pitfalls (from the F&B build)

- `normalise_header()`: transliterate `³`/`²` to `3`/`2` BEFORE the
  ASCII-only `preg_replace( '/[^a-z0-9]+/', '_', … )` pass, or `Water (m³)`
  normalises to garbage. Alias real header variants per table
  (`lpg_cylinders` ↔ `lpg_cylinders_changed`).
- `month_of()` must accept BOTH `Y-m-d` datetimes AND bare `Y-m` strings
  (payroll/budgets/ledger columns are often month-only).
- Keep precomputed source columns (recipe cost, used qty, kWh per cover,
  pivots) as TEST cross-checks only — the engine recomputes from raw tables
  (spec rule G-02/G-08: all figures via the engine, never model maths).
- Cache per-table reads in 5-minute transients so repeated metric calls agree.

## 8. Validation gate

1. `php -l` every new/changed file.
2. `vendor/bin/phpcs --standard=WordPress <paths>` clean (phpcbf first).
3. `php tests/pro/tools/<toolkit>/smoke-run.php` → all oracle rows PASS.
4. Docker PHPUnit green (per `mcp-ai-wpoos-test-suite`) — includes
   `test-pro-workflow-presets.php` (required keys incl. `edges`),
   `test-pro-schedule-presets.php` if it exists,
   `test-tool-registry-coverage.php` (manifest up to date), and your new
   settings-page registration test (§4.4; also run the `tests/basepro/`
   init-gate pin under `phpunit-basepro.xml.dist`).
5. Admin-surface smoke (the F&B regression): enable the toggle in
   `wp_mcp_ai_settings`, require the toolkit `init.php`, and assert the
   settings page class loads + `has_action( 'admin_menu', ... )` is set;
   separately assert the module registry defines the toolkit module
   (§4.1) and that the Tools-section checkbox renders (field present in
   `get_fields()` AND in the `features` subtab group, §4.3).
6. Preset sanity: load the preset classes with minimal stubs (define `ABSPATH`,
   stub `__`, `sanitize_key`, `get_option`) and assert
   `get_presets_by_toolkit('<slug>')` / workflow `get_presets_by_category('<slug>')`
   return your presets — preset files silently exit without `ABSPATH`.
7. Run the `mcp-ai-wpoos-toolkit-audit` four-failure-class scan on the new
   toolkit before the PR (no unguarded shell calls, no calls to nonexistent
   client methods, array-safe parsing of provider responses, provider enums).

## Case study: food-beverage

`addons/pro/includes/tools/food-beverage/` (2026-10-08, 21 tools, 56 files,
+17.4k lines): Surf Club Midigama F&B management — M-01…M-21 metric engine,
R-01…R-13 report builder, Drive + CSV adapters, menu-engineering quadrants,
drafts-only output. Validation: phpcs clean; standalone smoke harness
reproduces 20/20 workbook Check-totals values (covers, revenues, cost %s,
cost per cover, opex total, volume/per-cover effects, waste, budget
variances). Two formula findings encoded + tested: value-based COGS for
stock-used, Assumptions-rates for utilities. See the PR branch
`062-fnb-pro-toolkit-proposal` and the proposal docs under
`docs/project/proposals/062-*`.

**Post-merge misses (the reason §4 lists eleven surfaces):**

1. The `enable_fnb_toolkit` field existed in `get_fields()` but was missing
   from the `features` subtab group in `get_subtab_groups()`, so the checkbox
   never rendered in the Tools section — fixed in #7010, pinned by
   `tests/test-fnb-toolkit-toggle.php` (the test comment documents the
   original bug).
2. The build shipped no Pro Module Registry entry and no admin settings
   page, so `food-beverage/init.php` never loaded and the side admin menu
   never appeared even with the toggle enabled. Fixed by adding
   `class-wp-mcp-ai-fnb-settings-page.php` (submenu under
   `wp-mcp-ai-dashboard`, Settings API form for `wp_mcp_ai_fnb_settings`)
   + the `fnb` entries in `$conditional_toolkits` / `$dir_map`, with
   `addons/pro/tests/test-fnb-settings-page-registration.php` and
   `tests/basepro/test-basepro-fnb-init.php` as regression pins.

## Also load

- `mcp-ai-wpoos-toolkit-audit` — the four failure classes to scan for
- `mcp-ai-wpoos-test-suite` — Docker test environment + triage patterns
- `wp-plugin-architecture` — composition-root, one-class-per-file discipline
- `wp-rest-api` / `wp-security-audit` — if the toolkit exposes endpoints
- `design-pro-schedule-manager` / `design-pro-workflow-builder` — the runtime
  surfaces the presets feed into
