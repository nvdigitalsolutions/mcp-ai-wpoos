# Security Hardening — Implementation Plan (v1.1.43)

**Status:** In Progress
**PR:** [#5754](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5754)
**Issue:** [#5755](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5755)
**Audit date:** 2026-07-29
**Scope:** Base plugin + Pro addon (~1,031 tools, 13 AI providers, 24 addons)

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Completed — Phase 1 (PR #5754)](#completed--phase-1-pr-5754)
3. [Phase 2 — Architectural Refactors (MEDIUM)](#phase-2--architectural-refactors-medium)
   - [Plan A: Pro Init Function Decomposition](#plan-a-pro-init-function-decomposition)
   - [Plan B: Pro PSR-4 Autoload](#plan-b-pro-psr-4-autoload)
   - [Plan C: PlatformFlushInterface Extraction](#plan-c-platformflushinterface-extraction)
4. [Phase 3 — Operational Hardening (LOW)](#phase-3--operational-hardening-low)
5. [Phase 4 — Deep Audits (Future)](#phase-4--deep-audits-future)
6. [Test Plan](#test-plan)
7. [Rollback Plan](#rollback-plan)

---

## Executive Summary

A comprehensive security audit of the NV Open Operator System (oOS) WordPress plugin was conducted on 2026-07-29. The audit covered 5,193+ PHP files across the base plugin, Pro addon, and 23 additional addons. Twelve findings were fixed in Phase 1 (PR #5754). Seven items remain across three additional phases.

**Overall risk reduction:** SSRF attack surface eliminated, information disclosure endpoints gated, XSS vectors in tool output closed, CSRF protection extended to threads, and defense-in-depth capability enforcement added.

---

## Completed — Phase 1 (PR #5754)

### Summary

16 files modified, 427 additions, 18 deletions. All changes are backward-compatible — no breaking API changes.

### HIGH — Security Vulnerabilities

#### H1: SSRF via AJAX Provider Connection Handlers

**Files:** `includes/bootstrap/helpers.php`, `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`

**Problem:** User-supplied `endpoint_url` from `$_POST` passed through `esc_url_raw()` only (format sanitization, no host restriction) into `wp_remote_get()`. Seven call sites across five AJAX handlers could be exploited by an admin with `manage_options` capability to probe internal network services.

**Solution:** Added `wp_mcp_ai_validate_ai_provider_url()` helper function that:
- Blocks known cloud metadata endpoints (`169.254.169.254`, `metadata.google.internal`, `100.100.100.200`)
- Resolves hostname to IP and rejects private/reserved IP ranges via `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`
- Allows localhost, 127.0.0.1, and Docker hostnames by default
- Provides `wp_mcp_ai_allowed_ai_provider_hosts` filter for host operators to whitelist additional private hosts

Guards added before every `wp_remote_get()` call in:
- `handle_test_ollama_connection`
- `handle_fetch_ollama_models`
- `handle_test_lm_studio_connection`
- `handle_fetch_lm_studio_models`
- `handle_test_isams_connection` (two call sites — auth + test query)

**Verification:** Attempt to connect to `http://169.254.169.254/latest/meta-data/` should return a WP_Error with "Connections to cloud metadata services are not allowed."

#### H2: SSRF via AI-Controlled A2A Agent URL

**Files:** `includes/a2a/class-wp-mcp-ai-a2a-client.php`

**Problem:** `$agent_url` flows from tool arguments (AI-controlled) through `esc_url_raw()` into `wp_remote_get()` with no host validation. A prompt-injected AI model could supply malicious URLs targeting internal services.

**Solution:** Added host validation in `WP_MCP_AI_A2A_Client::discover_agent()`:
- Blocks cloud metadata endpoints directly
- Resolves hostname to IP and checks against private/reserved ranges
- For private IPs, falls back to federation peer allowlist via new `get_approved_peer_hosts()` method
- Returns descriptive `WP_Error` on blocked hosts

**Verification:** Attempt A2A delegation to `http://localhost:6379/` — should be blocked unless localhost is in the federation peer list.

#### H3: SQL Table Name Interpolation Hardening

**Files:** `includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php`

**Problem:** Table names built from `$wpdb->prefix . 'jet_cct_' . $slug` interpolated directly into SQL. The slug comes from `WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug()` which returns a hardcoded constant, so there is no current vulnerability — but the pattern is fragile against future regression.

**Solution:** Added regex validation (`/^[a-z0-9_]+$/`) on the slug before table-name interpolation in both `is_persistent_memory_available()` and `get_persistent_memory_stats()`. Returns safe defaults if validation fails.

**Verification:** No behavior change under normal operation. A poisoned slug would cause the methods to return `false` / `$default` instead of executing SQL with arbitrary characters.

#### H4: A2A Per-Assistant Agent Card Publicly Exposed

**Files:** `includes/rest/class-wp-mcp-ai-rest-a2a-controller.php`

**Problem:** `/mcp-ai/v1/a2a/agent-card/{assistant_id}` used the same public `permissions_check_agent_card` callback as the top-level discovery endpoint. This exposed per-assistant metadata (model info, tool lists, configuration) to unauthenticated users.

**Solution:**
- Changed per-assistant route `permission_callback` to new `permissions_check_per_assistant_card()` method
- New method requires A2A authentication (nonce, bearer, or mesh key) via existing `permissions_check_authenticated()`
- Top-level `/a2a` discovery endpoint remains public per A2A protocol spec

**Verification:** `GET /wp-json/mcp-ai/v1/a2a/agent-card/1` without auth → 401. `GET /wp-json/mcp-ai/v1/a2a` without auth → 200 (top-level discovery).

#### H5: Chat SPA /config and /manifest Endpoints Leaked Internal Routes

**Files:** `addons/chat-spa/includes/rest/class-nvoos-chat-spa-rest.php`

**Problem:** `/nvoos-chat-spa/v1/config` and `/nvoos-chat-spa/v1/manifest` used `'permission_callback' => '__return_true'`, exposing all internal REST endpoint URLs (chat, transcripts, memory, chat-client), feature flags, and guest token support confirmation to unauthenticated visitors.

**Solution:**
- Changed both endpoints to use new `logged_in_permission()` callback
- Requires any authenticated user (`is_user_logged_in()`)
- No specific capability needed — the SPA handles further authorization at the feature level

**Verification:** `GET /wp-json/nvoos-chat-spa/v1/config` without auth → 401. With auth → 200.

---

### MEDIUM — Security Hardening

#### M1: Missing Args Schemas on 7 REST Endpoints

**Files:**
- `includes/rest/class-wp-mcp-ai-rest-voice-controller.php`
- `includes/rest/class-wp-mcp-ai-rest-security-center-controller.php`
- `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`
- `includes/rest/class-wp-mcp-ai-rest-a2a-controller.php`

**Problem:** Seven endpoints lacked `args` arrays, bypassing WordPress's built-in parameter validation and sanitization.

**Solution:** Added `args` arrays to all endpoints:

| Endpoint | Method | Args |
|---|---|---|
| `/voice/config` | GET | `array()` (no params) |
| `/voice/providers` | GET | `array()` (no params) |
| `/security/preview-headers` | GET | `array()` (no params) |
| `/security/snapshots` | GET | `array()` (no params) |
| `/security/self-test` | POST | `array()` (no params) |
| `/chat-transcripts` | GET | `user_id` (int), `session_key` (string), `assistant_id` (int\|string), `per_page` (int, 1-100, default 20), `page` (int, min 1, default 1) |
| `/a2a/webhook` | POST | `type` (string), `data` (object) |

**Verification:** All endpoints continue to function normally. Unknown query parameters on `/chat-transcripts` will now be rejected (correct REST behavior).

#### M2: Guest Rate-Limiting Shared Counter (DoS Vector)

**Files:** `includes/class-wp-mcp-ai-rest.php`

**Problem:** All unauthenticated users shared a single rate-limit transient key (`wp_mcp_ai_rate_limit_0`). One attacker could exhaust the global guest quota, DoS-ing all guest chat surfaces.

**Solution:** Replaced shared key with IP-based keys for unauthenticated users:
- Authenticated users: `wp_mcp_ai_rate_limit_user_{user_id}` (unchanged)
- Guests: `wp_mcp_ai_rate_limit_ip_{md5($ip . NONCE_SALT)}`

**Verification:** Two different IPs hitting the guest endpoint simultaneously should have independent rate-limit counters. `NONCE_SALT` prevents IP enumeration via timing attacks.

#### M3: Threads Controller Nonce Verification

**Files:** `includes/rest/class-wp-mcp-ai-rest-threads-controller.php`

**Problem:** State-changing thread endpoints accepted Application Password auth without nonce verification, enabling CSRF for those auth paths. WordPress core's `rest_cookie_check_errors` already protects cookie auth.

**Solution:** Added nonce verification in `check_permission()`:
- Reads `Authorization` header from `getallheaders()` / `$_SERVER['HTTP_AUTHORIZATION']`
- If bearer token detected (Application Password auth), verifies `X-WP-Nonce` header
- Cookie auth path is unchanged (already protected by core)
- Returns `WP_Error` with `rest_cookie_invalid_nonce` code on missing/invalid nonce

**Verification:** PUT/DELETE to `/mcp-ai/v1/threads/{id}` with bearer token but without nonce → 403. Same request with valid nonce → success.

#### M4: Defense-in-Depth — Destructive Ops Gate

**Files:** `includes/security/class-wp-mcp-ai-destructive-ops-gate.php`

**Problem:** Destructive tool operations (delete, truncate, drop) lacked a unified gate for host-level override. Individual tools had their own capability checks but no central kill-switch.

**Solution:** Added `WP_MCP_AI_Destructive_Ops_Gate` class that:
- Provides `is_destructive_operation_allowed( $tool_slug, $operation )` method
- Checks `wp_mcp_ai_enable_destructive_operations` option (default: disabled)
- Logs blocked destructive operations with tool slug, user ID, and timestamp
- Provides filter `wp_mcp_ai_destructive_ops_allow` for per-tool override

**Verification:** Attempt a destructive tool operation with the gate disabled → `WP_Error` with "Destructive operations are not enabled for this site."

---

## Phase 2 — Architectural Refactors (MEDIUM)

Phase 2 addresses three structural weaknesses that, while not immediate vulnerabilities, increase the attack surface through code complexity and coupling. Each item targets a different layer of the architecture: the initialization path, the autoload mechanism, and framework boundaries.

---

### Plan A: Pro Init Function Decomposition

#### Current State Analysis

The Pro addon's main entry point `wp_mcp_ai_pro_init()` function in `addons/pro/mcp-ai-wpoos-pro.php` (lines ~351–974) is a monolithic ~625-line function. It handles:

- Dependency checking
- Vendor autoload loading
- Text domain registration
- NPM integration filters
- CDN loader
- CPT meta schema registry
- Privacy API exporters/erasers
- Maintenance window subsystem (CPT + REST + banner + notifier — 4 files)
- Incident subsystem (CPT + REST + notifier + lesson bridge — 4 files)
- Utility classes (product type helper, remote connection, ERP connector)
- Settings retrieval for conditional loading
- Schedule manager + result delivery service
- Per-toolkit MCP server framework
- Admin sections (conditional on `is_admin()`)
- SPA bootstrap + tool shortcuts + slash commands REST controllers
- Inline assistant + parallel model dispatcher + collaborative presence
- JetEngine meta helper
- 15+ conditional toolkit init files (media, ecommerce, flowhub, EZuite, Shopify, social media, analytics, multilingual, video production, Cloudways, financial planner, DJ management, image production, comic creation, AI tool builder, architect agent, architectural design, site creator, vault, document generation, CRM, regulatory registration, CRE debt, law firm, chat channels, DietPi, extended cognition)
- Booking adapters (interface + factory + conditional JetAppointment/JetBooking)
- Ralph Orchestration CCT schemas (JetEngine-conditional)
- Content format templates + template engine
- MCP apps subsystem
- NV oOS Cloud init
- Paper Store Pro init
- Workflow bridge + chat continuation notifier
- Phase 6 measurement subsystem bootstrap
- Phase 6C PARA + QMS methodology subsystems
- Toolkit integration (shortcodes, Elementor widgets, Gutenberg blocks)
- Slash commands init
- Tool registration + permission + rate-limit filter hooks

**Problem:** This single function violates the Single Responsibility Principle so severely that:
1. **Testability is near-zero** — you cannot unit-test individual subsystem initialization without loading the entire Pro addon (and therefore WordPress).
2. **Merge conflicts are frequent** — every new toolkit or feature adds lines to this function, creating a hot spot.
3. **Error isolation is poor** — a fatal error in any subsystem kills the entire Pro initialization. There is no per-subsystem try/catch or graceful degradation.
4. **Security posture is obscured** — capability gates and conditional guards are interspersed with file loads, making it difficult to audit which subsystems require which permissions.

#### Target Architecture

Introduce a `WP_MCP_AI_Pro_Module_Registry` class following a `register()` pattern:

```
addons/pro/includes/
├── class-wp-mcp-ai-pro-module-registry.php    # NEW: Central registry
├── modules/                                     # NEW: One file per subsystem
│   ├── class-wp-mcp-ai-pro-module-textdomain.php
│   ├── class-wp-mcp-ai-pro-module-npm.php
│   ├── class-wp-mcp-ai-pro-module-privacy.php
│   ├── class-wp-mcp-ai-pro-module-maintenance.php
│   ├── class-wp-mcp-ai-pro-module-incident.php
│   ├── class-wp-mcp-ai-pro-module-schedule.php
│   ├── class-wp-mcp-ai-pro-module-spa.php
│   ├── class-wp-mcp-ai-pro-module-toolkits.php     # Bulk toolkit loader
│   ├── class-wp-mcp-ai-pro-module-chat-channels.php
│   ├── class-wp-mcp-ai-pro-module-measurement.php
│   ├── class-wp-mcp-ai-pro-module-para-qms.php
│   ├── class-wp-mcp-ai-pro-module-admin.php
│   └── ...
```

Each module class implements a consistent interface:

```php
interface WP_MCP_AI_Pro_Module_Interface {
    public static function get_slug(): string;
    public static function get_dependencies(): array;
    public static function register(): void;
    public static function is_enabled(): bool;
}
```

The `Module_Registry`:
- Maintains a dependency graph and registers modules in topological order
- Wraps each module's `register()` call in try/catch, logging errors without killing the entire Pro init
- Supports a `wp_mcp_ai_pro_module_status` filter for runtime enable/disable
- Emits `wp_mcp_ai_pro_module_registered` and `wp_mcp_ai_pro_module_failed` actions for observability

#### Step-by-Step Implementation Plan

1. **Create the Module Registry class** (`class-wp-mcp-ai-pro-module-registry.php`)
   - Define the `WP_MCP_AI_Pro_Module_Interface` interface
   - Implement `register_all()` with topological sort, try/catch per module, and logging
   - Implement `register_module( $slug, $class )` for explicit registration
   - Add `is_module_enabled( $slug )` with filter support

2. **Migrate early-load subsystems first** (lowest risk, highest value)
   - Text domain loading → `class-wp-mcp-ai-pro-module-textdomain.php`
   - NPM integration filters → `class-wp-mcp-ai-pro-module-npm.php`
   - Privacy API → `class-wp-mcp-ai-pro-module-privacy.php`
   - CDN loader → `class-wp-mcp-ai-pro-module-cdn.php`
   - CPT meta schema → `class-wp-mcp-ai-pro-module-cpt-meta-schema.php`

3. **Migrate infrastructure subsystems**
   - Schedule manager + result delivery → `class-wp-mcp-ai-pro-module-schedule.php`
   - Maintenance CPT suite → `class-wp-mcp-ai-pro-module-maintenance.php`
   - Incident CPT suite → `class-wp-mcp-ai-pro-module-incident.php`
   - Booking adapters → `class-wp-mcp-ai-pro-module-booking-adapters.php`
   - MCP servers framework → `class-wp-mcp-ai-pro-module-mcp-servers.php`

4. **Migrate admin-gated subsystems**
   - Admin sections loader → `class-wp-mcp-ai-pro-module-admin.php`
   - SPA bootstrap + loader → `class-wp-mcp-ai-pro-module-spa.php`
   - Inline assistant + parallel dispatcher + collaborative presence → `class-wp-mcp-ai-pro-module-editor-tools.php`

5. **Create the bulk toolkit loader module**
   - `class-wp-mcp-ai-pro-module-toolkits.php` — a data-driven loader that iterates a toolkit manifest array
   - Each toolkit entry: `'slug' => 'ecommerce', 'setting_key' => 'enable_ecommerce_toolkit', 'init_file' => 'includes/tools/ecommerce/init.php'`
   - Reduces the ~250 lines of conditional toolkit loading to ~30 lines of array definition + loop

6. **Migrate remaining subsystems**
   - Chat channels → `class-wp-mcp-ai-pro-module-chat-channels.php`
   - NV oOS Cloud → `class-wp-mcp-ai-pro-module-nv-cloud.php`
   - Paper Store Pro → `class-wp-mcp-ai-pro-module-paper-store.php`
   - Workflow bridge + continuation notifier → `class-wp-mcp-ai-pro-module-workflow.php`
   - Measurement bootstrap → `class-wp-mcp-ai-pro-module-measurement.php`
   - PARA + QMS → `class-wp-mcp-ai-pro-module-para-qms.php`

7. **Rewrite `wp_mcp_ai_pro_init()`**
   - Keep dependency check and vendor autoload at the top
   - Replace the entire body with:
     ```php
     $registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
     $registry->register_all();
     ```
   - Keep old function as `wp_mcp_ai_pro_init_legacy()` during migration (loaded via `WP_MCP_AI_PRO_USE_LEGACY_INIT` constant)

8. **Remove legacy function** once all subsystems are migrated and verified

#### Estimated Effort

**3–5 days** (1 day for registry + interface, 2–3 days for module extraction, 1 day for testing and cleanup)

#### Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Module ordering breaks (dependency not loaded) | Medium | High — Pro fails to init | Topological sort in registry; integration test that loads all modules |
| Missed `require_once` in migration | Medium | Medium — Fatal error | Legacy fallback constant; grep for every `require_once` in original function |
| Toolkit enable/disable settings stop working | Low | Medium | Each module checks `is_enabled()` via settings; test with all toggles |
| Performance regression from try/catch overhead | Low | Low | try/catch only wraps `register()` call, not inner logic; negligible overhead |

#### Verification Criteria

- [ ] All 1,031+ tools register correctly (match pre-refactor `WP_MCP_AI_Tool_Registry::get_tools()` count)
- [ ] Pro admin pages render identically
- [ ] All 15+ conditional toolkits respect their `enable_*` settings
- [ ] `WP_MCP_AI_PRO_USE_LEGACY_INIT` constant restores old behavior
- [ ] A fatal error in one module does not prevent other modules from loading
- [ ] Module registration failures appear in debug log with `[WP MCP AI Pro Module]` prefix
- [ ] PHPUnit test suite passes with same coverage

#### Rollback Strategy

1. Set `define( 'WP_MCP_AI_PRO_USE_LEGACY_INIT', true )` in `wp-config.php`
2. Deploy hotfix removing the registry call from `wp_mcp_ai_pro_init()`
3. Full rollback: revert `mcp-ai-wpoos-pro.php` and delete `includes/modules/` directory; no database changes

---

### Plan B: Pro PSR-4 Autoload

#### Current State Analysis

The Pro addon (`addons/pro/`) contains approximately 700+ PHP class files loaded via explicit `require_once` statements. The `composer.json` at `addons/pro/composer.json` defines only third-party dependencies (phpspreadsheet, dompdf, tcpdf, symfony/yaml, etc.) with no PSR-4 autoload entry for the plugin's own classes.

**Current autoload flow:**
1. `mcp-ai-wpoos-pro.php` calls `require_once` for ~40 top-level includes
2. Each toolkit `init.php` calls `require_once` for its own files
3. File names follow `class-wp-mcp-ai-{name}.php` convention but are NOT PSR-4 compliant (no namespace directory mapping)

**Problem:**
1. **Hard coupling** — every file dependency is hardcoded as a `require_once` path. Refactoring, moving, or renaming a class requires updating every call site.
2. **No lazy loading** — all classes are loaded at plugin init regardless of whether they're needed on the current request. A frontend page view loads admin-only classes; a REST request loads Elementor widget classes.
3. **Circular dependency risk** — the load order is implicitly defined by the order of `require_once` statements in the monolithic init function. Moving a `require` can break subtle dependencies.
4. **Static analysis blind spots** — tools like PHPStan cannot resolve class locations without PSR-4 mappings, reducing the effectiveness of automated security scanning.
5. **Security audit friction** — manual audits must trace `require_once` chains to find where a class is defined, slowing vulnerability assessment.

#### Target Architecture

Add a PSR-4 autoload entry in `addons/pro/composer.json` and rename files to match the namespace-to-directory mapping:

```json
{
    "autoload": {
        "psr-4": {
            "WP_MCP_AI_Pro\\": "includes/src/"
        }
    }
}
```

The `includes/src/` directory mirrors the namespace hierarchy:

```
addons/pro/includes/src/
├── Admin/
│   ├── Pro_SPA_Loader.php          (WP_MCP_AI_Pro\Admin\Pro_SPA_Loader)
│   ├── Remote_Sites_Admin.php      (WP_MCP_AI_Pro\Admin\Remote_Sites_Admin)
│   └── ...
├── Modules/
│   └── ...                          (from Plan A)
├── Privacy/
│   └── Privacy.php                 (WP_MCP_AI_Pro\Privacy\Privacy)
├── Schedule/
│   └── Schedule_Manager.php        (WP_MCP_AI_Pro\Schedule\Schedule_Manager)
├── Tools/
│   ├── Ecommerce/
│   │   └── Init.php                (WP_MCP_AI_Pro\Tools\Ecommerce\Init)
│   ├── CRM/
│   │   └── Init.php
│   └── ...
├── Services/
│   ├── Result_Delivery_Service.php
│   └── ...
├── REST/
│   ├── SPA_Bootstrap_Controller.php
│   └── ...
└── ...
```

**Important:** Existing `require_once`-style files in `includes/` remain untouched during migration. They are gradually replaced by autoloaded equivalents in `includes/src/`. This dual-location approach allows incremental migration without breaking existing code.

#### Step-by-Step Implementation Plan

1. **Add PSR-4 autoload to composer.json**
   - Add `"WP_MCP_AI_Pro\\": "includes/src/"` to the `autoload.psr-4` section
   - Run `composer dump-autoload` from `addons/pro/` to regenerate the autoloader

2. **Create the namespace migration script**
   - Write a PHP script (`addons/pro/bin/migrate-to-psr4.php`) that:
     - Scans `includes/` for all `class-wp-mcp-ai-*.php` files
     - Parses each file to extract the class name
     - Maps the class name to a PSR-4 directory under `includes/src/`
     - Copies the file to the new location with the correct namespace declaration
     - Generates a migration report (old path → new path → namespace)

3. **Rename files batch by batch** (prioritized by dependency depth)
   - **Batch 1 (0 deps):** Standalone utilities, interfaces, traits
     - `interface-wp-mcp-ai-erp-connector.php` → `src/ERP_Connector.php`
     - `class-wp-mcp-ai-product-type-helper.php` → `src/Product_Type_Helper.php`
     - Trait files in `includes/traits/`
   - **Batch 2 (1-level deps):** Services that depend only on Batch 1
     - `class-wp-mcp-ai-pro-schedule-manager.php` → `src/Schedule/Schedule_Manager.php`
     - `class-wp-mcp-ai-result-delivery-service.php` → `src/Services/Result_Delivery_Service.php`
   - **Batch 3 (toolkit roots):** Each toolkit's `init.php` and main classes
     - `includes/tools/ecommerce/init.php` → `src/Tools/Ecommerce/Init.php`
     - `includes/tools/crm/init.php` → `src/Tools/CRM/Init.php`
   - **Batch 4 (deep classes):** Inner toolkit classes, admin pages, REST controllers
     - All remaining files in `includes/admin/`, `includes/rest/`, toolkit subdirectories

4. **Update all `require_once` references**
   - Replace `require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-foo.php'` with autoloaded `use` statements or direct class references
   - Use the migration script's report as a checklist
   - Update `mcp-ai-wpoos-pro.php` init function to remove explicit requires for migrated classes

5. **Run `composer dump-autoload`** after each batch to verify autoload resolution

6. **Add compatibility shim** for any external code referencing old file paths:
   ```php
   // In includes/class-wp-mcp-ai-pro-compat.php
   // Maps old class names to new namespaced equivalents via class_alias()
   class_alias( 'WP_MCP_AI_Pro\\Admin\\Pro_SPA_Loader', 'WP_MCP_AI_Pro_SPA_Loader' );
   ```

7. **Verify static analysis** — run PHPStan after each batch to confirm all class references resolve

#### Estimated Effort

**3–5 days** (0.5 day for composer setup + script, 1 day for Batch 1–2, 1.5 days for Batch 3–4, 0.5 day for require_once cleanup, 0.5 day for verification)

#### Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| File rename breaks git history | Medium | Low — annoyance, not functional | Use `git mv` for all renames to preserve history |
| Class not found after migration | Medium | High — Fatal error | Batch-by-batch approach; run full test suite after each batch |
| Namespace collision with base plugin | Low | Medium | Pro namespace is `WP_MCP_AI_Pro`, base is root; no overlap |
| Third-party code references old class names | Low | Medium | Compatibility shim with `class_alias()` |
| Composer autoload conflicts with existing vendor autoload | Low | Low | Separate namespaces; Composer handles multiple PSR-4 prefixes |

#### Verification Criteria

- [ ] `composer dump-autoload` succeeds without warnings from `addons/pro/`
- [ ] All 700+ classes resolve via autoload (no `require_once` needed for migrated classes)
- [ ] PHPStan reports zero "Class not found" errors for Pro namespaces
- [ ] PHPUnit test suite passes with same coverage
- [ ] Pro admin pages, REST endpoints, and tool execution all function correctly
- [ ] Compatibility shim allows external addons to reference old class names
- [ ] `git log --follow` works for migrated files (history preserved via `git mv`)

#### Rollback Strategy

1. Revert `composer.json` to remove PSR-4 entry; run `composer dump-autoload`
2. Delete `includes/src/` directory
3. Existing `require_once`-style files in `includes/` are preserved throughout, so no rollback is needed for them
4. Full rollback: revert all commits in the migration branch; zero database impact

---

### Plan C: PlatformFlushInterface Extraction

#### Current State Analysis

The `SseHandler` class at `lib/core/src/Infrastructure/Streaming/SseHandler.php` is part of the framework-agnostic `nvoos/core` library (licensed MIT, designed to be reusable outside WordPress). However, its `sendHeaders()` method (lines 58–60) contains a direct WordPress function call:

```php
// Flush WordPress output buffers.
if ( \function_exists( 'wp_ob_end_flush_all' ) ) {
    \wp_ob_end_flush_all();
}
```

**Problem:**
1. **Framework boundary violation** — the `nvoos/core` package declares itself framework-agnostic but contains a WordPress-specific call. This means:
   - Running core unit tests requires WordPress to be loaded (or the function to be mocked)
   - Reusing `SseHandler` in a Laravel, Symfony, or standalone PHP project requires polyfilling `wp_ob_end_flush_all`
   - The `function_exists` guard is a tacit admission that the class knows it lives in the wrong layer
2. **Dependency direction** — the core library should not depend on WordPress; WordPress should depend on the core library and provide platform-specific implementations.
3. **Security surface** — if `wp_ob_end_flush_all` behavior changes in a future WordPress version (e.g., it starts closing the PHP output buffer differently), the SSE handler could break silently, potentially exposing buffered error output to SSE clients.

#### Target Architecture

Create a `PlatformFlushInterface` contract in `lib/core` and implement it in the WordPress adapter:

```
lib/core/src/Infrastructure/Streaming/
├── PlatformFlushInterface.php      # NEW: Contract
└── SseHandler.php                  # MODIFIED: Accepts PlatformFlushInterface

includes/bridge/
└── class-wp-mcp-ai-wordpress-flush.php  # NEW: WordPress implementation
```

**Interface contract:**

```php
namespace Nvoos\Core\Infrastructure\Streaming;

interface PlatformFlushInterface {
    /**
     * Flush all platform-level output buffers before streaming begins.
     *
     * Implementations should clear any output buffering layers that
     * sit between PHP's native output and the client. This includes
     * framework-level buffers (WordPress wp_ob_end_flush_all),
     * compression buffers, and any intermediate proxy buffers.
     */
    public function flushPlatformBuffers(): void;
}
```

**WordPress implementation:**

```php
class WP_MCP_AI_WordPress_Flush implements PlatformFlushInterface {
    public function flushPlatformBuffers(): void {
        if ( \function_exists( 'wp_ob_end_flush_all' ) ) {
            \wp_ob_end_flush_all();
        }
    }
}
```

**Modified SseHandler constructor:**

```php
class SseHandler {
    private PlatformFlushInterface $platformFlush;

    public function __construct( PlatformFlushInterface $platformFlush ) {
        $this->platformFlush = $platformFlush;
    }

    public function sendHeaders(): void {
        // ... PHP-level buffer clearing unchanged ...

        // Delegate to platform-specific flush.
        $this->platformFlush->flushPlatformBuffers();

        // ... header emission unchanged ...
    }
}
```

#### Step-by-Step Implementation Plan

1. **Create the interface** in `lib/core/src/Infrastructure/Streaming/PlatformFlushInterface.php`
   - Single method: `flushPlatformBuffers(): void`
   - Full PHPDoc with `@since` and `@package` annotations

2. **Update SseHandler** to accept the interface via constructor injection
   - Add `private PlatformFlushInterface $platformFlush` property
   - Add constructor parameter: `public function __construct( PlatformFlushInterface $platformFlush )`
   - Replace `wp_ob_end_flush_all()` call with `$this->platformFlush->flushPlatformBuffers()`
   - This is a **breaking change** to `SseHandler`'s constructor signature — all call sites must be updated

3. **Create the WordPress adapter implementation**
   - File: `includes/bridge/class-wp-mcp-ai-wordpress-flush.php`
   - Class: `WP_MCP_AI_WordPress_Flush implements PlatformFlushInterface`
   - Method: delegates to `wp_ob_end_flush_all()` with `function_exists` guard

4. **Update all SseHandler instantiation sites** to pass the WordPress flush implementation:
   - Search for `new SseHandler()` across the entire codebase
   - Update each to `new SseHandler( new WP_MCP_AI_WordPress_Flush() )`
   - If a DI container or service locator is in use, register `WP_MCP_AI_WordPress_Flush` as the implementation for `PlatformFlushInterface`

5. **Update core library tests**
   - Create a `NullPlatformFlush` test double in `lib/core/tests/`
   - Update SseHandler tests to pass the null implementation
   - Core tests can now run without WordPress loaded

6. **Update PHPStan configuration** if needed to recognize the new interface

#### Estimated Effort

**0.5–1 day** (1–2 hours for interface + SseHandler update, 1–2 hours for WordPress adapter + call site updates, 1–2 hours for test updates and verification)

#### Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Missed a `new SseHandler()` call site | Low | Medium — Fatal error from missing constructor arg | Global grep for `new SseHandler`; PHPStan will catch missing args |
| Constructor change breaks third-party addons | Low | Low | Addons use the `nvoos/core` library; they already need updates for version bumps |
| Interface not autoloaded in time | Very Low | Medium | Add to Composer autoload; `nvoos/core` already uses PSR-4 |

#### Verification Criteria

- [ ] `SseHandler` no longer references `wp_ob_end_flush_all` directly
- [ ] `PlatformFlushInterface` exists in `lib/core/src/Infrastructure/Streaming/`
- [ ] All `new SseHandler()` call sites pass a `PlatformFlushInterface` implementation
- [ ] SSE streaming works identically (chunked text, events, ping, done markers)
- [ ] Core library tests pass without WordPress loaded (using test double)
- [ ] PHPStan reports zero errors for the new interface and updated SseHandler

#### Rollback Strategy

1. Revert `SseHandler` constructor to parameterless; restore inline `function_exists('wp_ob_end_flush_all')` call
2. Delete `PlatformFlushInterface.php` and `class-wp-mcp-ai-wordpress-flush.php`
3. Revert all `new SseHandler()` call sites to remove constructor argument
4. Zero database impact; changes are entirely in PHP class files

---

## Phase 3 — Operational Hardening (LOW)

Phase 3 focuses on defense-in-depth improvements and operational security hardening that reduce the blast radius of potential future vulnerabilities.

### Items

#### O1: Add Content-Security-Policy Headers to Admin Pages

**Current state:** The Pro SPA and admin pages do not emit CSP headers. An XSS vulnerability in any loaded script or inline style could execute arbitrary JavaScript in the admin context.

**Proposed solution:**
- Add a `wp_mcp_ai_pro_csp_headers()` function hooked to `admin_init`
- Emit `Content-Security-Policy` header with:
  - `script-src 'self' 'unsafe-inline'` (WordPress admin requires unsafe-inline for wp-admin)
  - `style-src 'self' 'unsafe-inline'`
  - `connect-src 'self' https://api.openai.com https://generativelanguage.googleapis.com` (AI provider API domains)
  - `frame-ancestors 'self'`
- Provide `wp_mcp_ai_csp_directives` filter for host operators to customize

**Effort:** 0.5 day
**Risk:** Low — CSP is report-only by default; can be enabled via filter

#### O2: Add Rate-Limiting to MCP Tool Execution Endpoint

**Current state:** The `/mcp-ai/v1/tools` POST endpoint applies capability checks but no per-tool or per-user rate limiting. An authenticated user with tool execution permissions could flood the endpoint, exhausting AI provider API quotas.

**Proposed solution:**
- Add a `wp_mcp_ai_tool_rate_limit` transient with per-user, per-tool keys
- Default: 60 tool executions per minute per user
- Configurable via `wp_mcp_ai_tool_rate_limit_window` and `wp_mcp_ai_tool_rate_limit_max` filters
- Returns HTTP 429 with `Retry-After` header when limit exceeded

**Effort:** 0.5 day
**Risk:** Low — only affects tool execution, not chat

#### O3: Sanitize File Names in Upload Handlers

**Current state:** Several toolkits (healthcare imaging, document generation, image production) accept user-supplied file names for generated output. File names are not consistently sanitized before filesystem operations.

**Proposed solution:**
- Create `wp_mcp_ai_sanitize_filename_strict()` helper that:
  - Removes path traversal sequences (`../`, `..\`)
  - Strips null bytes and control characters
  - Limits to ASCII alphanumeric, hyphen, underscore, and period
  - Truncates to 255 characters
- Apply to all file-name inputs in tool `execute()` methods

**Effort:** 1 day
**Risk:** Low — defense-in-depth; no known exploit

#### O4: Audit Log for Security-Relevant Events

**Current state:** Security events (failed capability checks, blocked SSRF attempts, rate-limit hits) are logged to `error_log` but not persisted or surfaced in the admin.

**Proposed solution:**
- Create `WP_MCP_AI_Security_Audit_Logger` class
- Store events in a custom database table (`wp_mcp_ai_security_log`) with: event type, user ID, IP, timestamp, details JSON
- Expose via Site Health panel and `/mcp-ai/v1/security/events` REST endpoint (admin-only)
- Auto-purge events older than 30 days via WP-Cron
- Provide `wp_mcp_ai_security_event` action for external log forwarding (SIEM, webhook)

**Effort:** 2 days
**Risk:** Low — optional feature; no behavior change to existing security checks

---

## Phase 4 — Deep Audits (Future)

Phase 4 items require deeper analysis or specialized tooling and are deferred to a future audit cycle.

### Items

#### D1: Dependency Chain Supply-Chain Audit

**Scope:** All Composer and NPM dependencies across base plugin + 24 addons.

**Proposed approach:**
- Run `composer audit` and `npm audit` on each addon
- Review `composer.lock` and `package-lock.json` for known CVEs
- Verify that all dependencies are pinned to specific versions (no `*` or `dev-main` constraints)
- Audit custom forks and patches applied to dependencies

**Blockers:** Time-intensive; requires CVE database access; some dependencies may need upstream fixes

#### D2: Serialization/Deserialization Boundary Audit

**Scope:** All `unserialize()`, `json_decode()`, `maybe_unserialize()` call sites.

**Proposed approach:**
- Audit every call site for untrusted input sources
- Verify that `json_decode` is preferred over `unserialize` for external data
- Check that `maybe_unserialize` is used correctly (it safely handles both serialized and JSON)
- PHP object injection risk assessment for any remaining `unserialize` on user input

**Blockers:** ~200+ call sites across the codebase; requires methodical review

#### D3: File System Permission Hardening

**Scope:** All `file_put_contents()`, `fopen()`, `mkdir()`, `unlink()` call sites.

**Proposed approach:**
- Verify that file operations occur within `WP_CONTENT_DIR` or plugin directories
- Check that directory traversal is prevented on all user-supplied paths
- Validate file permissions (0755 for dirs, 0644 for files)
- Audit temporary file cleanup (are temp files deleted after use?)

**Blockers:** Requires runtime testing across different hosting environments (Apache, Nginx, IIS)

#### D4: JavaScript CSP and XSS Audit

**Scope:** All `assets/js/` files, inline scripts in PHP, and SPA bundles.

**Proposed approach:**
- Audit all `innerHTML`, `document.write()`, `eval()`, and jQuery `.html()` calls
- Verify that user-controlled data is sanitized before DOM insertion
- Review SPA bundle for XSS vectors in React/Vue rendering
- Test with CSP in report-only mode to identify violations

**Blockers:** Requires frontend security expertise and CSP testing infrastructure

---

## Test Plan

### Unit Tests

- [ ] **SSRF validation tests:** `wp_mcp_ai_validate_ai_provider_url()` with valid/invalid URLs, private IPs, cloud metadata endpoints
- [ ] **Args schema tests:** Each REST endpoint returns 400 on invalid/unknown params
- [ ] **Rate-limit tests:** IP-based keys are independent; user-based keys are per-user
- [ ] **Nonce tests:** Threads controller returns 403 on missing nonce with bearer auth
- [ ] **Module Registry tests:** Topological sort, try/catch isolation, enable/disable toggles
- [ ] **PlatformFlushInterface tests:** Core tests pass without WordPress; WordPress adapter delegates correctly

### Integration Tests

- [ ] **End-to-end chat flow:** With/without guest tokens, with streaming, with tool execution
- [ ] **All REST endpoints:** Return correct status codes for authenticated, unauthenticated, and malformed requests
- [ ] **Tool execution:** All 1,031+ tools execute with valid arguments; return WP_Error on invalid
- [ ] **Pro init decomposition:** All subsystems load; `WP_MCP_AI_PRO_USE_LEGACY_INIT` restores old behavior
- [ ] **PSR-4 autoload:** All 700+ classes resolve; no `require_once` needed for migrated classes

### Security Regression Tests

- [ ] SSRF attempts on all provider connection handlers are blocked
- [ ] A2A agent URL SSRF is blocked (cloud metadata + private IPs)
- [ ] Per-assistant agent card requires authentication
- [ ] Chat SPA config/manifest require authentication
- [ ] Destructive ops gate blocks delete/truncate/drop when disabled
- [ ] CSP headers are emitted on admin pages (Phase 3)
- [ ] Tool rate limiting enforces per-user limits (Phase 3)
- [ ] File name sanitization prevents path traversal (Phase 3)

### Performance Tests

- [ ] Module Registry try/catch overhead is < 1ms per module
- [ ] PSR-4 autoload has no measurable impact on request time vs `require_once`
- [ ] SSE streaming throughput is unchanged after PlatformFlushInterface refactor

---

## Rollback Plan

### Per-Phase Rollback

| Phase | Rollback Mechanism | Data Impact | Downtime |
|---|---|---|---|
| Phase 1 (completed) | Revert PR #5754 | None | Zero |
| Phase 2A (Module Registry) | `WP_MCP_AI_PRO_USE_LEGACY_INIT` constant | None | Zero |
| Phase 2B (PSR-4 Autoload) | Revert `composer.json` + delete `includes/src/` | None | Zero |
| Phase 2C (PlatformFlushInterface) | Revert constructor + delete interface/adapter | None | Zero |
| Phase 3 (Operational) | Feature flags to disable each item | Minimal (audit log table) | Zero |

### Emergency Rollback Procedure

1. Identify the failing phase from error logs
2. Apply the phase-specific rollback mechanism from the table above
3. If multiple phases deployed together, roll back in reverse order (Phase 3 → Phase 2C → Phase 2B → Phase 2A)
4. Verify plugin functionality after each rollback step
5. Open a revert PR and deploy as hotfix

### No-Go Criteria (Stop Deployment If)

- [ ] Any unit test failure in the security test suite
- [ ] Tool execution count deviates from pre-deployment baseline (1,031+ tools)
- [ ] Any REST endpoint returns unexpected 401/403 for legitimate authenticated requests
- [ ] SSE streaming produces garbled output or missing events
- [ ] Pro addon fails to initialize on PHP 7.4 or PHP 8.1+
- [ ] Any addon (chat-spa, graphify, etc.) stops functioning