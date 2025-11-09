# Base Functionality Testing - Quick Reference

## One-Line Commands

```bash
# Run all base functionality tests
./bin/run-base-tests.sh

# Run with details
./bin/run-base-tests.sh --verbose

# Generate coverage
./bin/run-base-tests.sh --coverage

# Run single test
./bin/run-base-tests.sh --filter test_plugin_constants_defined
```

## What Gets Tested

### Automated (40 Tests)
- ✅ Plugin initialization
- ✅ Constants and version
- ✅ Assistant CPT
- ✅ Tool registry (35+ base tools)
- ✅ REST API endpoints
- ✅ Core classes
- ✅ Security components
- ✅ AI provider clients
- ✅ Base version exclusions

### Manual (20+ Scenarios)
See: [`docs/BASE-FUNCTIONALITY-TEST-PLAN.md`](BASE-FUNCTIONALITY-TEST-PLAN.md)

## Quick Validation

### Check if base version works
```bash
# Run just the comprehensive test
vendor/bin/phpunit tests/test-base-functionality-comprehensive.php
```

### Check specific feature
```bash
# Test tool registry
vendor/bin/phpunit tests/test-tool-registry.php

# Test REST API
vendor/bin/phpunit --testsuite rest-api

# Test base version mode
vendor/bin/phpunit tests/test-base-version.php
```

## Expected Results

✅ **40 tests pass** in comprehensive suite  
✅ **No PHP errors** or warnings  
✅ **All assertions succeed**  
✅ **Exit code 0**

## Troubleshooting

### Test Environment Not Set Up
```bash
composer run test:install
```

### Dependencies Missing
```bash
composer install
```

### WordPress Core Not Found
```bash
# Use vendor wp-phpunit (already installed)
# Or install WordPress test lib:
bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

## Files

| File | Purpose |
|------|---------|
| `tests/test-base-functionality-comprehensive.php` | 40 automated tests |
| `bin/run-base-tests.sh` | Test runner script |
| `docs/BASE-FUNCTIONALITY-TEST-PLAN.md` | Manual test scenarios |
| `docs/BASE-FUNCTIONALITY-TESTING-SUMMARY.md` | Detailed documentation |

## CI/CD Integration

```yaml
# GitHub Actions example
- name: Base Functionality Tests
  run: ./bin/run-base-tests.sh
```

---

**For full documentation**: See [`docs/BASE-FUNCTIONALITY-TESTING-SUMMARY.md`](BASE-FUNCTIONALITY-TESTING-SUMMARY.md)
