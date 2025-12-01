# WordPress Test Environment - Setup Guide

## Quick Start

### Prerequisites
- PHP 7.4 or higher
- MySQL 8.0 or higher
- Composer

### Setup Steps

1. **Install Dependencies**
   ```bash
   composer install
   ```

2. **Start MySQL**
   ```bash
   sudo service mysql start
   ```

3. **Create Test Database**
   ```bash
   sudo mysql -u debian-sys-maint -p[PASSWORD] -e "CREATE DATABASE IF NOT EXISTS wordpress_test; GRANT ALL PRIVILEGES ON wordpress_test.* TO 'root'@'localhost'; FLUSH PRIVILEGES;"
   ```

4. **Download WordPress Core** (if not already present)
   ```bash
   # WordPress 6.7.1 will be downloaded to /tmp/wordpress-core/wordpress
   # This is done automatically by the test bootstrap
   ```

5. **Run Tests**
   ```bash
   ./bin/run-tests.sh --no-coverage
   ```

## Test Runner Scripts

### `/bin/run-tests.sh`
Simplified test runner with MySQL and WordPress core setup.

```bash
# Run all tests
./bin/run-tests.sh --no-coverage

# Run specific test file
./bin/run-tests.sh tests/test-sample.php --no-coverage

# Run with filter
./bin/run-tests.sh --filter TestClassName --no-coverage

# Stop on first failure
./bin/run-tests.sh --stop-on-failure --no-coverage
```

### `/bin/analyze-tests.sh`
Comprehensive test analysis and reporting tool.

```bash
# Run full analysis
./bin/analyze-tests.sh
```

## Configuration

### Test Database Configuration
File: `tests/wp-tests-config.php`

```php
define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
```

### WordPress Core Location
Set via environment variable:
```bash
export WP_CORE_DIR="/tmp/wordpress-core/wordpress"
```

## Test Structure

```
tests/
├── bootstrap.php              # Test environment setup
├── wp-tests-config.php        # Database configuration
├── test-*.php                 # Individual test files
├── helpers/                   # Test helper functions
├── rest/                      # REST API tests
├── rest-api/                  # REST API integration tests
├── memory/                    # Memory and caching tests
├── performance/               # Performance tests
├── security/                  # Security tests
└── crawler/                   # Crawl4AI tests
```

## Common Issues and Solutions

### Issue: "WordPress core not found"
**Solution**: Set the `WP_CORE_DIR` environment variable or let the test bootstrap download it automatically.

### Issue: "MySQL connection failed"
**Solution**:
```bash
# Check MySQL status
sudo service mysql status

# Start MySQL
sudo service mysql start

# Verify connection
mysql -u root -e "SHOW DATABASES;"
```

### Issue: "Test database not found"
**Solution**:
```bash
# Create the database
sudo mysql -e "CREATE DATABASE wordpress_test;"
```

### Issue: "Permission denied on test scripts"
**Solution**:
```bash
chmod +x bin/*.sh
```

## Continuous Integration

The repository uses GitHub Actions for automated testing. See `.github/workflows/phpunit.yml` for configuration.

### Local CI Simulation
```bash
# Run the same tests as CI
WP_DB_NAME=wordpress_test \
WP_DB_USER=root \
WP_DB_PASSWORD= \
WP_DB_HOST=localhost \
vendor/bin/phpunit --no-coverage
```

## Code Quality

### Linting
```bash
# Run PHP linting
composer run lint

# Auto-fix code style issues
composer run format

# Check PHP compatibility
composer run lint:compat
```

### Code Coverage (Requires Xdebug)
```bash
vendor/bin/phpunit --coverage-html coverage/
```

## Performance Testing

Performance tests are located in `tests/performance/`.

```bash
# Run performance tests only
./bin/run-tests.sh tests/performance/ --no-coverage

# Run specific performance test
./bin/run-tests.sh --filter Stress --no-coverage
```

## Debugging Tests

### Enable Debug Mode
```bash
# Run with debug output
./bin/run-tests.sh --debug --no-coverage
```

### View Test Logs
```bash
# Check recent test output
tail -f /tmp/test-full-output.log
```

### Run Single Test Method
```bash
./bin/run-tests.sh --filter test_specific_method_name --no-coverage
```

## Best Practices

1. **Run tests before committing**
   ```bash
   ./bin/run-tests.sh --no-coverage
   ```

2. **Fix code style automatically**
   ```bash
   composer run format
   ```

3. **Focus on failures**
   ```bash
   ./bin/run-tests.sh --stop-on-failure --no-coverage
   ```

4. **Test specific components**
   ```bash
   ./bin/run-tests.sh tests/rest/ --no-coverage
   ```

5. **Maintain separation of concerns** - Follow existing architectural patterns

## Additional Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress Plugin Handbook - Testing](https://developer.wordpress.org/plugins/testing/)
- [WP-CLI Testing Guide](https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/)

## Support

For issues with the test environment:
1. Check this guide
2. Review `BUG_REPORT.md` for known issues
3. Check GitHub Issues
4. Consult `TESTING.md` for detailed testing documentation
