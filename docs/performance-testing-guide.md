# Performance Testing Guide

## Overview

The WP oOS Performance Testing Suite provides comprehensive testing capabilities to measure, analyze, and optimize plugin performance. This guide covers all aspects of performance testing, from running basic tests to interpreting complex results.

## Table of Contents

1. [Getting Started](#getting-started)
2. [Test Suites](#test-suites)
3. [Running Tests](#running-tests)
4. [Understanding Results](#understanding-results)
5. [Optimization Strategies](#optimization-strategies)
6. [CI/CD Integration](#cicd-integration)
7. [Troubleshooting](#troubleshooting)

## Getting Started

### Prerequisites

- WordPress 6.0 or higher
- PHP 7.4 or higher
- Composer installed
- PHPUnit configured (run `composer install`)
- Optional: JetEngine for CCT storage

### Installation

```bash
# Install dependencies
composer install

# Set up WordPress test environment (first time only)
composer run test:install

# Verify installation
./bin/run-performance-tests.sh --help
```

## Test Suites

### 1. Stress Tests

**Location:** `tests/performance/test-stress-suite.php`

Tests plugin behavior under heavy load conditions:

- **Concurrent API Requests** - Simulates 50+ simultaneous REST API calls
- **Multiple Chat Sessions** - Tests handling of 10+ concurrent chat sessions
- **Database Query Performance** - Executes 100+ database queries under load
- **CPT Bulk Operations** - Creates/queries 50+ custom posts at once
- **Tool Execution Concurrency** - Tests concurrent tool invocations

**When to Run:**
- Before major releases
- After performance optimizations
- When adding new REST endpoints
- During capacity planning

**Expected Baseline:**
- Average response time: < 500ms
- Success rate: > 95%
- Memory usage: < 128MB

### 2. Security Tests

**Location:** `tests/security/test-security-suite.php`

Validates security measures across the plugin:

- **SQL Injection Protection** - Tests 5 common SQL injection patterns
- **XSS Vulnerability Scanning** - Validates output escaping in chat UI
- **CSRF Token Enforcement** - Ensures all state-changing endpoints require valid nonces
- **File Upload Security** - Tests malicious file rejection and path traversal protection
- **Authentication Bypass** - Attempts to access protected endpoints without credentials
- **Rate Limiting** - Validates rate limiting enforcement (if enabled)
- **Permission Escalation** - Tests capability checking for privileged operations
- **Credential Leakage** - Ensures API keys/secrets are not exposed in responses

**When to Run:**
- Before every release
- After security-related changes
- As part of CI/CD pipeline
- Monthly security audits

**Expected Baseline:**
- Zero vulnerabilities found
- All security tests passing
- No exposed credentials

### 3. Speed Benchmarks

**Location:** `tests/performance/test-speed-benchmarks.php`

Establishes performance baselines:

- **API Endpoint Latency** - Measures p50, p95, p99 percentiles (100 iterations)
- **Chat UI Rendering** - Times shortcode rendering
- **Memory Leak Detection** - Monitors memory growth over 50 iterations
- **Database Query Tracking** - Counts queries per request
- **Response Time Regression** - Compares against historical baselines
- **Tool Execution Performance** - Benchmarks tool invocation speed

**When to Run:**
- Weekly (to establish trends)
- After performance changes
- Before/after optimization work
- When investigating performance issues

**Expected Baseline:**
- P50 latency: < 500ms
- P95 latency: < 1000ms
- P99 latency: < 2000ms
- Memory growth: < 50%
- DB queries: < 50 per request

### 4. Optimization Comparison

**Location:** `tests/performance/test-optimization-comparison.php`

A/B tests optimization features:

- **Cache Effectiveness** - Compares performance with/without caching
- **Message Bundling** - Tests bundled vs. individual message processing
- **localStorage Impact** - Measures server-side serialization performance
- **DOM Rendering** - Compares optimized vs. unoptimized rendering

**When to Run:**
- Before enabling optimizations in production
- To validate optimization benefits
- During performance tuning
- Quarterly performance reviews

**Expected Improvement:**
- Cache enabled: 20-50% faster
- Message bundling: 30-60% faster
- Rendering optimizations: 10-30% faster

### 5. Elementor Performance

**Location:** `tests/performance/test-elementor-performance.php`

Tests Elementor widget integration:

- **Widget Registration** - Times widget registration process
- **Widget Rendering** - Benchmarks rendering for all 6 performance widgets
- **AJAX Handler Load** - Tests AJAX endpoint performance
- **Multi-Instance Stress** - Renders 10+ widget instances simultaneously
- **Data Retrieval** - Tests performance data fetching
- **Widget Caching** - Validates caching effectiveness

**When to Run:**
- After widget changes
- Before Elementor updates
- When adding new widgets
- During widget development

**Expected Baseline:**
- Registration: < 1000ms
- Average rendering: < 500ms per widget
- Multi-instance: < 2000ms for 10 widgets
- AJAX handlers: 100% success rate

## Running Tests

### Command Line Interface

```bash
# Run all test suites
./bin/run-performance-tests.sh --full

# Run specific suite
./bin/run-performance-tests.sh --suite=stress
./bin/run-performance-tests.sh --suite=security
./bin/run-performance-tests.sh --suite=speed
./bin/run-performance-tests.sh --suite=optimization
./bin/run-performance-tests.sh --suite=elementor

# Generate performance report
./bin/run-performance-tests.sh --report

# Get help
./bin/run-performance-tests.sh --help
```

### PHPUnit Direct Execution

```bash
# Run specific test file
vendor/bin/phpunit tests/performance/test-stress-suite.php

# Run with verbose output
vendor/bin/phpunit --verbose tests/performance/test-stress-suite.php

# Run specific test method
vendor/bin/phpunit --filter test_concurrent_api_requests tests/performance/test-stress-suite.php
```

### WordPress Admin Interface

**NEW: Test Execution in Admin UI** (as of this update)

1. Navigate to **Settings → WP oOS → Advanced → Performance Monitoring**
2. Scroll to the "Run Performance Tests" section
3. Click any test button:
   - **Run Stress Test** - Executes concurrent load testing
   - **Run Security Test** - Runs security vulnerability scans  
   - **Run Speed Benchmark** - Measures latency percentiles
   - **Run Optimization Test** - Compares optimization effectiveness

**Test Execution Behavior:**

- **Environment Ready**: Tests execute programmatically and display results inline
- **Setup Required**: Shows installation instructions and CLI fallback commands
- **Results Display**: 
  - Success: Shows checkmark with test summary and expandable detailed output
  - Failure: Shows error with helpful next steps and CLI alternatives
  - Timeout: 65-second timeout with clear error messaging

**Features:**
- Real-time test execution (no page refresh needed)
- Inline results with syntax-highlighted output
- Fallback to CLI commands when WordPress test environment unavailable
- Export test history as JSON or CSV
- View component performance metrics
- Historical trend analysis

**Example Output:**
```
✓ Stress tests completed successfully. Passed: 5 tests, 12 assertions

[View Detailed Output] (expandable section with full PHPUnit output)
```

**Fallback Example (when environment not ready):**
```
✗ WordPress test environment not configured. Use CLI command below or run setup first.

Setup Required:
composer run test:install

Run via CLI:
./bin/run-performance-tests.sh --suite=stress
```

### Programmatic Execution

```php
// Store custom test results
WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
    'stress',           // test_type
    'rest_api',        // component
    true,              // optimizations_enabled
    array(             // metrics
        'concurrent_requests' => 100,
        'avg_response_time' => 245.3,
        'max_response_time' => 890.1,
        'memory_peak_mb' => 64.2,
        'db_queries' => 45
    ),
    array(             // test_results
        'passed' => 98,
        'failed' => 2,
        'total' => 100
    )
);

// Retrieve performance trends
$trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends(
    'rest_api',
    '-7 days'
);
```

## Understanding Results

### Test Result Status

- **Passed** ✓ - All tests passed, performance within acceptable limits
- **Warning** ⚠ - Tests passed but performance degraded or near limits
- **Failed** ✗ - Tests failed or critical performance issues detected

### Performance Metrics

| Metric | Description | Good | Warning | Critical |
|--------|-------------|------|---------|----------|
| Response Time | Average time to process request | < 500ms | 500-1000ms | > 1000ms |
| Memory Usage | Peak memory consumption | < 128MB | 128-256MB | > 256MB |
| DB Queries | Number of database queries | < 30 | 30-50 | > 50 |
| Success Rate | Percentage of successful operations | > 99% | 95-99% | < 95% |

### Trend Analysis

The Performance Monitor CCT automatically analyzes trends:

- **Improving** ↗️ - Performance is getting better over time (20%+ improvement)
- **Stable** → - Performance is consistent with no significant changes
- **Degrading** ↘️ - Performance is declining (20%+ regression)
- **No Data** — - Insufficient historical data for trend analysis

### Diagnostic Summaries

Each test generates a human-readable summary:

```
Test: Stress test on rest_api component
Avg Response Time: 245.30 ms | Peak Memory: 64.20 MB | DB Queries: 45
Handled 100 concurrent requests | Results: 98 passed, 2 failed
```

### AI-Generated Recommendations

The system automatically generates actionable recommendations:

```json
{
    "severity": "high",
    "issue": "Slow response times detected",
    "action": "Enable caching and optimize database queries"
}
```

## Optimization Strategies

### 1. Enable Caching

**When:** Response times > 500ms consistently

```php
// In wp-config.php or via settings
define('WP_MCP_AI_ENABLE_CACHE', true);
```

**Expected Impact:** 20-50% faster response times

### 2. Optimize Database Queries

**When:** DB queries > 30 per request

- Use `WP_Query` with proper caching
- Implement object caching
- Add database indexes
- Use query result caching

**Expected Impact:** 30-60% reduction in query count

### 3. Enable Message Bundling

**When:** Multiple chat messages sent rapidly

```php
add_filter('wp_mcp_ai_enable_bundling', '__return_true');
```

**Expected Impact:** 30-60% faster chat processing

### 4. Optimize Rendering

**When:** Chat UI takes > 1000ms to render

```php
add_filter('wp_mcp_ai_optimize_rendering', '__return_true');
```

**Expected Impact:** 10-30% faster rendering

### 5. Implement Rate Limiting

**When:** Security tests show vulnerability to abuse

```php
// Enable rate limiting in settings
update_option('wp_mcp_ai_rate_limit_enabled', true);
update_option('wp_mcp_ai_rate_limit_requests', 100);
update_option('wp_mcp_ai_rate_limit_window', 60);
```

**Expected Impact:** Protection against DOS attacks

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Performance Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main ]
  schedule:
    - cron: '0 2 * * 0' # Weekly on Sunday at 2am

jobs:
  performance-tests:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.1'
        
    - name: Install dependencies
      run: composer install
      
    - name: Setup WordPress test environment
      run: composer run test:install
      
    - name: Run performance tests
      run: ./bin/run-performance-tests.sh --full
      
    - name: Upload results
      uses: actions/upload-artifact@v3
      with:
        name: performance-results
        path: test-results/
```

### Automated Alerts

Configure alerts for performance degradation:

```php
// Check performance after tests
$trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends('rest_api', '-1 day');

if ($trends['trend'] === 'degrading') {
    // Send alert email
    wp_mail(
        'admin@example.com',
        'Performance Alert: Degradation Detected',
        'REST API performance is degrading. Check performance reports.'
    );
}
```

## Troubleshooting

### Tests Failing

**Problem:** Tests fail with timeout errors

**Solution:**
```bash
# Increase timeout in phpunit.xml.dist
<phpunit processIsolationTimeout="300">

# Or run with increased memory
php -d memory_limit=512M vendor/bin/phpunit
```

### No Test Results Stored

**Problem:** Tests run but results don't appear in admin

**Solution:**
1. Check if Performance Monitor CCT class is loaded
2. Verify JetEngine is active (or fallback to options)
3. Check PHP error logs for database errors

### Inconsistent Results

**Problem:** Performance metrics vary significantly between runs

**Solution:**
- Run tests multiple times and average results
- Ensure no other processes are running during tests
- Use dedicated test environment
- Clear caches before testing

### Memory Errors

**Problem:** Tests fail with "Allowed memory size exhausted"

**Solution:**
```php
// Increase memory limit in wp-config.php
define('WP_MEMORY_LIMIT', '512M');
define('WP_MAX_MEMORY_LIMIT', '512M');
```

## Best Practices

1. **Run tests regularly** - Establish baseline performance metrics
2. **Test before and after** - Always test before/after optimization changes
3. **Monitor trends** - Watch for gradual performance degradation
4. **Document baselines** - Keep records of acceptable performance levels
5. **Test in production-like environment** - Use similar hardware and configuration
6. **Isolate variables** - Test one optimization at a time
7. **Review security tests** - Run security suite before every release
8. **Export results** - Keep historical data for comparison
9. **Set up alerts** - Configure automated alerts for critical issues
10. **Optimize iteratively** - Make small changes and measure impact

## Advanced Topics

### Custom Test Development

Create custom performance tests:

```php
class My_Custom_Performance_Test extends WP_UnitTestCase {
    public function test_my_feature() {
        $start = microtime(true);
        
        // Your code to test
        my_custom_function();
        
        $elapsed = (microtime(true) - $start) * 1000;
        
        WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
            'custom',
            'my_component',
            false,
            array('avg_response_time' => $elapsed),
            array('passed' => 1)
        );
        
        $this->assertLessThan(500, $elapsed);
    }
}
```

### Performance Profiling

For detailed profiling, use Xdebug:

```bash
# Install Xdebug
pecl install xdebug

# Run tests with profiling
XDEBUG_MODE=profile vendor/bin/phpunit tests/performance/

# Analyze with webgrind or cachegrind
```

### Load Testing

For realistic load testing:

```bash
# Use Apache Bench
ab -n 1000 -c 100 http://example.com/wp-json/mcp-ai/v1/assistants

# Use wrk
wrk -t4 -c100 -d30s http://example.com/wp-json/mcp-ai/v1/assistants
```

## Resources

- [WordPress Performance Best Practices](https://developer.wordpress.org/advanced-administration/performance/)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Performance Monitoring Guide](performance-monitoring.md)
- [WP oOS Documentation Index](DOCUMENTATION_INDEX.md)

## Support

For issues or questions:

- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: See `docs/` directory
- Security Issues: See SECURITY.md
