# Implementation Plan: WP-CLI Infrastructure Hardening

**Based on:** Proposal 005 (`docs/project/proposals/005-wp-cli-infrastructure-hardening.md`)  
**Date:** 2026-06-26  
**Status:** Ready for execution  
**Target release:** v1.2.0 → v1.3.0

---

## Executive Summary

The audit revealed that several "missing" commands actually exist but weren't tested (`assistant create`/`delete`, `settings set`/`reset`). This plan focuses on **9 implementation tasks** (2 new subcommands, 5 hardening passes, 2 UX fixes) and **4 documentation updates**, executed over 4 phases.

---

## Phase 0: Pre-Flight Verification (0.5 days)

Before starting, verify what actually exists vs the test report's claims.

### Task 0.1 — Verify actual subcommand surface

```bash
# Run every documented subcommand with --help to confirm existence
for cmd in \
  "assistant create" "assistant delete" "assistant export" \
  "settings set" "settings reset" \
  "plugins activate" "plugins deactivate" \
  "thread create" "thread export" \
  "credential list" "credential issue" "credential revoke" \
  "transcript list" "transcript mine" "transcript status" "transcript cancel" \
  "tool enable" "tool disable" \
  "approval list" "approval approve" "approval reject" \
  "cron list" "cron run" "cron delete" "cron clear" \
  "memory recall" "memory store" "memory forget" "memory stats" "memory audit" \
  "slash execute" "slash list" \
  "cache clear" \
  "provider list" "provider test" "provider models"
do
  echo "=== wp mcp-ai $cmd --help ==="
  wp mcp-ai $cmd --help 2>&1 || echo "  ^^ NOT FOUND"
done
```

**Expected findings:**
- ✅ `assistant create`, `assistant delete`, `assistant export`, `settings set`, `settings reset` — exist
- ❌ `plugins activate`, `plugins deactivate` — missing
- ❌ `thread create`, `thread export` — missing
- ✅ `credential issue`, `credential revoke` — exist
- ✅ `transcript mine`, `transcript status`, `transcript cancel` — exist
- ❌ `transcript list` — missing (only `mine` exists)

---

## Phase 1: New Subcommands (1 day)

### Task 1.1 — Implement `plugins activate <slug>` and `plugins deactivate <slug>`

**File:** `includes/class-wp-mcp-ai-cli-command.php` (legacy dispatcher — add to `WP_MCP_AI_CLI_Plugins_Command`)

**Current state:** The `WP_MCP_AI_CLI_Plugins_Command` class at line ~406 only has `list_()`. Needs `activate()` and `deactivate()`.

**Implementation:**

```php
/**
 * Activate a supported plugin dependency.
 *
 * Activates a supported plugin (WooCommerce, JetEngine, etc.) if installed.
 * Equivalent to `wp plugin activate <file>` but validates against the
 * supported-plugin list first.
 *
 * ## OPTIONS
 *
 * <slug>
 * : Plugin slug (e.g. woocommerce, jet-engine).
 *
 * [--yes]
 * : Skip the confirmation prompt.
 *
 * ## EXAMPLES
 *
 *     # Activate WooCommerce.
 *     $ wp mcp-ai plugins activate woocommerce
 *
 *     # Activate without prompting.
 *     $ wp mcp-ai plugins activate woocommerce --yes
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Associative arguments.
 */
public function activate( $args, $assoc_args ) {
    $slug = sanitize_key( (string) ( $args[0] ?? '' ) );
    $yes  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

    if ( '' === $slug ) {
        WP_CLI::error( __( 'Please provide a plugin slug.', 'mcp-ai-wpoos' ) );
    }

    $plugins = self::get_supported_plugins_with_status();
    if ( ! isset( $plugins[ $slug ] ) ) {
        WP_CLI::error(
            sprintf(
                /* translators: 1: plugin slug, 2: supported plugins list command */
                __( 'Plugin "%1$s" is not a supported NV oOS integration. Run `%2$s` to see available plugins.', 'mcp-ai-wpoos' ),
                $slug,
                'wp mcp-ai plugins list'
            )
        );
    }

    $plugin = $plugins[ $slug ];

    if ( 'active' === $plugin['status'] ) {
        WP_CLI::success(
            sprintf(
                /* translators: %s: plugin name */
                __( 'Plugin "%s" is already active.', 'mcp-ai-wpoos' ),
                $plugin['name']
            )
        );
        return;
    }

    if ( 'missing' === $plugin['status'] ) {
        WP_CLI::error(
            sprintf(
                /* translators: %s: plugin name */
                __( 'Plugin "%s" is not installed.', 'mcp-ai-wpoos' ),
                $plugin['name']
            )
        );
    }

    if ( ! $yes ) {
        WP_CLI::confirm(
            sprintf(
                /* translators: %s: plugin name */
                __( 'Activate "%s"?', 'mcp-ai-wpoos' ),
                $plugin['name']
            )
        );
    }

    $result = activate_plugin( $plugin['plugin_file'] );

    if ( is_wp_error( $result ) ) {
        WP_CLI::error( $result->get_error_message() );
    }

    WP_CLI::success(
        sprintf(
            /* translators: %s: plugin name */
            __( 'Plugin "%s" activated.', 'mcp-ai-wpoos' ),
            $plugin['name']
        )
    );
}
```

The `deactivate` method is identical but calls `deactivate_plugins( $plugin['plugin_file'] )` instead.

**Pre-activation checks:**
1. Slug is in the supported plugin list
2. Plugin is installed (`plugin_file` exists)
3. Plugin is not already active
4. Confirmation prompt (unless `--yes`)

**Edge cases handled:**
- Unknown slug → error with hint to run `wp mcp-ai plugins list`
- Already active → success message (idempotent)
- Not installed → error
- Activation failure → WP_Error message

### Task 1.2 — Add `transcript list` alias delegating to `mine`

**File:** `includes/cli/class-wp-mcp-ai-cli-transcript-command.php`

**Rationale:** README documents `wp mcp-ai transcript list` but the actual subcommand is `mine`. Add `list` as an alias.

**Implementation:**

```php
/**
 * List transcript mining jobs (alias for "mine").
 *
 * ## OPTIONS
 *
 * [--assistant=<id>]
 * : Filter transcripts by assistant post ID.
 *
 * [--user=<id>]
 * : Filter transcripts by user ID.
 *
 * [--since=<date>]
 * : Only mine transcripts after this date (Y-m-d format).
 *
 * [--min-messages=<number>]
 * : Minimum messages per transcript (default: 3).
 * ---
 * default: 3
 * ---
 *
 * [--batch-size=<number>]
 * : Sessions per tick (default: 10).
 * ---
 * default: 10
 * ---
 *
 * ## EXAMPLES
 *
 *     $ wp mcp-ai transcript list --assistant=42
 *
 * @subcommand list
 * @param array $args       Positional arguments.
 * @param array $assoc_args Associative arguments.
 */
public function list_( $args, $assoc_args ) {
    $this->mine( $args, $assoc_args );
}
```

---

## Phase 2: Security Hardening (1.5 days)

### Task 2.1 — Add `require_capability()` helper to Base class

**File:** `includes/cli/class-wp-mcp-ai-cli-base-command.php`

**Rationale:** Centralize capability checking so every mutating command can gate on a single line.

**Implementation — add after the `confirm()` method (~line 243):**

```php
/**
 * Require a WordPress capability, or exit with an error.
 *
 * When run via WP-CLI, the current user is typically the system user
 * (ID 0 or 1).  This check ensures that even in CLI context, privileged
 * operations respect the same capability gates as the admin UI and REST API.
 *
 * @param string $capability WordPress capability name (e.g. 'manage_options').
 * @return void
 */
protected function require_capability( $capability = 'manage_options' ) {
    if ( ! current_user_can( $capability ) ) {
        WP_CLI::error(
            sprintf(
                /* translators: %s: WordPress capability name */
                __( 'Sorry, you are not allowed to perform this action. Required capability: %s', 'mcp-ai-wpoos' ),
                $capability
            )
        );
    }
}
```

### Task 2.2 — Add capability checks to mutating Base commands

**Files and insertion points:**

| File | Method | Capability | Insert after |
|------|--------|-----------|-------------|
| `class-wp-mcp-ai-cli-assistant-command.php` | `create()` | `manage_options` | After `$title` validation |
| `class-wp-mcp-ai-cli-assistant-command.php` | `delete()` | `manage_options` | After `$id` validation |
| `class-wp-mcp-ai-cli-assistant-command.php` | `update()` | `manage_options` | After ID validation |
| `class-wp-mcp-ai-cli-credential-command.php` | `issue()` | `manage_options` | After `$assistant_id` validation |
| `class-wp-mcp-ai-cli-credential-command.php` | `revoke()` | `manage_options` | After credential ID validation |
| `class-wp-mcp-ai-cli-tool-command.php` | `enable()` | `manage_options` | After tool slug validation |
| `class-wp-mcp-ai-cli-tool-command.php` | `disable()` | `manage_options` | After tool slug validation |
| `class-wp-mcp-ai-cli-cron-command.php` | `run()` | `manage_options` | After job ID validation |
| `class-wp-mcp-ai-cli-cron-command.php` | `delete()` | `manage_options` | After job ID validation |
| `class-wp-mcp-ai-cli-cron-command.php` | `clear()` | `manage_options` | After class_exists check |
| `class-wp-mcp-ai-cli-settings-command.php` | `set_()` | `manage_options` | After key validation |
| `class-wp-mcp-ai-cli-settings-command.php` | `reset()` | `manage_options` | At method entry |

**Pattern — insert one line after input validation:**
```php
$this->require_capability( 'manage_options' );
```

### Task 2.3 — Add `--yes` confirmation to `tool disable` and `credential revoke`

**File 1:** `includes/cli/class-wp-mcp-ai-cli-tool-command.php` — `disable()` method

**Current:** No confirmation. Disabling a tool is destructive (removes it from the assistant's available tool set).

**Add after line ~196 (after slug validation):**

```php
$yes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

if ( ! $yes ) {
    WP_CLI::confirm(
        sprintf(
            /* translators: %s: tool slug */
            __( 'Disable tool "%s"? This will remove it from all assistants.', 'mcp-ai-wpoos' ),
            $slug
        )
    );
}
```

**Update docblock:** Add `[--yes]` flag documentation.

**File 2:** `includes/cli/class-wp-mcp-ai-cli-credential-command.php` — `revoke()` method

**Current:** Check if `revoke()` has confirmation. If not, add the same pattern:
```php
$yes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );
if ( ! $yes ) {
    WP_CLI::confirm( __( 'Revoke this credential? It cannot be restored.', 'mcp-ai-wpoos' ) );
}
```

---

## Phase 3: Consistency Cleanup (1.5 days)

### Task 3.1 — Migrate `list_` methods to `list`

**Affected files (7):**
- `includes/cli/class-wp-mcp-ai-cli-approval-command.php`
- `includes/cli/class-wp-mcp-ai-cli-cron-command.php`
- `includes/cli/class-wp-mcp-ai-cli-provider-command.php`
- `includes/cli/class-wp-mcp-ai-cli-thread-command.php`
- `includes/cli/class-wp-mcp-ai-cli-credential-command.php`
- `includes/cli/class-wp-mcp-ai-cli-tool-command.php`
- `includes/class-wp-mcp-ai-cli-command.php` — `WP_MCP_AI_CLI_Plugins_Command::list_()`

**Migration pattern for each file:**

```php
// BEFORE
public function list_( $args, $assoc_args ) {
    // ... implementation
}

// AFTER
/**
 * List all <items>.
 *
 * ...
 * @subcommand list       ← WP-CLI annotation ensures `wp mcp-ai <group> list` works
 */
public function list_items( $args, $assoc_args ) {
    // ... same implementation
}
```

**Why `list_items` not `list`:** PHP allows `list` as a method name in class context (PHP 7.0+), but some static analysis tools and older linters flag it. Using `list_items` + `@subcommand list` is the safest migration path. The `@subcommand list` annotation tells WP-CLI to expose the method as `list`.

**Note:** `class-wp-mcp-ai-cli-slash-command.php` uses `list()` directly as the method name — this proves PHP allows it. But for consistency and tooling safety, use the `@subcommand` approach.

### Task 3.2 — Add `@when after_wp_load` to all subcommands

**Rationale:** WP-CLI's `@when after_wp_load` ensures WordPress is fully bootstrapped before the command executes. This prevents subtle bugs where `get_option()`, `get_post()`, or `WP_Query` fail because they're called too early.

**Affected methods (approximate — verify with grep):**

```bash
# Find methods missing @when after_wp_load
grep -L '@when after_wp_load' includes/cli/class-wp-mcp-ai-cli-*.php
```

**Pattern — add before `@param` tag:**
```
 * @when after_wp_load
 *
 * @param array $args ...
```

**Insertion point:** Immediately before the `@param` line in every method docblock that reads from the database, options, or post meta. Read-only query commands that only use `WP_CLI::log()` don't strictly need it, but adding it universally prevents future bugs if methods evolve.

### Task 3.3 — Add `get_format()` helper to Base class

**File:** `includes/cli/class-wp-mcp-ai-cli-base-command.php`

**Rationale:** Every command manually reads `$assoc_args['format'] ?? 'table'`. Standardize.

**Implementation — add after `parse_common_args()` (~line 393):**

```php
/**
 * Get the output format from associative args with a default.
 *
 * @param array  $assoc_args Associative arguments from the command.
 * @param string $default    Default format (default: 'table').
 * @return string Sanitised format string.
 */
protected function get_format( $assoc_args, $default = 'table' ) {
    $format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', $default );
    $allowed = array( 'table', 'json', 'yaml', 'csv', 'ids' );
    return in_array( $format, $allowed, true ) ? $format : $default;
}
```

**Usage in commands (replace existing manual reads):**
```php
// BEFORE
$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

// AFTER
$format = $this->get_format( $assoc_args );
```

**Rollout strategy:** Update `get_format()` calls in all files touched during Phase 3. Leave untouched files for a future cleanup pass to minimize diff risk.

---

## Phase 4: UX Polish + Documentation (0.5 days)

### Task 4.1 — Support `wp mcp-ai cache clear` without double nesting

**File:** `includes/class-wp-mcp-ai-cli-command.php`

**Current:** `wp mcp-ai cache clear clear` (double `clear` because WP-CLI nesting: parent `cache` → subcommand `clear` → action `clear`).

**Fix:** Make `clear` the default action when no further subcommand is specified. In the `WP_MCP_AI_CLI_Cache_Command` class, add a `__invoke()` method that delegates to the existing `clear()`:

```php
/**
 * Clear all plugin caches.
 *
 * This is the default action when no subcommand is given.
 *
 * ## EXAMPLES
 *
 *     $ wp mcp-ai cache clear
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Associative arguments.
 */
public function __invoke( $args, $assoc_args ) {
    $this->clear( $args, $assoc_args );
}
```

### Task 4.2 — Add `--assistant-id` alias where `--assistant` is used

**Files affected:**
- `includes/cli/class-wp-mcp-ai-cli-chat-command.php` (already has `--assistant`, add `--assistant-id`)
- `includes/cli/class-wp-mcp-ai-cli-memory-command.php` (already has `--assistant`, add `--assistant-id`)
- `includes/cli/class-wp-mcp-ai-cli-transcript-command.php` (`mine` method — already has `--assistant`)

**Pattern — add to each method's docblock and argument parsing:**

Docblock addition:
```
 * [--assistant-id=<id>]
 * : Alias for --assistant.
```

Code addition (after existing `--assistant` read):
```php
// Accept --assistant-id as an alias for --assistant.
if ( ! $assistant_id && isset( $assoc_args['assistant-id'] ) ) {
    $assistant_id = absint( $assoc_args['assistant-id'] );
}
```

### Task 4.3 — Update README documentation consistency

**File:** Project README (or dedicated `docs/guides/operator/wp-cli.md`)

**Changes:**
1. Document `transcript mine` (not `list`) — or note that `list` is an alias
2. Document actual `thread` subcommands: `compact`, `delete`, `get`, `list`
3. Document `--assistant-id` as accepted alias for `--assistant`
4. Document `plugin activate`/`deactivate` (now implemented)
5. Document `settings set` and `settings reset` (already exist but undocumented)
6. Document `assistant create`, `assistant delete`, `assistant export` (already exist but undocumented)
7. Document `cache clear` single-command shortcut

---

## Phase 5: Testing (1 day)

### Task 5.1 — Add PHPUnit test scaffolding

**Create test files following the existing pattern in `tests/test-wp-cli-tool.php`:**

```bash
tests/test-wp-cli-plugins.php          # activate, deactivate, list
tests/test-wp-cli-assistant-crud.php   # create, delete, update, export
tests/test-wp-cli-settings-write.php   # set, reset
tests/test-wp-cli-tool-disable-confirm.php  # --yes on disable
tests/test-wp-cli-capability-gating.php     # require_capability on mutating commands
tests/test-wp-cli-credential-security.php   # credential issue/revoke gating
```

**Test boilerplate pattern (from `tests/test-wp-cli-tool.php`):**

```php
<?php
/**
 * Tests for WP-CLI plugins command.
 */
class Test_WP_CLI_Plugins extends WP_UnitTestCase {

    protected $admin_user_id = 0;

    public function setUp(): void {
        parent::setUp();
        $this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->admin_user_id );
    }

    public function test_activate_unknown_slug_errors() {
        // Stub WP_CLI::error to capture instead of exit.
        // ...
    }

    public function test_activate_requires_manage_options() {
        // Set subscriber role, assert error.
        // ...
    }
}
```

### Task 5.2 — Manual verification checklist

```bash
# Phase 1 — New commands
wp mcp-ai plugins activate woocommerce --yes
wp mcp-ai plugins deactivate woocommerce --yes
wp mcp-ai plugins activate nonexistent    # Should error
wp mcp-ai transcript list                 # Should delegate to mine

# Phase 2 — Security
wp mcp-ai tool disable search_posts       # Should prompt for confirmation
wp mcp-ai tool disable search_posts --yes # Should skip prompt
wp mcp-ai credential revoke <id>          # Should prompt for confirmation
wp mcp-ai assistant delete 999 --yes      # Should check manage_options
# (As non-admin WP-CLI user — all mutating commands should error)

# Phase 3 — Consistency
wp mcp-ai approval list                   # Should work (was list_)
wp mcp-ai cron list                       # Should work (was list_)
wp mcp-ai provider list                   # Should work (was list_)
wp mcp-ai thread list                     # Should work (was list_)

# Phase 4 — UX
wp mcp-ai cache clear                     # Should work without double-nesting
wp mcp-ai chat "hello" --assistant-id=42  # Should work as alias
```

---

## Consolidated File Change Map

| Phase | File | Change | Risk |
|-------|------|--------|------|
| 1 | `includes/class-wp-mcp-ai-cli-command.php` | Add `activate()`, `deactivate()` to Plugins_Command | Low — additive |
| 1 | `includes/cli/class-wp-mcp-ai-cli-transcript-command.php` | Add `list_()` alias delegating to `mine()` | Low — additive |
| 2 | `includes/cli/class-wp-mcp-ai-cli-base-command.php` | Add `require_capability()` helper | Low — additive |
| 2 | `includes/cli/class-wp-mcp-ai-cli-assistant-command.php` | Add `$this->require_capability()` to `create`, `delete`, `update` | Low |
| 2 | `includes/cli/class-wp-mcp-ai-cli-credential-command.php` | Add capability check + `--yes` to `issue`, `revoke` | Low |
| 2 | `includes/cli/class-wp-mcp-ai-cli-tool-command.php` | Add capability check + `--yes` to `enable`, `disable` | Low |
| 2 | `includes/cli/class-wp-mcp-ai-cli-cron-command.php` | Add capability check to `run`, `delete`, `clear` | Low |
| 2 | `includes/cli/class-wp-mcp-ai-cli-settings-command.php` | Add capability check to `set_`, `reset` | Low |
| 3 | 7 files (list_ → list migration) | Rename methods + `@subcommand list` annotation | Medium — rename |
| 3 | All 20 CLI files | Add `@when after_wp_load` to docblocks | Low — docblock only |
| 3 | `includes/cli/class-wp-mcp-ai-cli-base-command.php` | Add `get_format()` helper | Low — additive |
| 4 | `includes/class-wp-mcp-ai-cli-command.php` | Add `__invoke()` to Cache_Command | Low — additive |
| 4 | Chat, Memory, Transcript commands | Add `--assistant-id` alias | Low — additive |
| 5 | `tests/test-wp-cli-*.php` | 6 new test files | Low — additive |

---

## Risk Assessment

| Risk | Mitigation |
|------|-----------|
| `list_` → `list` breaks WP-CLI command resolution | Keep old `list_` methods as `@subcommand list` annotated; test with `wp mcp-ai <group> list` before merging |
| Capability checks break existing CI scripts that run as system user | WP-CLI runs as user ID 0/1 which typically has `manage_options`; only restrictive setups affected |
| `--yes` on `tool disable` breaks non-interactive scripts | Scripts that don't pass `--yes` will hang waiting for input — update any internal CI scripts |
| `@when after_wp_load` changes command timing | None — commands already call `get_option` etc. which require WP load; this just makes it explicit |

---

## Dependencies Between Tasks

```
Phase 1 (New Subcommands) ──┐
                            ├──→ Phase 3 (Consistency) ──→ Phase 4 (UX)
Phase 2 (Security) ─────────┘
                                                           │
Phase 5 (Testing) ←────────────────────────────────────────┘
```

- Phase 1 and 2 can run in parallel (different files, no conflicts)
- Phase 3 depends on Phase 2 (base class changes needed first)
- Phase 4 depends on Phase 3 (format helper used in UX fixes)
- Phase 5 runs after all code changes

---

## Total Estimated Effort

| Phase | Tasks | Days |
|-------|-------|------|
| 0 — Verification | 1 | 0.5 |
| 1 — New Subcommands | 2 | 1.0 |
| 2 — Security Hardening | 3 | 1.5 |
| 3 — Consistency Cleanup | 3 | 1.5 |
| 4 — UX Polish + Docs | 3 | 0.5 |
| 5 — Testing | 2 | 1.0 |
| **Total** | **14** | **6.0** |
