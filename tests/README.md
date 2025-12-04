# WP MCP AI Test Suite

This document explains how to set up and run the test suite for the WP MCP AI plugin.

## Prerequisites

### System Requirements

- PHP 8.1 or later
- MySQL 8.0 or later (or MariaDB equivalent)
- Composer
- Git

### Installing Dependencies

```bash
# Install PHP and JavaScript dependencies
composer install
npm install
```

## Test Database Setup

The test suite requires a MySQL database for WordPress test framework.

### Option 1: Using MySQL Service (CI/Local)

```bash
# Create test database
mysql -u root -p <<EOF
CREATE DATABASE IF NOT EXISTS wordpress_test;
GRANT ALL PRIVILEGES ON wordpress_test.* TO 'root'@'localhost';
FLUSH PRIVILEGES;
EOF

# Install WordPress test framework
composer run test:install
# Or manually:
# bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

### Option 2: Using Docker Compose

```bash
# Start MySQL service
docker compose up -d mysql

# Install WordPress test framework with Docker MySQL
bash bin/install-wp-tests.sh wordpress_test wordpress wordpress 127.0.0.1 latest
```

## Running Tests

### Full Test Suite

```bash
composer test
```

### Running Specific Tests

```bash
# Run single test file
vendor/bin/phpunit tests/test-rest-authentication.php

# Run specific test method
vendor/bin/phpunit --filter test_permissions_check_allows_author_with_valid_nonce tests/test-rest-authentication.php

# Run tests in a directory
vendor/bin/phpunit tests/rest/
```

### Running Tests with Coverage

```bash
vendor/bin/phpunit --coverage-html coverage/
```

## Test Organization

### Test Directory Structure

```
tests/
├── bootstrap.php                          # Test bootstrap and setup
├── fixtures/                              # Binary test fixtures (images, PDFs, etc.)
├── helpers/                               # Reusable test traits and utilities
│   ├── trait-wp-mcp-ai-docx-test-helper.php
│   └── trait-wp-mcp-ai-rest-test-helper.php
├── rest/                                  # REST API integration tests
├── rest-api/                              # REST API unit tests
└── test-*.php                             # Individual test files
```

### Test Helpers

The test suite provides reusable traits for common test scenarios:

#### `WP_MCP_AI_REST_Test_Helper`

Provides methods for testing REST endpoints:

```php
class My_REST_Test extends WP_UnitTestCase {
    use WP_MCP_AI_REST_Test_Helper;

    public function test_something() {
        // Create test assistant
        $assistant_id = $this->create_assistant_post('Test Assistant');

        // Create authenticated user
        $user_id = $this->create_test_user('administrator');
        wp_set_current_user($user_id);

        // Create authenticated REST request
        $request = $this->create_authenticated_request(
            'POST',
            '/mcp-ai/v1/chat',
            array('assistant_id' => $assistant_id)
        );

        // Bootstrap REST controller with mock client
        $mock_client = $this->getMockBuilder(WP_MCP_AI_Language_Model_Router::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->bootstrap_rest_controller($mock_client);

        // Dispatch request
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
    }
}
```

#### `WP_MCP_AI_Docx_Test_Helper`

Provides methods for creating DOCX test files:

```php
class My_File_Test extends WP_UnitTestCase {
    use WP_MCP_AI_Docx_Test_Helper;

    public function test_docx_processing() {
        $upload = $this->create_docx_upload('test.docx', 'Test content');
        $this->assertFalse($upload['error']);
    }
}
```

## Writing Tests

### Test Structure

Follow WordPress PHPUnit testing best practices:

```php
<?php
/**
 * Tests for My Feature
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

class My_Feature_Test extends WP_UnitTestCase {

    /**
     * Set up test fixtures before each test.
     */
    public function setUp(): void {
        parent::setUp();
        // Your setup code
    }

    /**
     * Clean up after each test.
     */
    public function tearDown(): void {
        // Your cleanup code
        parent::tearDown();
    }

    /**
     * Test that feature works correctly.
     */
    public function test_feature_works() {
        // Arrange
        $input = 'test data';

        // Act
        $result = my_function($input);

        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### REST API Testing Patterns

#### Authentication Testing

```php
public function test_rest_endpoint_requires_authentication() {
    $request = new WP_REST_Request('POST', '/mcp-ai/v1/endpoint');
    $response = rest_get_server()->dispatch($request);
    $this->assertSame(401, $response->get_status());
}

public function test_rest_endpoint_with_valid_nonce() {
    $user_id = self::factory()->user->create(array('role' => 'administrator'));
    wp_set_current_user($user_id);

    $request = new WP_REST_Request('POST', '/mcp-ai/v1/endpoint');
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

    $response = rest_get_server()->dispatch($request);
    $this->assertSame(200, $response->get_status());
}
```

#### Permission Testing

```php
public function test_rest_endpoint_checks_capabilities() {
    // Create user with insufficient capabilities
    $user_id = self::factory()->user->create(array('role' => 'subscriber'));
    wp_set_current_user($user_id);

    $request = new WP_REST_Request('POST', '/mcp-ai/v1/endpoint');
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

    $response = rest_get_server()->dispatch($request);
    $this->assertSame(403, $response->get_status());
}
```

### Creating Test Fixtures

#### Users

```php
// Create user with specific role
$user_id = self::factory()->user->create(array('role' => 'editor'));

// Create user with custom capabilities
$user_id = self::factory()->user->create(array('role' => 'subscriber'));
$user = get_user_by('id', $user_id);
$user->add_cap('custom_capability');
```

#### Posts/Assistants

```php
// Create assistant post
$assistant_id = wp_insert_post(array(
    'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
    'post_title'  => 'Test Assistant',
    'post_status' => 'publish',
));

// Create with meta
update_post_meta($assistant_id, 'meta_key', 'meta_value');
```

#### Credentials

```php
// Issue assistant credential
$issuer_id = self::factory()->user->create(array('role' => 'administrator'));
wp_set_current_user($issuer_id);
$issued = WP_MCP_AI_Credentials::issue_credential($assistant_id, $issuer_id);
$token = $issued['token'];
```

## Common Issues and Solutions

### Issue: Database Connection Error

**Error:** `Error establishing a database connection`

**Solution:**
1. Ensure MySQL is running: `sudo service mysql start`
2. Verify database credentials match those in `tests/wp-tests-config.php`
3. Run `composer run test:install` to set up the test database

### Issue: WordPress Core Not Found

**Error:** `Could not locate a WordPress installation`

**Solution:**
1. Run the install script: `bash bin/install-wp-tests.sh wordpress_test root '' localhost latest`
2. Or set environment variable: `export WP_CORE_DIR=/path/to/wordpress`

### Issue: Tests Fail with 401/403 Errors

**Cause:** REST authentication not properly set up in test

**Solution:**
1. Ensure user is created and set as current: `wp_set_current_user($user_id)`
2. Add nonce header to request: `$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'))`
3. Verify user has required capabilities
4. Use helper trait methods: `$this->create_authenticated_request()`

### Issue: REST Server Not Initialized

**Cause:** Tests calling REST endpoints before server is ready

**Solution:**
1. Call `rest_get_server()` before dispatching requests
2. Use `bootstrap_rest_controller()` helper method
3. Ensure `rest_api_init` action is fired: `do_action('rest_api_init')`

### Issue: Mock Objects Not Working

**Cause:** Incorrect mock setup or expectations

**Solution:**
1. Use `getMockBuilder()` with `disableOriginalConstructor()`
2. Specify methods to mock with `onlyMethods()`
3. Set expectations before using: `$mock->expects($this->once())->method('...')`

## Debugging Tests

### Enable Debug Output

```bash
# Run with verbose output
vendor/bin/phpunit --verbose

# Run with debug output
vendor/bin/phpunit --debug

# Show test names as they run
vendor/bin/phpunit --testdox
```

### Inspect Test Failures

```bash
# Stop on first failure
vendor/bin/phpunit --stop-on-failure

# Stop on first error
vendor/bin/phpunit --stop-on-error
```

### Use var_dump() and print_r()

PHPUnit will capture and display output from failed tests:

```php
public function test_something() {
    var_dump($some_variable);
    print_r($array_data);
    $this->assertTrue(false); // Force failure to see output
}
```

## Continuous Integration

The test suite runs automatically on GitHub Actions for all pull requests.

### CI Configuration

See `.github/workflows/phpunit.yml` for the CI configuration.

### Local CI Simulation

```bash
# Install dependencies
composer install --no-interaction --prefer-dist

# Set up WordPress test framework
bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Run tests with coverage
vendor/bin/phpunit --coverage-clover coverage.xml
```

## Best Practices

1. **Always clean up** - Use `tearDown()` to remove test data and reset state
2. **Isolate tests** - Each test should be independent and not rely on others
3. **Use factories** - Use `self::factory()` to create test data
4. **Mock external calls** - Don't make real API calls in tests
5. **Test edge cases** - Test boundary conditions and error states
6. **Use descriptive names** - Test method names should describe what they test
7. **Keep tests fast** - Avoid slow operations when possible
8. **Document complex setup** - Add comments explaining non-obvious test setup

## Resources

- [WordPress PHPUnit Testing](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress Test Factory](https://make.wordpress.org/core/handbook/testing/automated-testing/writing-phpunit-tests/#fixtures-and-factories)
- [WordPress REST API Testing](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/#testing)
