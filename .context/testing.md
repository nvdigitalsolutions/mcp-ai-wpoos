# NV oOS Testing Patterns

> **GSD Context File** — Load this when writing or reviewing PHPUnit tests.
> Last reviewed: August 31, 2026 (v1.1.65).
>
> **Repair workflow:** when a suite is *failing* (rather than being written),
> use the [`.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md`](../.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md)
> skill — Docker test commands (WP 6.9/7.1), CI-log triage, recurring
> root-cause patterns, and the cluster-by-cluster PR conventions live there.
> This file stays focused on test-writing patterns and the coverage policy.
>
> **New in v1.1.65:** A cluster of suite-alignment PRs (mostly test-only): SSE streaming suites assert the explicit `stream` parameter contract (#5995); WP 7.1 icon-init replay is neutralised in the test bootstrap (#5996); transcript suite follow-ups handle CI environment differences (#5998); Phase 4 slash-command workflow tests run against the current handler API (#5999); SSE tool-result text-extraction tests use the REST constructor (#6001); the transcript retrieval roundtrip is skipped when the CCT table is missing (#6002). Production-side seams that tests depend on: assistant-builder + Pro toolkit blocks skip already-registered names so re-firing `init` doesn't raise notices (#5997); workflow-execution AJAX moved `wp_send_json_*` out of the try block so exceptions can't double-output (#6004). Keep those seams when touching block registration or the orchestration dashboard AJAX.
>
> **New in v1.1.64:** PRs #5931/#5935 — Pro admin classes load directly in AJAX suites, AJAX dispatch is hardened against leaked state, WordPress.com staging APIs are registered, WP All Import no longer kills the test bootstrap, and moved-file `require`s point at their current paths. PR #5960 — validated-tool tests now actually validate: constraint loading was silently skipped on Symfony 5.4 (`enableAnnotationMapping( true )`, not `enableAttributeMapping`), so suites that should fail on invalid input were running against unvalidated paths; keep constraint coverage real when touching the validator service. PR #5965 — envelope assertions must expect the canonical success array or `WP_Error` (never `success => false`).
>
> **New in v1.1.63 (PR #5929):** `bin/sweep-tests.php` sweeps every test file in parallel (6 workers, 180s/file cap, `--report` output) to surface exit-trap and drift failures. AJAX test contracts live in `tests/bootstrap.php` (see `tests/AJAX_TESTS_README.md`): admin AJAX hooks are re-registered per test, and handlers that `die()`/`exit()` directly are guarded with `WP_MCP_AI_TESTS_RUNNING` seams that throw a catchable exception instead. SSE and Veo polling loops honor `wp_mcp_ai_sse_job_max_polls` / `wp_mcp_ai_sse_job_poll_interval` / `wp_mcp_ai_veo_poll_max_attempts` / `wp_mcp_ai_veo_poll_interval` filters so tests can bound them. Remaining suite work is tracked in [`docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md`](../docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md) — prefer `markTestSkipped()` with an explicit reason over deleting assertions, and never weaken security assertions to make a test pass.

---

## Test Framework

- **PHPUnit** via Composer: `composer run test`
- **Base class:** `WP_UnitTestCase` (WordPress test utilities)
- **Test directory:** `tests/` (base plugin), `addons/pro/tests/` (pro tests)
- **Config:** `phpunit.xml.dist`

---

## Test File Naming

| Test Type | File Location | Class Name |
|-----------|--------------|-----------|
| Feature/unit test | `tests/test-{feature}.php` | `Test_{Feature}` |
| REST API test | `tests/rest/test-{endpoint}.php` | `Test_{Endpoint}_REST` |
| REST API integration | `tests/rest-api/test-{name}.php` | `Test_{Name}_API` |
| Helper tests | `tests/helpers/test-{helper}.php` | `Test_{Helper}` |
| Memory tests | `tests/memory/test-{name}.php` | `Test_{Name}_Memory` |
| Pro feature test | `addons/pro/tests/test-{feature}.php` | `Test_Pro_{Feature}` |

---

## Minimal Test Class

```php
<?php
/**
 * Tests for {FeatureName}.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for {FeatureName}.
 */
class Test_{FeatureName} extends WP_UnitTestCase {

    /**
     * Set up test environment.
     */
    public function setUp(): void {
        parent::setUp();
        // Test setup: create users, posts, set options, etc.
    }

    /**
     * Tear down test environment.
     */
    public function tearDown(): void {
        // Cleanup: delete test data, restore options
        parent::tearDown();
    }

    /**
     * Test that {feature} works correctly with valid input.
     */
    public function test_{feature}_with_valid_input() {
        // Arrange
        $input = 'valid input';

        // Act
        $result = wp_mcp_ai_function( $input );

        // Assert
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'success', $result );
        $this->assertTrue( $result['success'] );
    }

    /**
     * Test that {feature} returns WP_Error with invalid input.
     */
    public function test_{feature}_returns_error_with_invalid_input() {
        $result = wp_mcp_ai_function( '' );
        $this->assertWPError( $result );
    }

    /**
     * Test that {feature} requires correct capability.
     */
    public function test_{feature}_requires_capability() {
        // Set unprivileged user
        wp_set_current_user(
            $this->factory->user->create( array( 'role' => 'subscriber' ) )
        );

        $result = wp_mcp_ai_function( 'input' );
        $this->assertWPError( $result );
        $this->assertEquals( 'forbidden', $result->get_error_code() );
    }
}
```

---

## Tool Testing Pattern

```php
/**
 * Tests for WP_MCP_AI_Tool_{Name}.
 */
class Test_Tool_{Name} extends WP_UnitTestCase {

    /**
     * @var WP_MCP_AI_Tool_{Name}
     */
    private $tool;

    public function setUp(): void {
        parent::setUp();
        $this->tool = new WP_MCP_AI_Tool_{Name}();

        // Create an admin user for privileged operations:
        $admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin );
    }

    public function test_get_slug_returns_correct_slug() {
        $this->assertEquals( '{expected_slug}', $this->tool->get_slug() );
    }

    public function test_execute_create_action() {
        $result = $this->tool->execute(
            array( 'action' => 'create', 'name' => 'Test Item' ),
            array()
        );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
    }

    public function test_execute_requires_capability() {
        wp_set_current_user(
            $this->factory->user->create( array( 'role' => 'subscriber' ) )
        );

        $result = $this->tool->execute(
            array( 'action' => 'create' ),
            array()
        );

        $this->assertWPError( $result );
        $this->assertEquals( 'forbidden', $result->get_error_code() );
    }

    public function test_execute_returns_error_for_invalid_action() {
        $result = $this->tool->execute(
            array( 'action' => 'nonexistent_action' ),
            array()
        );

        $this->assertWPError( $result );
    }
}
```

---

## REST API Testing Pattern

```php
/**
 * Tests for /mcp-ai/v1/{endpoint}.
 */
class Test_{Endpoint}_REST extends WP_Test_REST_TestCase {

    public function setUp(): void {
        parent::setUp();
        $this->user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
    }

    public function test_get_items_authenticated() {
        wp_set_current_user( $this->user_id );

        $request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/{endpoint}' );
        $response = rest_do_request( $request );

        $this->assertEquals( 200, $response->get_status() );
        $this->assertArrayHasKey( 'data', $response->get_data() );
    }

    public function test_get_items_unauthenticated_returns_401() {
        wp_set_current_user( 0 );

        $request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/{endpoint}' );
        $response = rest_do_request( $request );

        $this->assertEquals( 401, $response->get_status() );
    }
}
```

---

## Common Test Utilities

```php
// Create test user with role:
$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
wp_set_current_user( $user_id );

// Create test post:
$post_id = $this->factory->post->create( array(
    'post_type'   => 'mcp_ai_assistant',
    'post_status' => 'publish',
    'post_title'  => 'Test Assistant',
) );

// Assert WP_Error:
$this->assertWPError( $result );
$this->assertEquals( 'expected_code', $result->get_error_code() );

// Assert array structure:
$this->assertArrayHasKey( 'success', $result );
$this->assertArrayHasKey( 'data', $result );
$this->assertIsArray( $result['data'] );

// Assert hook fired:
$this->assertFalse( has_action( 'wp_mcp_ai_hook', 'my_callback' ) );
do_action( 'wp_mcp_ai_hook' );
// ...
```

---

## Minimum Test Coverage Per Story

Every story that modifies or creates a tool must have **at least 3 test methods**:
1. Happy path (valid input, expected result)
2. Error path (invalid input, returns `WP_Error`)
3. Capability check (unprivileged user is rejected)

REST endpoint stories need at minimum:
1. Authenticated GET/POST returns expected status code
2. Unauthenticated request returns 401
3. Invalid input returns 400

---

## Running Tests

### Option A: Docker (recommended for Windows / no local PHP)

```bash
# Prerequisites (once):
composer install                        # install dev dependencies
docker compose up -d                    # start WP + MySQL containers

# Run tests:
bash bin/run-tests-docker.sh                                   # all tests
bash bin/run-tests-docker.sh tests/test-admin-settings.php     # single file
bash bin/run-tests-docker.sh --filter='test_default_provider' tests/test-admin-settings.php
```

The script automatically:
- Creates the `wordpress_test` database if missing
- Refreshes the Composer autoloader (fixes stale bind-mount caches)
- Handles Git Bash path mangling (`MSYS_NO_PATHCONV`)
- Passes all arguments through to PHPUnit

> **Note:** After `composer install` or `composer dump-autoload`, restart Docker
> if tests fail with class-not-found errors — the bind mount can cache stale files.
> Shortcut: `docker compose down && docker compose up -d`

### Option B: Local PHP + MySQL

```bash
# Install test dependencies (first time only):
composer run test:install

# Run all tests:
composer run test

# Run a specific test file:
vendor/bin/phpunit tests/test-{name}.php

# Run tests with verbose output:
vendor/bin/phpunit --verbose

# Run with coverage (requires Xdebug):
vendor/bin/phpunit --coverage-html coverage/
```

### Option C: Codex / SQLite (self-contained, no Docker)

```bash
bash bin/codex-startup.sh               # download WP + SQLite, start server
composer run test                       # run tests against the Codex WP
```

---

## Coverage Policy (PHPUnit Test Coverage Gap-Filling Plan)

**Every PR that adds new code must add at least one PHPUnit test in the same PR.** Specifically:

| Change | Required tests |
|---|---|
| New base tool (`includes/tools/class-*.php`) | `tests/test-tool-{slug}.php` covering at minimum the unauthorised-user case + one happy path |
| New pro tool (`addons/pro/includes/tools/class-*.php`) | Test under `addons/pro/tests/` referencing the tool class name |
| New REST controller / route | Permission callback + schema + at least one happy path under `tests/rest/` or `tests/rest-api/` |
| New slash command (`includes/slash-commands/commands/class-*.php`) | `tests/test-slash-command-{name}.php` with output shape + capability gate + alias resolution |
| New harness layer (`includes/harness/`, `addons/pro/includes/harness/`) | Layer enable/disable + documented filter (`wp_mcp_ai_harness_*`) firing |
| New service class | Either a direct unit test or coverage via the REST/tool surface that consumes it |

### Baseline & non-regression gate

- The per-subsystem coverage floors live in [`tests/.coverage-baseline.json`](../tests/.coverage-baseline.json).
- The `PHPUnit` GitHub workflow runs `bin/find-untested-classes.sh --check` and fails any PR that drops the count of covered classes for a subsystem below its baseline.
- Floors **must only ratchet upward**. Never lower a floor without an explicit justification in the PR description.
- Locally, run `composer run test:gaps` to see the full list of untested classes per subsystem, or `composer run test:gaps:check` to verify the baseline before opening a PR.

### Recomputing the baseline after coverage improves

```bash
# Print current covered counts for all subsystems
bin/find-untested-classes.sh --check
# Then update tests/.coverage-baseline.json so subsystem_floors.<name>.covered_classes_min
# matches the new (higher) covered count, and bump test_file_floor.min_count if it grew.
```

The baseline file's `subsystem_floors` keys correspond 1:1 to the categories in §2 of the PHPUnit Test Coverage Gap-Filling Plan.

---

## Tool registry coverage smoke test

`tests/test-tool-registry-coverage.php` is a single data-driven smoke test that locks the contract for every registered tool in one place. It asserts:

1. `get_slug()` returns a non-empty string that survives `sanitize_key()` unchanged.
2. The parameter schema (from `get_parameters_schema()` or `get_definition()['parameters']`) contains no `'mixed'` types and every `type:'array'` declares `items`.
3. `get_required_capability()` (when present) resolves to a non-empty string or array of strings.
4. `execute()` does not throw when invoked by an unauthenticated caller (a logged-out user with no capabilities).

It is paired with two manifest files that list every tool-class file basename so `bin/find-untested-classes.sh` recognises the smoke test as covering the entire tool registry:

- `tests/tools/.coverage-manifest.txt` — base tools under `includes/tools/`
- `addons/pro/tests/tools/.coverage-manifest.txt` — pro tools under `addons/pro/includes/tools/`

**Whenever you add, remove or rename a tool class, regenerate the manifest:**

```bash
bin/generate-tool-coverage-manifest.sh
```

The smoke test itself includes an assertion that fails when the manifest is stale, so CI catches drift even if a contributor forgets the regen step.

When you want to add behavioural coverage for a high-risk tool (write/state-changing, external API, file/upload), add a dedicated test under `tests/tools/` (base) or `addons/pro/tests/tools/` (pro) — those tests stack on top of the smoke test rather than replacing it.

### Coverage matcher: kebab → PascalCase

`bin/find-untested-classes.sh` recognises a class as "covered" if any test file under `tests/` or `addons/*/tests/` references **either**:

1. The kebab-case file basename (e.g. `wp-mcp-ai-harness-profile`), as appears in the coverage manifests, or
2. The PascalCase class name derived from that basename (e.g. `WP_MCP_AI_Harness_Profile`), as appears in normal PHPUnit `use`/instance-of references.

The acronyms `WP`, `MCP`, and `AI` stay fully uppercase; every other segment is title-cased. So `wp-mcp-ai-pii-filter` matches `WP_MCP_AI_Pii_Filter`, and `wp-mcp-ai-tool-router-harness` matches `WP_MCP_AI_Tool_Router_Harness`.

Tests therefore do not need to mention class file basenames; referencing the class symbol naturally is enough to credit coverage.
