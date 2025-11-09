# PHPUnit Test Suite Optimization Guide

## Overview

This guide documents the optimizations and best practices for the WP oOS PHPUnit test suite to ensure fast, reliable, and maintainable tests.

**Last Updated**: November 9, 2024

## Test Suite Organization

### Test Suites

The PHPUnit configuration now includes organized test suites for targeted testing:

```bash
# Run all tests (default)
vendor/bin/phpunit

# Run only unit tests (excludes integration, performance, security)
vendor/bin/phpunit --testsuite unit

# Run REST API tests
vendor/bin/phpunit --testsuite rest-api

# Run performance tests
vendor/bin/phpunit --testsuite performance

# Run security tests
vendor/bin/phpunit --testsuite security

# Run crawler tests
vendor/bin/phpunit --testsuite crawler

# Run memory tests
vendor/bin/phpunit --testsuite memory
```

### Directory Structure

```
tests/
├── bootstrap.php           # Test bootstrap (auto-loads WP test framework)
├── wp-tests-config.php     # WordPress test database config
├── helpers/                # Test helper classes and traits (excluded from test discovery)
├── rest/                   # REST API endpoint tests
├── rest-api/               # Additional REST API integration tests
├── performance/            # Performance benchmark tests
├── security/               # Security-focused tests
├── crawler/                # Crawl4AI integration tests
├── memory/                 # Memory usage tests
└── test-*.php              # Unit and integration tests
```

## Configuration Optimizations

### phpunit.xml.dist Improvements

The PHPUnit configuration has been optimized with the following settings:

#### 1. Strict Testing Modes

```xml
<phpunit
    beStrictAboutOutputDuringTests="true"
    beStrictAboutTestsThatDoNotTestAnything="false"
    beStrictAboutTodoAnnotatedTests="false"
    convertDeprecationsToExceptions="false"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true">
```

**Benefits:**
- Catches unexpected output during tests
- Allows intentionally empty tests for optional features
- Tolerates TODO annotations
- Properly handles PHP errors and warnings

#### 2. Test Suite Organization

Tests are organized into logical suites for faster selective testing:

- **unit**: Core functionality tests (fastest)
- **rest-api**: REST endpoint tests
- **performance**: Performance benchmarks (slower)
- **security**: Security validation tests
- **crawler**: Crawl4AI integration tests
- **memory**: Memory usage tests

#### 3. Code Coverage Configuration

```xml
<coverage processUncoveredFiles="false">
    <include>
        <directory suffix=".php">includes</directory>
    </include>
    <exclude>
        <directory suffix=".php">includes/tools/examples</directory>
        <directory suffix=".php">includes/vendor</directory>
    </exclude>
</coverage>
```

**Benefits:**
- Focuses coverage on actual plugin code
- Excludes example files and vendor code
- Faster coverage generation

#### 4. PHP Configuration

```xml
<php>
    <ini name="error_reporting" value="-1"/>
    <ini name="display_errors" value="1"/>
    <ini name="display_startup_errors" value="1"/>
    <ini name="memory_limit" value="512M"/>
</php>
```

**Benefits:**
- Full error reporting for better debugging
- Adequate memory for complex tests
- Consistent PHP configuration across environments

## Test Quality Standards

### Test Method Patterns

#### setUp() and tearDown()

**Required Pattern:**
```php
public function setUp(): void {
    parent::setUp();  // ALWAYS call parent first
    
    // Your test setup code
}

public function tearDown(): void {
    // Your cleanup code
    
    parent::tearDown();  // ALWAYS call parent last
}
```

**Status:** ✅ All test files properly call parent methods (verified Nov 2024)

#### Static Properties

Static properties in tests can cause state leakage between tests. When needed:

1. **Declare with purpose**: Only use for mock/stub state
2. **Provide reset method**: Add a static `reset()` method
3. **Call in tearDown**: Reset state in tearDown method

**Example:**
```php
class Mock_Service {
    public static $next_response = null;
    
    public static function reset() {
        self::$next_response = null;
    }
}

class My_Test extends WP_UnitTestCase {
    protected function tearDown(): void {
        Mock_Service::reset();
        parent::tearDown();
    }
}
```

**Status:** ✅ All test files with static properties properly reset them (verified Nov 2024)

### Test Complexity Guidelines

Based on analysis of the test suite:

#### Average Lines Per Test

| Complexity Level | Lines/Test | Action Needed |
|-----------------|------------|---------------|
| Simple | < 30 | ✅ Ideal |
| Moderate | 30-60 | ✅ Acceptable |
| Complex | 60-100 | ⚠️ Consider splitting |
| Very Complex | > 100 | ❌ Should be split |

**Current Stats:**
- Most tests: 30-60 lines (good)
- 10 tests > 90 lines per test (acceptable for integration tests)
- No tests > 150 lines per test

#### When to Split Tests

Split large test files when:
- Single test method exceeds 150 lines
- File has > 20 test methods
- Tests cover unrelated functionality
- Test class exceeds 1000 lines

**Example Split:**
```
Before: test-openai-client.php (2639 lines, all OpenAI functionality)
After:  test-openai-chat.php (chat completion tests)
        test-openai-images.php (image generation tests)
        test-openai-audio.php (audio transcription tests)
```

### Conditional Test Execution

#### markTestSkipped Usage

**Appropriate Use Cases:**
- Optional plugin dependencies (WooCommerce, Elementor, JetEngine)
- Performance monitoring features (CCT classes)
- Multisite-specific features

**Pattern:**
```php
public function test_woocommerce_integration() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        $this->markTestSkipped( 'WooCommerce is not active.' );
    }
    
    // Test code...
}
```

**Current Stats:**
- 84 skipped tests (all appropriate for optional features)
- 0 incomplete tests

#### Conditional Test Groups

Use `@group` annotations for optional test sets:

```php
/**
 * @group woocommerce
 * @group integration
 */
class WP_MCP_AI_Woo_Tools_Test extends WP_UnitTestCase {
    // ...
}
```

Run with: `vendor/bin/phpunit --exclude-group woocommerce`

## Performance Optimizations

### 1. Database Optimization

**Use WordPress Test Factory:**
```php
// Good: Uses factory (cleanup automatic)
$user_id = $this->factory->user->create();
$post_id = $this->factory->post->create();

// Avoid: Manual database queries
global $wpdb;
$wpdb->insert( $wpdb->users, $data );
```

**Benefits:**
- Automatic cleanup after tests
- Consistent data generation
- Faster than manual SQL

### 2. HTTP Request Mocking

**Use WordPress HTTP API Filters:**
```php
public function setUp(): void {
    parent::setUp();
    
    // Mock HTTP requests
    add_filter( 'pre_http_request', array( $this, 'mock_http_response' ), 10, 3 );
}

public function mock_http_response( $response, $args, $url ) {
    if ( strpos( $url, 'api.openai.com' ) !== false ) {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => json_encode( array( 'result' => 'mocked' ) ),
        );
    }
    return $response;
}

public function tearDown(): void {
    remove_filter( 'pre_http_request', array( $this, 'mock_http_response' ), 10 );
    parent::tearDown();
}
```

**Benefits:**
- No external network dependencies
- Faster test execution
- Predictable test results

### 3. Test Data Fixtures

**Create Helper Methods:**
```php
protected function create_test_assistant( $overrides = array() ) {
    $defaults = array(
        'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
        'post_status' => 'publish',
        'post_title'  => 'Test Assistant',
    );
    
    return $this->factory->post->create( wp_parse_args( $overrides, $defaults ) );
}
```

**Benefits:**
- Reduces code duplication
- Ensures consistent test data
- Easier to maintain

### 4. Caching Test Results

**Use setUpBeforeClass for Expensive Operations:**
```php
class Expensive_Test extends WP_UnitTestCase {
    protected static $shared_data;
    
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        
        // Expensive operation run once for all tests in this class
        self::$shared_data = expensive_data_generation();
    }
    
    public static function tearDownAfterClass(): void {
        self::$shared_data = null;
        parent::tearDownAfterClass();
    }
}
```

**Warning:** Only use for truly immutable shared data. Most tests should use `setUp()`.

## Running Tests Efficiently

### Parallel Testing

PHPUnit doesn't natively support parallel test execution, but you can run test suites in parallel:

```bash
# Terminal 1
vendor/bin/phpunit --testsuite unit

# Terminal 2
vendor/bin/phpunit --testsuite rest-api

# Terminal 3
vendor/bin/phpunit --testsuite security
```

### Selective Test Execution

```bash
# Run specific test file
vendor/bin/phpunit tests/test-openai-client.php

# Run specific test method
vendor/bin/phpunit --filter test_create_chat_completion

# Run tests matching pattern
vendor/bin/phpunit --filter "chat.*completion"

# Exclude slow tests
vendor/bin/phpunit --exclude-group performance,slow
```

### Fast Feedback Loop

For rapid development:

```bash
# Run just the tests you're working on
vendor/bin/phpunit --filter test_my_new_feature --stop-on-failure
```

## Continuous Integration

### GitHub Actions Configuration

The PHPUnit workflow (`.github/workflows/phpunit.yml`) is optimized with:

1. **Dependency Caching**: Composer packages are cached
2. **MySQL Service**: Dedicated database service
3. **PHP 8.1**: Latest stable PHP version
4. **Parallel Jobs**: Future enhancement opportunity

### Optimization Opportunities

**Future Enhancements:**
- [ ] Matrix testing (PHP 7.4, 8.0, 8.1, 8.2, 8.3)
- [ ] Parallel test suite execution
- [ ] Code coverage reporting
- [ ] Performance regression detection

## Test Maintenance

### When Adding New Tests

1. **Choose the Right Location:**
   - `tests/` for unit tests
   - `tests/rest/` or `tests/rest-api/` for REST endpoint tests
   - `tests/performance/` for benchmarks
   - `tests/security/` for security tests

2. **Follow Naming Conventions:**
   - Files: `test-feature-name.php`
   - Classes: `WP_MCP_AI_Feature_Name_Test`
   - Methods: `test_specific_behavior()`

3. **Add Appropriate Annotations:**
   ```php
   /**
    * @group feature-area
    * @group integration
    */
   ```

4. **Update Documentation:**
   - Add to `docs/TEST-COVERAGE-SUMMARY.md`
   - Update this guide if adding new patterns

### When Modifying Tests

1. **Run Affected Tests:** Test the specific file first
2. **Run Full Suite:** Ensure no regressions
3. **Check Coverage:** Maintain or improve coverage
4. **Update Docs:** Keep documentation in sync

## Troubleshooting

### Common Issues

#### Tests Fail in CI but Pass Locally

**Possible Causes:**
- Different PHP versions
- Different WordPress versions
- Missing database setup
- Timezone differences

**Solution:**
```bash
# Match CI environment locally
export WP_DB_NAME=wordpress_test
export WP_DB_USER=wordpress
export WP_DB_PASSWORD=wordpress
export WP_DB_HOST=127.0.0.1

bin/install-wp-tests.sh $WP_DB_NAME $WP_DB_USER $WP_DB_PASSWORD $WP_DB_HOST latest
vendor/bin/phpunit
```

#### Tests are Slow

**Diagnosis:**
```bash
# Run with timing information
vendor/bin/phpunit --verbose
```

**Solutions:**
- Skip performance tests: `--exclude-group performance`
- Mock HTTP requests
- Use test factories instead of manual DB operations
- Split large test files

#### Memory Issues

**Error:** `Fatal error: Allowed memory size exhausted`

**Solutions:**
```bash
# Increase memory limit
php -d memory_limit=1G vendor/bin/phpunit

# Or update phpunit.xml.dist
<php>
    <ini name="memory_limit" value="1G"/>
</php>
```

## Best Practices Summary

### ✅ Do

- Call `parent::setUp()` first and `parent::tearDown()` last
- Use WordPress test factory for data creation
- Mock external HTTP requests
- Use descriptive test names
- Group related tests
- Clean up state in `tearDown()`
- Test one thing per test method
- Use appropriate `markTestSkipped()` for optional features

### ❌ Don't

- Rely on test execution order
- Use static properties without cleanup
- Make real HTTP requests
- Leave global state changes
- Create tests > 150 lines
- Test WordPress core functionality
- Use production API keys
- Skip tests that should pass

## Metrics and Goals

### Current State (November 2024)

| Metric | Value | Status |
|--------|-------|--------|
| Total Test Files | 206 | ✅ |
| Total Test Methods | 1,754 | ✅ |
| Skipped Tests | 84 | ✅ (appropriate) |
| Missing tearDown | 0 | ✅ |
| Avg Lines/Test | 35 | ✅ |
| Test Suite Organization | 7 suites | ✅ |

### Goals

- ✅ Maintain < 50 avg lines per test
- ✅ All tests call parent methods properly
- ✅ Organized test suites for selective execution
- ✅ Comprehensive coverage configuration
- ⏳ Add parallel test execution (future)
- ⏳ Add performance regression tracking (future)

## Resources

- **PHPUnit Documentation**: https://phpunit.de/documentation.html
- **WordPress Test Suite**: https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/
- **Test Coverage Summary**: `docs/TEST-COVERAGE-SUMMARY.md`
- **Performance Testing**: `docs/performance-testing-guide.md`

---

**Maintained By**: WP oOS Development Team
**Last Reviewed**: November 9, 2024
