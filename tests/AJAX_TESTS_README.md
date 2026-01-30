# AJAX Endpoint Test Suites - Quick Reference

## Overview

Four comprehensive test suites covering 28 AJAX endpoints with 46 test methods, focusing on async operations and multi-agent workflows.

## Test Files

### 1. test-async-ajax-data-seeding.php
**Purpose:** Tests async data seeding operations  
**Methods:** 11 tests  
**Key Endpoints:**
- Team reseeding (update/replace modes)
- Profession seeding with dependencies
- Playbook generation and synchronization
- Task template and orchestration seeding

**Run:** `vendor/bin/phpunit tests/test-async-ajax-data-seeding.php`

---

### 2. test-multi-agent-ajax-orchestration.php
**Purpose:** Tests multi-agent coordination and bulk operations  
**Methods:** 10 tests  
**Key Endpoints:**
- Bulk tier assignment (multi-user)
- Batch tool configuration
- Token usage management
- Concurrent operations without conflicts

**Run:** `vendor/bin/phpunit tests/test-multi-agent-ajax-orchestration.php`

---

### 3. test-async-ajax-provider-testing.php
**Purpose:** Tests provider connection and model fetching  
**Methods:** 14 tests  
**Key Endpoints:**
- Ollama, LM Studio connection testing
- Cloudflare, Brave Search, Mubert APIs
- Flowhub, ISAMS integration
- Timeout and error handling

**Run:** `vendor/bin/phpunit tests/test-async-ajax-provider-testing.php`

---

### 4. test-model-manager-ajax-handlers.php
**Purpose:** Tests model discovery and configuration  
**Methods:** 11 tests  
**Key Endpoints:**
- Model discovery with tool integration
- Model research with web search
- Configuration management
- Concurrent operations

**Run:** `vendor/bin/phpunit tests/test-model-manager-ajax-handlers.php`

---

## Quick Start

```bash
# Install dependencies
composer install

# Set up WordPress test environment (one-time)
composer run test:install

# Run all tests
composer run test

# Run specific suite
vendor/bin/phpunit tests/test-async-ajax-data-seeding.php
```

## Test Coverage

| Category | Tests | Endpoints |
|----------|-------|-----------|
| Async Data Seeding | 11 | 6 |
| Multi-Agent Orchestration | 10 | 8 |
| Provider Testing | 14 | 11 |
| Model Manager | 11 | 3 |
| **Total** | **46** | **28** |

## Key Features Tested

✅ **Async Operations** - Job creation, status tracking, cron integration  
✅ **Multi-Agent Workflows** - Parallel execution, dependency management  
✅ **Security** - Permissions, nonce verification, capability checks  
✅ **Error Handling** - Timeouts, missing credentials, invalid inputs  
✅ **Batch Operations** - Bulk updates, concurrent operations  
✅ **Integration** - Tool registry, web search, external APIs

## Test Pattern Example

```php
class Test_Example extends WP_Ajax_UnitTestCase {
    
    public function test_endpoint_success() {
        // Setup admin user
        $admin_id = $this->factory->user->create( 
            array( 'role' => 'administrator' ) 
        );
        wp_set_current_user( $admin_id );

        // Setup AJAX request
        $_POST['action'] = 'wp_mcp_ai_example';
        $_POST['param']  = 'value';
        $_POST['nonce']  = wp_create_nonce( 'key' );

        // Make request
        try {
            $this->_handleAjax( 'wp_mcp_ai_example' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected
        }

        // Validate response
        $response = json_decode( $this->_last_response, true );
        $this->assertTrue( $response['success'] );
    }
}
```

## Security Testing

Every endpoint includes tests for:
- ✅ Permission checks (`manage_options` capability)
- ✅ Nonce verification (valid/invalid nonces)
- ✅ Input validation (required fields, data types)
- ✅ Error handling (graceful failures)

## Documentation

- Full documentation: `docs/ajax-test-suites.md`
- REST API reference: `docs/rest-api.md`
- Testing guide: `tests/README.md`

## Contributing

When adding new AJAX endpoints:
1. Create test methods in appropriate file
2. Follow existing patterns (see examples above)
3. Include security tests
4. Test success and failure scenarios
5. Update documentation

## Troubleshooting

**Tests not running?**
```bash
# Check dependencies
composer install

# Setup WordPress test DB
composer run test:install
```

**Permission errors?**
- Ensure test user has `manage_options` capability
- Check nonce action names match

**External API failures?**
- Tests should work without external APIs
- Validate error handling instead of success
