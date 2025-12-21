# Testing Guide - Open Operator System

This document describes how to set up and run tests for the Open Operator System (WP oOS) plugin.

## Overview

The plugin includes comprehensive test suites:

### PHP Tests (PHPUnit)
- 100+ tests covering:
  - REST API endpoints
  - Tool implementations
  - Helper functions
  - Memory management
  - Security features

### JavaScript Tests (Jest)
- Unit tests for JavaScript modules
- DOM manipulation testing
- Event handling tests
- Storage and utility function tests

## Quick Start

### PHP Testing

#### Option 1: Using Composer (Recommended for Development)

Install development dependencies including PHPUnit:

```bash
composer install
```

Set up the WordPress test environment:

```bash
composer run test:install
```

Run tests:

```bash
composer run test
```

### JavaScript Testing

Install Node.js dependencies:

```bash
npm install
```

Run JavaScript tests:

```bash
npm test
```

Run with coverage:

```bash
npm run test:coverage
```

## Running Tests

### PHP Tests

#### All PHP Tests

Run the complete PHP test suite:

```bash
composer run test
```

Or directly with PHPUnit:

```bash
vendor/bin/phpunit
```

#### Specific Test File

Run a specific test file:

```bash
vendor/bin/phpunit tests/test-assistant-tools.php
```

#### With Coverage

Generate code coverage report (requires Xdebug):

```bash
vendor/bin/phpunit --coverage-html coverage/
```

### JavaScript Tests

#### All JavaScript Tests

Run the complete JavaScript test suite:

```bash
npm test
```

#### Watch Mode

Run tests in watch mode (automatically re-runs on file changes):

```bash
npm run test:watch
```

#### With Coverage

Generate JavaScript coverage report:

```bash
npm run test:coverage
```

View the HTML coverage report:

```bash
open coverage/lcov-report/index.html
```

#### Specific Test File

Run a specific test file:

```bash
npm test -- storage-util.test.js
```

#### Verbose Output

Run tests with detailed output:

```bash
npm run test:verbose
```

## Available Test Commands

### PHP Testing Commands

```bash
# Install WordPress test environment
composer run test:install

# Run all PHP tests
composer run test

# Run PHP linting
composer run lint

# Run PHP compatibility check
composer run lint:compat

# Auto-fix code style issues
composer run format
```

### JavaScript Testing Commands

```bash
# Run all JavaScript tests
npm test

# Run tests in watch mode
npm run test:watch

# Run tests with coverage
npm run test:coverage

# Run tests with verbose output
npm run test:verbose

# Run JavaScript linting
npm run lint:js

# Auto-fix JavaScript linting issues
npm run lint:js:fix
```

## Test Environment Setup

### Prerequisites

- PHP 7.4 or higher
- MySQL or MariaDB
- WordPress 6.0 or higher (installed automatically by test:install)

### Initial Setup

The first time you run tests, install the WordPress test environment:

```bash
composer run test:install
```

This script:
1. Downloads WordPress core
2. Creates a test database
3. Installs WordPress test suite
4. Configures PHPUnit

### Database Configuration

Default test database settings:
- Database: `wordpress_test`
- User: `root`
- Password: (empty)
- Host: `localhost`

To customize, edit `bin/install-wp-tests.sh` or provide environment variables.

## Test Organization

### PHP Tests

```
tests/
├── test-*.php              # Component-specific tests
├── rest/                   # REST API endpoint tests
├── rest-api/              # REST API integration tests
├── helpers/               # Helper function tests
├── memory/                # Memory and caching tests
├── crawler/               # Crawl4AI integration tests
└── js/                    # JavaScript tests (see below)
```

### JavaScript Tests

```
tests/js/
├── setup.js               # Jest setup and global mocks
├── *.test.js              # Test files (one per module/feature)
└── README.md              # JavaScript testing guide
```

See [tests/js/README.md](tests/js/README.md) for detailed JavaScript testing documentation.

## Writing Tests

### Writing PHP Tests

#### Basic Test Structure

```php
<?php
class Test_Feature extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        // Test setup
    }
    
    public function test_feature_works() {
        // Arrange
        $input = 'test data';
        
        // Act
        $result = wp_mcp_ai_function( $input );
        
        // Assert
        $this->assertEquals( 'expected', $result );
    }
}
```

#### Test Naming Conventions

**PHP Tests:**
- Test classes: `Test_Feature_Name`
- Test methods: `test_what_it_does`
- File names: `test-feature-name.php`

**JavaScript Tests:**
- Test files: `module-name.test.js`
- Test suites: `describe( 'Module', () => {} )`
- Test cases: `it( 'should do something', () => {} )`

### Writing JavaScript Tests

See [tests/js/README.md](tests/js/README.md) for comprehensive JavaScript testing guide, including:
- Test structure and patterns
- Available mocks (WordPress, jQuery, browser APIs)
- Testing async code, DOM, events, and timers
- Debugging and best practices

Quick example:

```javascript
describe( 'MyModule', () => {
    it( 'should perform expected behavior', () => {
        // Arrange
        const input = 'test';
        
        // Act
        const result = myFunction( input );
        
        // Assert
        expect( result ).toBe( 'expected' );
    } );
} );
```

## Continuous Integration

The plugin uses GitHub Actions to run tests automatically:

### PHP Tests
- On every push to main branch
- On every pull request
- Tests against PHP 8.1 and MySQL 8.0
- See `.github/workflows/phpunit.yml`

### JavaScript Tests
- On every push to main branch
- On every pull request  
- Tests against Node.js 18.x and 20.x
- See `.github/workflows/javascript-tests.yml`

## Troubleshooting

### Tests Fail on Fresh Install

Make sure the WordPress test environment is installed:

```bash
composer run test:install
```

### Database Connection Errors

Verify MySQL is running and credentials are correct:

```bash
mysql -u root -p -e "SHOW DATABASES;"
```

### Missing PHPUnit

If you see "command not found: phpunit", install dependencies:

```bash
composer install
```

Or use the pre-packaged vendor-dev.zip:

```bash
./bin/install-vendor-dev.sh
```

### Permission Errors

Ensure bin scripts are executable:

```bash
chmod +x bin/*.sh
```

## Package Size Reference

- **Production vendor**: ~5.6 MB (committed to repo)
  - tiktoken-php
  - symfony/http-client
  - nyholm/psr7
  - psr/* packages

- **Development vendor-dev.zip**: ~140 MB (optional download)
  - PHPUnit framework
  - PHP_CodeSniffer
  - WordPress Coding Standards
  - WordPress stubs
  - PHPUnit polyfills
  - Sebastian testing tools

## Best Practices

1. **Always run tests** before committing code changes
2. **Write tests** for new features and bug fixes
3. **Maintain coverage** - aim for high test coverage
4. **Use meaningful assertions** - test behavior, not implementation
5. **Keep tests fast** - avoid unnecessary setup
6. **Isolate tests** - each test should be independent

## Related Documentation

- [BUILD.md](../../../BUILD.md) - Asset build process
- [CONTRIBUTING.md](../../../CONTRIBUTING.md) - Contribution guidelines
- [docs/CODE_REVIEW.md](../code-reviews/CODE_REVIEW.md) - Code quality standards
- [docs/BEST_PRACTICES.md](../../guides/developer/best-practices/BEST_PRACTICES.md) - Development best practices

## Support

For testing-related issues:
1. Check this guide and troubleshooting section
2. Review existing tests for examples
3. Check the GitHub Actions logs for CI test results
4. Open an issue if you encounter problems

## License

Same as main plugin: GPLv3 or later
