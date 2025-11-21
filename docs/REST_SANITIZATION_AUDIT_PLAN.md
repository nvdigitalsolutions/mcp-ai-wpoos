# REST Endpoint Sanitization Audit Plan

## Goal
Audit all REST endpoints to ensure proper sanitization that:
1. Preserves valid data formats (dots, special chars where needed)
2. Blocks security threats (path traversal, XSS, injection)
3. Is consistent and maintainable

## Current Status

### ✅ Fixed Endpoints (Current PR)
- `/cron-status/{job_id}` - Custom `sanitize_job_id()` in Tools Controller
- `/jobs/{job_id}/stream` - Custom `sanitize_job_id()` in Job Notifier REST
- `/jobs/{job_id}` - Custom `sanitize_job_id()` in Job Notifier REST
- `/jobs/{job_id}/webhooks` - Custom `sanitize_job_id()` in Job Notifier REST

### 🔍 Endpoints to Audit

#### ID-Based Parameters
1. `/files/{file_id}/download` 
   - Current: `[^/]+` pattern, `sanitize_text_field()`
   - May need custom sanitizer for file IDs (OpenAI file IDs, attachment IDs)

2. `/chat-transcripts/{session_key}`
   - Current: `[^/]+` pattern
   - Session keys may have special format requirements

3. `/crawl4ai/task/{task_id}`
   - Current: `[A-Za-z0-9_\-]+` pattern, `sanitize_text_field()`
   - Uses `wp_generate_password()` (no dots), but should verify sanitization

4. `/analytics/trends/{user_id}`
5. `/analytics/patterns/{user_id}`
6. `/users/{id}/cost-breakdown`
7. `/users/{id}/roi`
8. `/users/{id}/token-tier`
9. `/users/{id}/token-usage`
10. `/users/{id}/token-forecast`
    - All use `\d+` pattern for numeric IDs
    - Should verify proper `absint()` sanitization

11. `/assistants/{id}`
    - Current: `\d+` pattern
    - Should verify numeric sanitization

12. `/teams/{id}/members`
    - Current: `\d+` pattern
    - Should verify numeric sanitization

13. `/peers/{id}`, `/reverify/{id}`, `/report/{id}`
    - Federation endpoints with `\d+` pattern
    - Should verify numeric sanitization

#### String/Complex Parameters
Need to audit all endpoints for parameters accepting:
- Email addresses
- URLs
- File paths
- JSON payloads
- Search queries
- User-generated content
- Tool arguments
- Assistant configurations

## Proposed Approach

### Phase 1: Inventory & Analysis
1. Create comprehensive list of all REST endpoints (use `register_rest_route` grep)
2. Document current sanitization for each parameter type
3. Identify data format requirements (what characters are valid?)
4. Identify security requirements (what must be blocked?)
5. Categorize parameters by data type

### Phase 2: Create Sanitization Library
Create a centralized class with reusable sanitization methods:

```php
class WP_MCP_AI_REST_Sanitizers {
    // Already implemented
    public static function sanitize_job_id( $job_id ) { /* ... */ }
    
    // To implement
    public static function sanitize_numeric_id( $id, $min = 1, $max = null ) { /* ... */ }
    public static function sanitize_file_id( $file_id ) { /* ... */ }
    public static function sanitize_session_key( $key ) { /* ... */ }
    public static function sanitize_url_param( $url ) { /* ... */ }
    public static function sanitize_email_param( $email ) { /* ... */ }
    public static function sanitize_slug( $slug ) { /* ... */ }
    public static function sanitize_uuid( $uuid ) { /* ... */ }
    public static function sanitize_json_string( $json ) { /* ... */ }
    // etc.
}
```

### Phase 3: Implementation
1. Create `includes/rest/class-wp-mcp-ai-rest-sanitizers.php`
2. Move existing `sanitize_job_id()` methods to centralized class
3. Update all REST controllers to use the sanitizers class
4. Add comprehensive tests for each sanitizer
5. Add endpoint-specific tests with security attack vectors

### Phase 4: Documentation
1. Create `docs/REST_SANITIZATION.md` with:
   - Security best practices
   - When to use each sanitizer
   - How to create custom sanitizers
   - Common attack vectors to block
2. Add inline code comments explaining security considerations
3. Update REST API documentation with sanitization details

## Security Considerations

### Common Threats to Block
- **Path traversal**: `../`, `..\\`, `%2e%2e/`, `..\`, `....//`
- **SQL injection**: `'; DROP TABLE`, `' OR '1'='1`, `--`, etc.
- **XSS**: `<script>`, `javascript:`, `<iframe>`, `onerror=`, etc.
- **Command injection**: `; rm -rf`, `| cat`, `&& ls`, `$(whoami)`, etc.
- **Null byte injection**: `\0`, `%00`
- **LDAP injection**: `*)(uid=*))(|(uid=*`, etc.
- **XML injection**: `<!DOCTYPE>`, `<!ENTITY>`, etc.
- **Template injection**: `{{`, `${`, `<%`, etc.

### Data Format Preservation
- **Dots** in uniqid() generated IDs (e.g., `veo_69203b5b2388f5.11575461`)
- **Hyphens** in UUIDs (e.g., `550e8400-e29b-41d4-a716-446655440000`)
- **Underscores** in identifiers
- **Wildcards** where appropriate (`*` for job patterns)
- **International characters** where needed (UTF-8 support)
- **Special chars** in OpenAI file IDs (e.g., `file-abc123`)
- **Colons** in session keys or timestamps

## Files to Create/Modify

### New Files to Create
- `includes/rest/class-wp-mcp-ai-rest-sanitizers.php` - Centralized sanitization library
- `tests/test-rest-sanitizers.php` - Comprehensive sanitizer unit tests
- `tests/test-rest-security.php` - Security-focused endpoint tests
- `docs/REST_SANITIZATION.md` - Security documentation

### Files to Update
- `includes/rest/class-wp-mcp-ai-rest-tools-controller.php` - Use centralized sanitizers
- `includes/class-wp-mcp-ai-job-notifier-rest.php` - Use centralized sanitizers
- `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` - Audit and update
- `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php` - Audit and update
- `includes/rest/class-wp-mcp-ai-rest-analytics-manager.php` - Audit and update
- `includes/rest/class-wp-mcp-ai-rest-cost-manager.php` - Audit and update
- `includes/rest/class-wp-mcp-ai-rest-token-manager.php` - Audit and update
- `includes/rest/class-wp-mcp-ai-rest-teams-controller.php` - Audit and update
- `includes/class-wp-mcp-ai-federation-directory-rest.php` - Audit and update
- `includes/class-wp-mcp-ai-crawl4ai-local-api.php` - Audit and update
- All test files for the above controllers

## Implementation Checklist

### Phase 1: Inventory (Week 1)
- [ ] List all REST endpoints with `grep -r register_rest_route`
- [ ] Document each endpoint's parameters and current sanitization
- [ ] Categorize parameters by data type
- [ ] Identify security risks for each parameter type

### Phase 2: Library Creation (Week 1-2)
- [ ] Create `WP_MCP_AI_REST_Sanitizers` class
- [ ] Implement core sanitizers (numeric, job_id, file_id, etc.)
- [ ] Create comprehensive test suite for sanitizers
- [ ] Document each sanitizer's purpose and usage

### Phase 3: Controller Updates (Week 2-3)
- [ ] Update Tools Controller to use centralized sanitizers
- [ ] Update Job Notifier REST to use centralized sanitizers
- [ ] Update Chat Controller
- [ ] Update MCP Controller
- [ ] Update Analytics Manager
- [ ] Update Cost Manager
- [ ] Update Token Manager
- [ ] Update Teams Controller
- [ ] Update Federation Directory REST
- [ ] Update Crawl4AI Local API

### Phase 4: Testing & Documentation (Week 3-4)
- [ ] Add security-focused tests for each endpoint
- [ ] Test attack vectors (path traversal, XSS, injection, etc.)
- [ ] Create `docs/REST_SANITIZATION.md`
- [ ] Update REST API documentation
- [ ] Code review and security audit
- [ ] Final testing and validation

## Estimated Scope
- **Endpoints to review**: ~40+ REST endpoints
- **Custom sanitizers to create**: ~10-15 specialized sanitizers
- **Test files to create/update**: ~20+ test files
- **Documentation**: 3-4 documentation files
- **Timeline**: 3-4 weeks for comprehensive audit and implementation
- **Should be separate PR**: This is a significant security enhancement project

## Dependencies
- Current PR must be merged first (provides foundation with `sanitize_job_id()`)
- May require WordPress Coding Standards updates
- May require PHPUnit test environment enhancements

## Success Criteria
1. All REST endpoints use appropriate sanitization
2. All common attack vectors are blocked
3. All valid data formats are preserved
4. 100% test coverage for sanitizers
5. Security documentation is comprehensive
6. No breaking changes to existing functionality

## Notes
- This should be a **separate PR** from the current dot-in-job-ids fix
- Focus on security without breaking existing functionality
- Prioritize high-risk endpoints (file uploads, user input, etc.)
- Consider creating a security checklist for new endpoints

## References
- WordPress Sanitization Functions: https://developer.wordpress.org/apis/security/sanitizing-securing-output/
- OWASP Input Validation: https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html
- WordPress REST API Security: https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
