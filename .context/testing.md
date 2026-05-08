# NV oOS Testing Patterns

> **GSD Context File** — Load this when writing or reviewing PHPUnit tests.
> Last reviewed: March 2026.

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
