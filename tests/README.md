# WP oOS Test Suite

This directory contains the PHPUnit test suite for the WP Open Operator System (WP oOS) plugin.

## Quick Start

### Base Functionality Testing

To perform a **complete test of the base plugin functionality**:

```bash
# Run comprehensive base functionality tests
./bin/run-base-tests.sh

# Run with verbose output
./bin/run-base-tests.sh --verbose

# Run with code coverage report
./bin/run-base-tests.sh --coverage
```

The base functionality test suite includes:
- ✅ 40+ automated tests validating core features
- ✅ Plugin initialization and constants
- ✅ Base version detection
- ✅ Assistant CPT registration
- ✅ Tool registry (35+ base tools)
- ✅ REST API endpoints
- ✅ Authentication mechanisms
- ✅ Security features
- ✅ No third-party dependencies required

**For manual testing scenarios**, see: [`docs/BASE-FUNCTIONALITY-TEST-PLAN.md`](../docs/BASE-FUNCTIONALITY-TEST-PLAN.md)

### Full Test Suite

```bash
# Install dependencies
composer install

# Set up WordPress test environment (one time)
composer run test:install
# OR
bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Run all tests
composer run test
# OR
vendor/bin/phpunit
```

## Directory Structure

```
tests/
├── bootstrap.php           # Test bootstrap - loads WordPress test framework
├── wp-tests-config.php     # WordPress test database configuration
├── README.md              # This file
├── helpers/               # Test helper classes and traits
│   ├── elementor-stubs.php
│   ├── woocommerce-stubs.php
│   └── trait-wp-mcp-ai-docx-test-helper.php
├── rest/                  # REST API endpoint tests
├── rest-api/              # Additional REST API integration tests
├── performance/           # Performance benchmark tests
├── security/              # Security-focused tests
├── crawler/               # Crawl4AI integration tests
├── memory/                # Memory usage tests
└── test-*.php             # Unit and integration tests
```

## Test Suites

Tests are organized into logical suites for targeted testing:

### Run Specific Test Suites

```bash
# Unit tests only (fastest - excludes integration/performance/security)
vendor/bin/phpunit --testsuite unit

# REST API tests
vendor/bin/phpunit --testsuite rest-api

# Performance tests
vendor/bin/phpunit --testsuite performance

# Security tests
vendor/bin/phpunit --testsuite security

# Crawler tests
vendor/bin/phpunit --testsuite crawler

# Memory tests
vendor/bin/phpunit --testsuite memory

# All tests (default)
vendor/bin/phpunit
```

## Running Specific Tests

```bash
# Run a specific test file
vendor/bin/phpunit tests/test-openai-client.php

# Run a specific test method
vendor/bin/phpunit --filter test_create_chat_completion

# Run tests matching a pattern
vendor/bin/phpunit --filter "chat.*completion"

# Run with verbose output
vendor/bin/phpunit --verbose

# Stop on first failure
vendor/bin/phpunit --stop-on-failure
```

## Writing Tests

### File Naming Convention

- **Test files**: `test-feature-name.php`
- **Test classes**: `WP_MCP_AI_Feature_Name_Test` or `WP_MCP_AI_Feature_Name_Tests`
- **Test methods**: `test_specific_behavior_description()`

### Test File Template

```php
<?php
/**
 * Tests for Feature Name functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * @group feature-area
 */
class WP_MCP_AI_Feature_Test extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        
        // Your test setup code
    }

    public function tearDown(): void {
        // Your cleanup code
        
        parent::tearDown();
    }

    /**
     * Test description.
     */
    public function test_specific_behavior() {
        // Arrange
        $input = 'test data';
        
        // Act
        $result = function_to_test( $input );
        
        // Assert
        $this->assertEquals( 'expected', $result );
    }
}
```

### Best Practices

#### ✅ Do

- Call `parent::setUp()` first and `parent::tearDown()` last
- Use WordPress test factory for data creation: `$this->factory->post->create()`
- Mock external HTTP requests using filters
- Use descriptive test method names
- Add `@group` annotations for test organization
- Clean up state in `tearDown()`
- Test one thing per test method

#### ❌ Don't

- Rely on test execution order
- Make real HTTP requests to external APIs
- Test WordPress core functionality
- Use production API keys
- Create tests longer than 150 lines
- Skip calling parent methods

### Test Groups

Use `@group` annotations to organize tests:

```php
/**
 * @group rest-api
 * @group integration
 */
class My_REST_Test extends WP_UnitTestCase {
    // ...
}
```

Run tests by group:
```bash
vendor/bin/phpunit --group rest-api
vendor/bin/phpunit --exclude-group slow,performance
```

### Optional Dependencies

For tests that depend on optional plugins (WooCommerce, Elementor, JetEngine):

```php
public function test_woocommerce_feature() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        $this->markTestSkipped( 'WooCommerce is not active.' );
    }
    
    // Test code...
}
```

## Helper Files

### Available Helpers

- **elementor-stubs.php**: Mock Elementor classes for testing without Elementor
- **woocommerce-stubs.php**: Mock WooCommerce classes for testing without WooCommerce
- **trait-wp-mcp-ai-docx-test-helper.php**: Helper trait for DOCX file testing

### Using Helpers

```php
// In your test file
require_once __DIR__ . '/helpers/elementor-stubs.php';

class My_Test extends WP_UnitTestCase {
    use WP_MCP_AI_DOCX_Test_Helper;
    
    // ...
}
```

## Test Database

Tests use a SQLite database by default for faster execution:

- **Location**: `.codex-wordpress/tests-database/wptests.sqlite`
- **Configuration**: `tests/wp-tests-config.php`

To use MySQL instead, set environment variables before running tests:

```bash
export WP_DB_NAME=wordpress_test
export WP_DB_USER=wordpress
export WP_DB_PASSWORD=wordpress
export WP_DB_HOST=127.0.0.1

bin/install-wp-tests.sh $WP_DB_NAME $WP_DB_USER $WP_DB_PASSWORD $WP_DB_HOST latest
vendor/bin/phpunit
```

## Code Coverage

Generate code coverage report (requires Xdebug):

```bash
# Generate HTML report
vendor/bin/phpunit --coverage-html coverage/

# View report
open coverage/index.html
```

## Troubleshooting

### WordPress Test Framework Not Found

```
Could not find the WordPress tests directory
```

**Solution:**
```bash
composer run test:install
# OR
bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

### Memory Limit Issues

```
Fatal error: Allowed memory size exhausted
```

**Solution:**
```bash
php -d memory_limit=512M vendor/bin/phpunit
```

Or update `phpunit.xml.dist`:
```xml
<php>
    <ini name="memory_limit" value="512M"/>
</php>
```

### Slow Tests

**Solutions:**
- Run unit tests only: `vendor/bin/phpunit --testsuite unit`
- Exclude performance tests: `vendor/bin/phpunit --exclude-group performance`
- Run specific test file instead of full suite
- Mock HTTP requests to external services

## Documentation

- **Optimization Guide**: `docs/PHPUNIT-OPTIMIZATION-GUIDE.md` - Best practices and performance tips
- **Coverage Summary**: `docs/TEST-COVERAGE-SUMMARY.md` - Test statistics and coverage details
- **Performance Guide**: `docs/performance-testing-guide.md` - Performance testing methodology

## Continuous Integration

Tests run automatically on GitHub Actions for:
- Pull requests
- Pushes to main branch
- Release tags

See `.github/workflows/phpunit.yml` for CI configuration.

## Getting Help

- Check existing tests for examples
- Read the optimization guide: `docs/PHPUNIT-OPTIMIZATION-GUIDE.md`
- Review WordPress testing handbook: https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/
- PHPUnit documentation: https://phpunit.de/documentation.html

---

**Last Updated**: November 9, 2024
