# AJAX Test Suites Documentation

## Overview

This document describes the comprehensive test suites implemented for AJAX endpoints in the NV oOS WordPress plugin, with a focus on async operations and multi-agent workflows.

## Test Suite Structure

### 1. Async AJAX Data Seeding Tests
**File:** `tests/test-async-ajax-data-seeding.php`

Tests async data seeding operations that involve multi-agent workflows and long-running processes.

#### Endpoints Covered:
- `wp_mcp_ai_reseed_teams` - Multi-agent team creation with update/replace modes
- `wp_mcp_ai_reseed_professions` - Profession data seeding with dependencies
- `wp_mcp_ai_regenerate_playbook` - Async playbook generation
- `wp_mcp_ai_sync_all_playbooks` - Bulk playbook synchronization
- `wp_mcp_ai_seed_task_templates` - Task template initialization
- `wp_mcp_ai_seed_orchestration` - Orchestration system setup

#### Test Methods (11):
1. `test_reseed_teams_update_success()` - Verify team reseeding with update mode
2. `test_reseed_teams_fails_without_professions()` - Test dependency validation
3. `test_reseed_teams_replace_deletes_existing()` - Verify replace mode behavior
4. `test_reseed_teams_requires_permissions()` - Security check
5. `test_reseed_teams_requires_valid_nonce()` - Nonce verification
6. `test_reseed_teams_rejects_invalid_action_type()` - Input validation
7. `test_reseed_professions_success()` - Profession seeding
8. `test_regenerate_playbook_success()` - Async playbook generation
9. `test_sync_all_playbooks_success()` - Bulk sync operations
10. `test_seed_task_templates_success()` - Template seeding
11. `test_seed_orchestration_success()` - Orchestration initialization

#### Key Features:
- Tests both `update` and `replace` modes for data seeding
- Validates dependency requirements (e.g., professions before teams)
- Verifies proper cleanup of existing data in replace mode
- Tests security (permissions, nonces)
- Validates async job creation and status tracking

---

### 2. Multi-Agent AJAX Orchestration Tests
**File:** `tests/test-multi-agent-ajax-orchestration.php`

Tests AJAX endpoints that coordinate multi-agent workflows and bulk operations.

#### Endpoints Covered:
- `wp_mcp_ai_bulk_assign_tier` - Multi-user tier assignment
- `wp_mcp_ai_apply_all_recommendations` - Batch recommendation processing
- `wp_mcp_ai_apply_preset` - Settings preset application
- `wp_mcp_ai_save_tool_limits` - Bulk tool limit configuration
- `wp_mcp_ai_save_tool_settings` - Batch tool settings update
- `wp_mcp_ai_toggle_tool` - Tool enable/disable operations
- `wp_mcp_ai_reset_user_token_usage` - Single user token reset
- `wp_mcp_ai_reset_all_token_usage` - Bulk token reset

#### Test Methods (10):
1. `test_bulk_assign_tier_multiple_users()` - Multi-user batch operations
2. `test_bulk_assign_tier_fails_without_users()` - Empty input validation
3. `test_bulk_assign_tier_requires_permissions()` - Permission checks
4. `test_apply_all_recommendations_success()` - Batch processing
5. `test_apply_preset_success()` - Configuration preset application
6. `test_save_tool_limits_multiple_tools()` - Multi-tool configuration
7. `test_save_tool_settings_batch()` - Batch settings update
8. `test_toggle_tool_success()` - Tool state management
9. `test_concurrent_bulk_operations_no_conflict()` - Concurrency testing
10. `test_reset_user_token_usage()` - Token management
11. `test_reset_all_token_usage()` - Bulk token reset

#### Key Features:
- Tests parallel batch operations
- Validates no conflicts in concurrent operations
- Verifies proper state isolation between operations
- Tests multi-user and multi-tool scenarios
- Includes comprehensive security validation

---

### 3. Async AJAX Provider Testing Tests
**File:** `tests/test-async-ajax-provider-testing.php`

Tests async provider connection testing and model fetching operations.

#### Endpoints Covered:
- `wp_mcp_ai_test_ollama_connection` - Ollama connection validation
- `wp_mcp_ai_fetch_ollama_models` - Ollama model discovery
- `wp_mcp_ai_test_lm_studio_connection` - LM Studio testing
- `wp_mcp_ai_fetch_lm_studio_models` - LM Studio model listing
- `wp_mcp_ai_test_cloudflare_connection` - Cloudflare AI testing
- `wp_mcp_ai_test_brave_search_connection` - Brave Search API testing
- `wp_mcp_ai_test_mubert_connection` - Mubert API validation
- `wp_mcp_ai_test_flowhub_connection` - Flowhub integration testing
- `wp_mcp_ai_test_isams_connection` - ISAMS system testing
- `wp_mcp_ai_fetch_cloudways_data` - Cloudways data fetching
- `wp_mcp_ai_get_models_for_provider` - Generic model listing

#### Test Methods (14):
1. `test_ollama_connection_test()` - Local AI connection testing
2. `test_ollama_connection_requires_permissions()` - Security validation
3. `test_fetch_ollama_models()` - Model discovery
4. `test_lm_studio_connection_test()` - LM Studio validation
5. `test_fetch_lm_studio_models()` - Model fetching
6. `test_cloudflare_connection_test()` - Cloud AI testing
7. `test_brave_search_connection_test()` - Search API testing
8. `test_mubert_connection_test()` - Music API validation
9. `test_flowhub_connection_test()` - Integration testing
10. `test_isams_connection_test()` - External system testing
11. `test_provider_connection_missing_credentials()` - Error handling
12. `test_provider_connection_requires_nonce()` - Security validation
13. `test_fetch_cloudways_data()` - Data fetching operations
14. `test_get_models_for_provider()` - Generic model listing
15. `test_provider_connection_timeout_handling()` - Timeout scenarios

#### Key Features:
- Tests timeout handling for slow connections
- Validates graceful failure with missing credentials
- Tests multiple provider types (local, cloud, third-party)
- Includes async operation handling
- Comprehensive security validation

---

### 4. Model Manager AJAX Handlers Tests
**File:** `tests/test-model-manager-ajax-handlers.php`

Tests model discovery, research, and configuration management operations.

#### Endpoints Covered:
- `wp_mcp_ai_discover_models` - Async model discovery with tool integration
- `wp_mcp_ai_research_model` - Web search-enabled model research
- `wp_mcp_ai_add_model_config` - Model configuration management

#### Test Methods (11):
1. `test_discover_models_success()` - Model discovery operation
2. `test_discover_models_requires_permissions()` - Permission validation
3. `test_discover_models_requires_nonce()` - Security validation
4. `test_discover_models_requires_provider()` - Input validation
5. `test_research_model_success()` - Web search integration
6. `test_research_model_requires_permissions()` - Security check
7. `test_research_model_requires_model_name()` - Input validation
8. `test_add_model_config_success()` - Configuration management
9. `test_add_model_config_requires_permissions()` - Permission check
10. `test_add_model_config_validates_fields()` - Field validation
11. `test_concurrent_model_discovery()` - Concurrent operations
12. `test_model_research_web_search_integration()` - Tool integration
13. `test_update_existing_model_config()` - Update operations

#### Key Features:
- Tests async model discovery operations
- Validates web search tool integration
- Tests concurrent discovery operations
- Includes configuration CRUD operations
- Comprehensive input validation

---

## Running the Tests

### Prerequisites

1. Install PHP dependencies:
```bash
composer install
```

2. Set up WordPress test environment:
```bash
composer run test:install
```

### Run All Tests

```bash
composer run test
```

### Run Specific Test Suite

```bash
vendor/bin/phpunit tests/test-async-ajax-data-seeding.php
vendor/bin/phpunit tests/test-multi-agent-ajax-orchestration.php
vendor/bin/phpunit tests/test-async-ajax-provider-testing.php
vendor/bin/phpunit tests/test-model-manager-ajax-handlers.php
```

### Run with Coverage

```bash
composer run test:coverage
```

---

## Test Patterns Used

### 1. WP_Ajax_UnitTestCase Base Class
All AJAX tests extend `WP_Ajax_UnitTestCase` which provides:
- `_handleAjax()` - Simulates AJAX requests
- `_last_response` - Captures JSON responses
- Automatic exception handling for `wp_die()`

### 2. Security Testing Pattern
Every test includes:
```php
// Create admin user
$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
wp_set_current_user( $admin_id );

// Set up AJAX request with nonce
$_POST['action'] = 'action_name';
$_POST['nonce']  = wp_create_nonce( 'nonce_key' );
```

### 3. Response Validation Pattern
```php
// Make AJAX request
try {
    $this->_handleAjax( 'action_name' );
} catch ( WPAjaxDieContinueException $e ) {
    // Expected - AJAX handlers call wp_die()
}

// Get and validate response
$response = json_decode( $this->_last_response, true );
$this->assertTrue( $response['success'] );
```

### 4. Async Operation Testing
Tests validate that async operations:
- Return proper job IDs
- Create scheduled cron jobs
- Return appropriate status messages
- Handle failures gracefully

---

## Coverage Summary

| Test Suite | Methods | Endpoints | Focus Areas |
|------------|---------|-----------|-------------|
| Async Data Seeding | 11 | 6 | Multi-agent workflows, dependencies |
| Multi-Agent Orchestration | 10 | 8 | Batch operations, concurrency |
| Provider Testing | 14 | 11 | Timeouts, external APIs |
| Model Manager | 11 | 3 | Tool integration, web search |
| **Total** | **46** | **28** | **Comprehensive coverage** |

---

## Best Practices

1. **Mock External APIs**: All tests avoid actual external API calls
2. **Test Security First**: Every endpoint tests permissions and nonces
3. **Validate Error Handling**: Tests include failure scenarios
4. **Test Async Behavior**: Verify job creation and status tracking
5. **Use Factory Methods**: Create test data programmatically
6. **Clean State**: Tests are isolated and don't affect each other

---

## Future Enhancements

### Potential Additions:
1. Performance tests for bulk operations
2. Load tests for concurrent AJAX requests
3. Integration tests with actual WordPress multisite
4. End-to-end tests with browser automation
5. Stress tests for queue management

### Additional Endpoints to Test:
- Orchestration dashboard endpoints
- Assistant/team management endpoints
- Auth0 integration endpoints
- Custom provider endpoints

---

## Troubleshooting

### Common Issues:

1. **Missing Dependencies**: Run `composer install`
2. **WordPress Not Found**: Run `composer run test:install`
3. **Permission Errors**: Check user capabilities in tests
4. **Nonce Failures**: Use correct nonce action names

### Debug Mode:

Enable WordPress debug mode in tests:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

---

## Contributing

When adding new AJAX endpoints:

1. Create corresponding test methods
2. Follow existing test patterns
3. Include security tests (permissions, nonces)
4. Test both success and failure scenarios
5. Document async behavior if applicable
6. Update this documentation

---

## References

- [WordPress AJAX Testing Documentation](https://make.wordpress.org/core/handbook/testing/automated-testing/writing-phpunit-tests/)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- Plugin Documentation: `/docs/DOCUMENTATION_INDEX.md`
- REST API Reference: `/docs/rest-api.md`
