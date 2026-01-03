# Code Coverage Dashboard

**Status:** ✅ Active  
**Coverage Target:** 70%+  
**Current Coverage:** View on [Codecov](https://codecov.io/gh/nvdigitalsolutions/mcp-ai-wpoos)

---

## Overview

The NV oOS plugin uses comprehensive code coverage tracking to ensure quality and identify areas needing additional tests. Coverage is automatically generated and reported on every commit and pull request.

## Quick Links

- **Codecov Dashboard:** https://codecov.io/gh/nvdigitalsolutions/mcp-ai-wpoos
- **Coverage Badge:** ![codecov](https://codecov.io/gh/nvdigitalsolutions/mcp-ai-wpoos/branch/main/graph/badge.svg)
- **CI Workflow:** [.github/workflows/phpunit.yml](../../.github/workflows/phpunit.yml)

---

## Coverage Components

### Core Components

| Component | Target | Description |
|-----------|--------|-------------|
| **Core Plugin** | 70%+ | Main plugin files and initialization |
| **AI Tools** | 70%+ | All 86+ tool implementations |
| **Admin Interface** | 70%+ | Admin settings and UI |
| **REST API** | 80%+ | API endpoints and authentication |
| **Integrations** | 60%+ | Third-party plugin integrations |

### Coverage by File Type

- **PHP Files:** Covered by PHPUnit with Xdebug
- **JavaScript Files:** Covered by Jest (see `coverage/lcov.info`)

---

## Viewing Coverage Reports

### Online (Codecov)

View interactive coverage reports at: https://codecov.io/gh/nvdigitalsolutions/mcp-ai-wpoos

Features:
- Line-by-line coverage visualization
- Coverage trends over time
- Pull request coverage impact
- Component-based analysis

### Local HTML Dashboard

Generate and view a local HTML coverage dashboard:

```bash
# Generate coverage dashboard
composer run test:coverage-dashboard

# View in browser (macOS)
open coverage/html/index.html

# View in browser (Linux)
xdg-open coverage/html/index.html

# View in browser (Windows)
start coverage/html/index.html
```

The HTML dashboard includes:
- Overall coverage percentage
- File-by-file coverage breakdown
- Line-by-line coverage visualization
- Color-coded coverage levels (green/yellow/red)

### Command Line

Quick coverage summary:

```bash
# Run tests with text coverage output
composer run test:coverage

# Or just run PHPUnit directly
vendor/bin/phpunit --coverage-text
```

---

## CI/CD Integration

### Automatic Coverage Generation

Coverage is automatically generated in these scenarios:

1. **Every push to main branch**
   - Generates coverage report
   - Uploads to Codecov
   - Updates badge in README

2. **Every pull request**
   - Generates coverage diff
   - Comments on PR with coverage change
   - Blocks PR if coverage drops significantly (threshold: -5%)

3. **Manual workflow trigger**
   - Can be triggered from GitHub Actions UI

### Coverage Workflow

The coverage workflow (`.github/workflows/phpunit.yml`):

1. Sets up PHP 8.1 with Xdebug
2. Installs WordPress test environment
3. Runs PHPUnit with `--coverage-clover coverage.xml`
4. Checks coverage threshold (70% minimum)
5. Uploads to Codecov
6. Displays coverage summary in workflow log

---

## Coverage Configuration

### Codecov Configuration

Configuration file: `.codecov.yml`

Key settings:
- **Target:** 70% minimum coverage
- **Threshold:** 5% allowed decrease
- **Ignored Paths:** tests/, vendor/, node_modules/
- **Components:** Core, Tools, Admin, REST API, Integrations

### PHPUnit Configuration

Configuration file: `phpunit.xml.dist`

Coverage settings:
```xml
<coverage>
  <include>
    <directory suffix=".php">includes</directory>
    <file>mcp-ai-wpoos.php</file>
  </include>
  <exclude>
    <directory>tests</directory>
    <directory>vendor</directory>
    <directory>node_modules</directory>
  </exclude>
</coverage>
```

---

## Improving Coverage

### Identifying Gaps

1. **View Codecov Dashboard**
   - See uncovered files and lines
   - Prioritize by importance

2. **Local HTML Report**
   - Generate with `composer run test:coverage-dashboard`
   - Browse to `coverage/html/index.html`
   - Red = uncovered, yellow = partially covered, green = fully covered

3. **Focus Areas**
   - Core plugin files (high priority)
   - REST API endpoints (high priority)
   - AI tools (medium priority)
   - Admin UI (lower priority for automated tests)

### Adding Tests

1. **Create test file** in `tests/` directory:
   ```php
   <?php
   class Test_My_Feature extends WP_UnitTestCase {
       public function test_feature_works() {
           // Test code
       }
   }
   ```

2. **Run tests** to verify coverage increase:
   ```bash
   composer run test:coverage
   ```

3. **View results** in HTML report or Codecov

### Best Practices

- **Test critical paths first** - Focus on code that handles user data, security, or core functionality
- **Add edge case tests** - Test error conditions, boundary values, and unusual inputs
- **Mock external dependencies** - Use WordPress test doubles for API calls
- **Keep tests fast** - Use unit tests over integration tests when possible

---

## Coverage Metrics

### Current Status

View current metrics at: https://codecov.io/gh/nvdigitalsolutions/mcp-ai-wpoos

### Historical Trends

Codecov tracks coverage over time:
- Coverage changes per commit
- Coverage trends (weekly/monthly)
- Top uncovered files
- Coverage by component

### Quality Gates

| Gate | Threshold | Action |
|------|-----------|--------|
| **Project Coverage** | 70% | Warning if below |
| **Patch Coverage** | 70% | Warning on new code |
| **Coverage Decrease** | -5% | Blocks PR merge |

---

## Troubleshooting

### Coverage Not Generated

**Problem:** `coverage.xml` not created

**Solutions:**
1. Ensure Xdebug is installed: `php -m | grep xdebug`
2. Check PHPUnit config: `phpunit.xml.dist`
3. Verify tests run: `composer run test`

### Coverage Appears Low

**Problem:** Coverage much lower than expected

**Solutions:**
1. Check which files are excluded in `.codecov.yml`
2. Verify tests are actually running: check PHPUnit output
3. Ensure test files are in `tests/` directory
4. Check for `@covers` annotations in tests

### Codecov Upload Fails

**Problem:** Coverage not appearing on Codecov

**Solutions:**
1. Check GitHub Actions secrets for `CODECOV_TOKEN`
2. Verify `.codecov.yml` syntax
3. Check Codecov action version in workflow
4. Review workflow logs for upload errors

---

## Scripts Reference

```bash
# Run tests only
composer run test

# Run tests with HTML coverage
composer run test:coverage

# Run tests with full dashboard
composer run test:coverage-dashboard

# Run tests with text output
vendor/bin/phpunit --coverage-text

# Run specific test file
vendor/bin/phpunit tests/test-specific-file.php

# Run with coverage for specific file
vendor/bin/phpunit --coverage-filter 'includes/class-file.php' tests/test-file.php
```

---

## Resources

- **Codecov Documentation:** https://docs.codecov.io/
- **PHPUnit Documentation:** https://phpunit.de/documentation.html
- **Xdebug Documentation:** https://xdebug.org/docs/code_coverage
- **WordPress Testing:** https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/

---

**Last Updated:** January 3, 2026  
**Maintained By:** NV Digital Solutions  
**Questions?** See [CONTRIBUTING.md](../../CONTRIBUTING.md)
