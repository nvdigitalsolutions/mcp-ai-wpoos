# WP oOS Plugin Refactoring Plan

## Executive Summary

This document outlines a comprehensive refactoring plan to make the WP Open Operator System (WP oOS) plugin more durable, maintainable, and testable. The plan focuses on three monolithic classes that have accumulated multiple responsibilities over time.

## Problem Analysis

### Current State

The plugin has three main files that exhibit "monolithic" tendencies:

#### 1. `includes/class-wp-mcp-ai-rest.php` (8,066 lines, 123 methods)
**Current Responsibilities:**
- REST API route registration
- Request validation and sanitization
- Authentication (WordPress nonces, local tokens, mesh keys, Auth0 tokens, guest tokens)
- Permission checking
- Chat message handling and streaming (SSE)
- Assistant management endpoints
- File upload/download handling
- Tool execution
- Memory/attachment processing
- Rate limiting
- Token budget management

**Issues:**
- Single class handling ~10 distinct concerns
- 123 methods make it difficult to navigate and understand
- Tight coupling between authentication, validation, and business logic
- Hard to test individual components in isolation
- Changes to one concern (e.g., authentication) risk breaking unrelated features

#### 2. `includes/admin/class-wp-mcp-ai-admin-settings.php` (6,753 lines, 139 methods)
**Current Responsibilities:**
- Settings page registration
- Settings field registration
- 88+ UI rendering methods for different sections
- AJAX handlers for various operations (Ollama, LM Studio, Cloudflare, etc.)
- OAuth flows (Gmail integration)
- Settings validation and sanitization
- Database operations
- Admin notices
- Tool limit management

**Issues:**
- Massive UI rendering responsibility (88 render_* methods)
- Mixing of concerns: UI, AJAX, validation, OAuth, database operations
- Difficult to modify UI without risking backend logic
- Already partially refactored but still too large
- Hard to unit test due to tight coupling with WordPress admin hooks

#### 3. `includes/assistants/class-wp-mcp-ai-assistant-cpt.php` (3,800 lines, 24 methods)
**Current Responsibilities:**
- Custom Post Type registration
- Metabox rendering (multiple metaboxes)
- Credential management
- Assistant capability management
- Assistant-specific settings
- Default assistant handling
- Post list customization

**Issues:**
- While better organized than the others, still combines CPT registration with UI rendering
- Metabox rendering makes up significant portion of the class
- Credential management logic mixed with UI concerns
- Could benefit from separation of data layer and presentation layer

## Refactoring Strategy

### Guiding Principles

1. **Single Responsibility Principle (SRP)**: Each class should have one clear, well-defined responsibility
2. **Open/Closed Principle**: Classes should be open for extension but closed for modification
3. **Dependency Injection**: Dependencies should be injected, not instantiated internally
4. **Backward Compatibility**: Maintain all public APIs and global variables
5. **Incremental Changes**: Work in small, testable increments
6. **No Behavior Changes**: Extract without changing logic or behavior
7. **Test Coverage**: Add/update tests for each extraction

### Phase 1: REST API Refactoring

#### 1.1 Extract Authentication Layer
**Goal**: Separate authentication logic from the REST controller

**New Class**: `WP_MCP_AI_REST_Authenticator`
- Location: `includes/rest/class-wp-mcp-ai-rest-authenticator.php`
- Responsibilities:
  - Authentication context management
  - Nonce validation
  - Local token validation
  - Mesh key validation
  - Bearer token validation
  - Guest token handling
  - Permission error generation

**Methods to Extract:**
- `reset_auth_context()`
- `mark_token_authenticated()`
- `set_authenticated_user_id()`
- `maybe_set_current_user()`
- `get_auth_context()`
- `validate_local_token()`
- `validate_mesh_key()`
- `validate_bearer_token()`
- `get_auth0_jwks()`
- `insufficient_permissions_error()`
- `extract_guest_token()`

**Integration**:
```php
// In WP_MCP_AI_REST constructor:
$this->authenticator = new WP_MCP_AI_REST_Authenticator();

// In permissions_check method:
return $this->authenticator->permissions_check(
    $request,
    $this->resolve_assistant_id($request->get_param('assistant_id')),
    array($this, 'resolve_assistant_id'),
    array($this, 'extract_guest_token')
);
```

**Benefits:**
- Authentication logic can be tested independently
- Easier to add new authentication methods
- Clearer separation of concerns
- Reduced REST class size by ~300 lines

#### 1.2 Extract Validation Layer
**Goal**: Separate request validation logic from REST controller

**New Class**: `WP_MCP_AI_REST_Validator`
- Location: `includes/rest/class-wp-mcp-ai-rest-validator.php`
- Responsibilities:
  - Request parameter validation
  - Message array validation
  - Attachments validation
  - MCP params validation
  - Input sanitization
  - Schema validation

**Methods to Extract:**
- `validate_messages_array()`
- `validate_attachments_array()`
- `validate_mcp_params()`
- All `sanitize_*()` methods

**Integration**:
```php
// In WP_MCP_AI_REST constructor:
$this->validator = new WP_MCP_AI_REST_Validator();

// In register_routes:
'validate_callback' => array($this->validator, 'validate_messages_array')
```

**Benefits:**
- Validation rules are centralized and reusable
- Easier to add new validation rules
- Can be tested with simple unit tests
- Reduced REST class size by ~500 lines

#### 1.3 Extract SSE Streaming Handler
**Goal**: Separate Server-Sent Events streaming logic

**New Class**: `WP_MCP_AI_SSE_Handler`
- Location: `includes/rest/class-wp-mcp-ai-sse-handler.php`
- Responsibilities:
  - SSE connection management
  - Streaming response formatting
  - Connection keep-alive
  - Error handling for streams
  - Client disconnection detection

**Methods to Extract:**
- `handle_sse_handshake()`
- SSE-specific streaming methods from chat handlers

**Benefits:**
- SSE logic isolated for easier debugging
- Can support multiple streaming formats
- Reduced complexity in main REST class
- Reduced REST class size by ~200 lines

#### 1.4 What Stays in the REST Class?

After Phase 1 extraction, the `WP_MCP_AI_REST` class will remain responsible for:

**Core Routing & Orchestration:**
- Route registration (`register_routes()`)
- Request dispatching to appropriate handlers
- Response formatting and error handling
- High-level chat/tool/file endpoint coordination

**Business Logic Calls:**
The REST class will continue to orchestrate business logic by calling existing manager classes:
- **Rate Limiting**: Already delegated to `WP_MCP_AI_Rate_Limit_Manager` class (no extraction needed)
- **Token Budget**: Already delegated to `WP_MCP_AI_Token_Budget_Manager` class (no extraction needed)
- **Tool Execution**: Calls `WP_MCP_AI_Tool_Registry` (no extraction needed)
- **Model Routing**: Calls `WP_MCP_AI_Language_Model_Router` (no extraction needed)

**What Gets Moved to Service Layer (Phase 4):**
- Chat message processing logic → `WP_MCP_AI_Chat_Service`
- File upload/download orchestration → `WP_MCP_AI_File_Service`
- Tool execution workflows → `WP_MCP_AI_Tool_Service`

**Example: Before vs After**

Before Phase 1:
```php
class WP_MCP_AI_REST {
    // 123 methods including:
    // - Authentication (10+ methods) ← Extract to Authenticator
    // - Validation (7+ methods) ← Extract to Validator
    // - SSE streaming (5+ methods) ← Extract to SSE_Handler
    // - Chat orchestration (20+ methods) ← Move to Chat_Service in Phase 4
    // - Rate limiting calls (kept, already delegated)
    // - Token budget calls (kept, already delegated)
}
```

After Phase 1:
```php
class WP_MCP_AI_REST {
    // ~70 methods:
    // - Route registration
    // - Chat endpoint handlers (orchestration only)
    // - Tool endpoint handlers
    // - File endpoint handlers
    // - Uses: Authenticator, Validator, SSE_Handler
    // - Calls: Rate_Limit_Manager, Token_Budget_Manager, Tool_Registry
}
```

After Phase 4 (Service Layer):
```php
class WP_MCP_AI_REST {
    // ~50 methods:
    // - Route registration
    // - Request/response handling
    // - Uses: Authenticator, Validator, SSE_Handler
    // - Uses: Chat_Service, File_Service, Tool_Service
    // - Services internally use: Rate_Limit_Manager, Token_Budget_Manager
}
```

**Key Point**: Rate limiting and token management are already well-separated into their own manager classes. They don't need extraction—they just need to be called from the appropriate service layer classes instead of directly from the REST controller.

### Phase 2: Admin Settings Refactoring

#### 2.1 Extract UI Section Renderers
**Goal**: Separate UI rendering into focused section classes

**New Directory Structure**:
```
includes/admin/ui/
├── class-wp-mcp-ai-settings-section-renderer.php (base class)
├── class-wp-mcp-ai-settings-section-general.php
├── class-wp-mcp-ai-settings-section-providers.php
├── class-wp-mcp-ai-settings-section-tools.php
├── class-wp-mcp-ai-settings-section-security.php
└── ... (one per major section)
```

**Responsibilities per Section Class:**
- Render section-specific fields
- Handle section-specific validation
- Manage section-specific AJAX endpoints
- Section-specific help text and documentation

**Methods to Extract:**
- All 88 `render_*()` methods distributed across section classes
- Group by logical sections (General, Providers, Tools, Security, etc.)

**Integration**:
```php
// In WP_MCP_AI_Admin_Settings:
private $section_renderers = array();

public function __construct() {
    $this->section_renderers['general'] = new WP_MCP_AI_Settings_Section_General();
    $this->section_renderers['providers'] = new WP_MCP_AI_Settings_Section_Providers();
    // ... etc
}

public function render_settings_page() {
    foreach ($this->section_renderers as $renderer) {
        $renderer->render();
    }
}
```

**Benefits:**
- Each section is self-contained and independently testable
- UI changes don't affect other sections
- Reduced main class size by ~3,000 lines
- Easier to add new settings sections
- Better code organization and discoverability

#### 2.2 Extract AJAX Handler Layer
**Goal**: Complete the AJAX handler separation already started

**Current State**: `WP_MCP_AI_Admin_AJAX_Handlers` exists but incomplete

**Enhancement Strategy:**
- Move remaining AJAX methods from main class to AJAX handlers
- Create specific handler classes for complex operations
- Implement consistent error handling
- Add request validation

**New Classes**:
```
includes/admin/ajax/
├── class-wp-mcp-ai-ajax-provider-handlers.php (Ollama, LM Studio, Cloudflare)
├── class-wp-mcp-ai-ajax-token-handlers.php (Token usage management)
├── class-wp-mcp-ai-ajax-tool-handlers.php (Tool limit management)
└── class-wp-mcp-ai-ajax-oauth-handlers.php (Gmail OAuth, etc.)
```

**Benefits:**
- AJAX endpoints are well-organized and discoverable
- Each handler can be tested independently
- Consistent error handling across all AJAX endpoints
- Reduced main class size by ~800 lines

#### 2.3 Extract OAuth Integration Layer
**Goal**: Separate OAuth flows from main settings class

**New Class**: `WP_MCP_AI_OAuth_Manager`
- Location: `includes/integrations/class-wp-mcp-ai-oauth-manager.php`
- Responsibilities:
  - OAuth flow initialization
  - Callback handling
  - Token management
  - Service-specific OAuth implementations

**Methods to Extract:**
- `handle_gmail_oauth_start()`
- `handle_gmail_oauth_callback()`
- `allow_gmail_oauth_redirect_host()`

**Benefits:**
- OAuth logic isolated and reusable for other services
- Easier to add new OAuth integrations
- Security-sensitive code is isolated
- Reduced main class size by ~200 lines

### Phase 3: Assistant CPT Refactoring

#### 3.1 Extract Metabox Renderers
**Goal**: Separate metabox rendering from CPT registration

**New Classes**:
```
includes/assistants/metaboxes/
├── class-wp-mcp-ai-metabox-credentials.php
├── class-wp-mcp-ai-metabox-capabilities.php
├── class-wp-mcp-ai-metabox-settings.php
└── class-wp-mcp-ai-metabox-defaults.php
```

**Responsibilities per Metabox:**
- Render metabox UI
- Handle metabox data saving
- Validate metabox input
- Metabox-specific scripts/styles

**Methods to Extract:**
- All 8 `render_*()` methods
- Related save/validation methods

**Integration**:
```php
// In WP_MCP_AI_Assistant_CPT:
private $metaboxes = array();

public function __construct($registry) {
    $this->registry = $registry;
    $this->metaboxes['credentials'] = new WP_MCP_AI_Metabox_Credentials();
    $this->metaboxes['capabilities'] = new WP_MCP_AI_Metabox_Capabilities($registry);
    // ... etc
}
```

**Benefits:**
- Each metabox is self-contained
- Easier to modify individual metaboxes
- Better code organization
- Reduced main class size by ~1,500 lines

#### 3.2 Extract Credential Management
**Goal**: Separate credential logic from CPT

**Note**: `WP_MCP_AI_Credentials` class already exists but may need enhancement

**Enhancement Strategy:**
- Review current `WP_MCP_AI_Credentials` class
- Move any credential-specific logic from Assistant CPT to Credentials class
- Ensure credential operations are atomic and well-tested

**Benefits:**
- Credential management is centralized
- Security-sensitive code is isolated
- Easier to audit and test

### Phase 4: Additional Improvements

#### 4.1 Create Service Layer
**Goal**: Introduce a service layer for business logic

**New Classes**:
```
includes/services/
├── class-wp-mcp-ai-chat-service.php
├── class-wp-mcp-ai-assistant-service.php
├── class-wp-mcp-ai-tool-service.php
└── class-wp-mcp-ai-file-service.php
```

**Benefits:**
- Business logic separated from HTTP/UI layers
- Services can be used by REST API, admin, CLI, etc.
- Better testability
- Clearer architecture

#### 4.2 Implement Repository Pattern
**Goal**: Abstract database operations

**New Classes**:
```
includes/repositories/
├── class-wp-mcp-ai-assistant-repository.php
├── class-wp-mcp-ai-credential-repository.php
└── class-wp-mcp-ai-settings-repository.php
```

**Benefits:**
- Database operations are centralized
- Easier to cache and optimize queries
- Can be mocked for testing
- Clearer data access patterns

#### 4.3 Add Dependency Injection Container
**Goal**: Manage dependencies centrally

**Approach**: Use a simple PSR-11 compatible container or WordPress-specific solution

**Benefits:**
- Dependencies are explicit and manageable
- Easier to swap implementations
- Better testability
- Clearer dependency graph

## Implementation Roadmap

### Milestone 1: REST API Authentication (Week 1)
- [ ] Create `WP_MCP_AI_REST_Authenticator` class
- [ ] Extract authentication methods
- [ ] Update `WP_MCP_AI_REST` to use authenticator
- [ ] Add unit tests for authenticator
- [ ] Run integration tests
- [ ] Code review and documentation

**Estimated Lines Reduced**: ~300
**Risk Level**: Medium (touches critical authentication logic)
**Testing Priority**: High

### Milestone 2: REST API Validation (Week 2)
- [ ] Create `WP_MCP_AI_REST_Validator` class
- [ ] Extract validation methods
- [ ] Update REST endpoints to use validator
- [ ] Add unit tests for validator
- [ ] Run integration tests
- [ ] Code review and documentation

**Estimated Lines Reduced**: ~500
**Risk Level**: Low (validation is stateless)
**Testing Priority**: High

### Milestone 3: REST API SSE Handler (Week 3)
- [ ] Create `WP_MCP_AI_SSE_Handler` class
- [ ] Extract SSE streaming logic
- [ ] Update chat endpoints to use SSE handler
- [ ] Test SSE connections
- [ ] Run integration tests
- [ ] Code review and documentation

**Estimated Lines Reduced**: ~200
**Risk Level**: Medium (streaming is complex)
**Testing Priority**: High

### Milestone 4: Admin Settings UI Sections (Week 4-5)
- [ ] Create section renderer base class
- [ ] Create section-specific renderer classes
- [ ] Extract render methods to section classes
- [ ] Update settings page to use section renderers
- [ ] Test all settings sections
- [ ] Code review and documentation

**Estimated Lines Reduced**: ~3,000
**Risk Level**: Low (mostly UI changes)
**Testing Priority**: Medium

### Milestone 5: Admin Settings AJAX Handlers (Week 6)
- [ ] Create specialized AJAX handler classes
- [ ] Extract AJAX methods from main class
- [ ] Update AJAX action hooks
- [ ] Test all AJAX endpoints
- [ ] Code review and documentation

**Estimated Lines Reduced**: ~800
**Risk Level**: Low (AJAX handlers are independent)
**Testing Priority**: Medium

### Milestone 6: Admin Settings OAuth (Week 7)
- [ ] Create `WP_MCP_AI_OAuth_Manager` class
- [ ] Extract OAuth methods
- [ ] Update settings to use OAuth manager
- [ ] Test OAuth flows
- [ ] Code review and documentation

**Estimated Lines Reduced**: ~200
**Risk Level**: Medium (OAuth is security-sensitive)
**Testing Priority**: High

### Milestone 7: Assistant CPT Metaboxes (Week 8)
- [ ] Create metabox classes
- [ ] Extract metabox rendering logic
- [ ] Update CPT to use metabox classes
- [ ] Test metabox rendering and saving
- [ ] Code review and documentation

**Estimated Lines Reduced**: ~1,500
**Risk Level**: Low (metaboxes are independent)
**Testing Priority**: Medium

### Milestone 8: Service Layer (Week 9-10)
- [ ] Create service classes
- [ ] Extract business logic from controllers
- [ ] Update controllers to use services
- [ ] Add service tests
- [ ] Code review and documentation

**Estimated Lines Reduced**: ~500 (net - adds some, reduces controllers)
**Risk Level**: Medium (changes architecture)
**Testing Priority**: High

### Milestone 9: Repository Pattern (Week 11)
- [ ] Create repository classes
- [ ] Extract database operations
- [ ] Update services to use repositories
- [ ] Add repository tests
- [ ] Code review and documentation

**Estimated Lines Reduced**: ~300 (net)
**Risk Level**: Medium (changes data access)
**Testing Priority**: High

### Milestone 10: Dependency Injection (Week 12)
- [ ] Choose/implement DI container
- [ ] Configure service definitions
- [ ] Update bootstrapping code
- [ ] Test complete application
- [ ] Code review and documentation

**Estimated Lines Reduced**: 0 (improves architecture)
**Risk Level**: High (changes initialization)
**Testing Priority**: Critical

## Code Inventory & Verification

### Pre-Refactoring Baseline
Before starting any refactoring, establish a baseline inventory:

**Script: `bin/code-inventory.sh`**
```bash
#!/bin/bash
# Generate code inventory for verification

echo "=== Code Inventory Report ==="
echo "Generated: $(date)"
echo ""

echo "Total PHP Files:"
find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" | wc -l

echo ""
echo "Total Lines of Code:"
find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" -exec wc -l {} + | tail -1

echo ""
echo "Total Classes:"
grep -r "^class " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . | wc -l

echo ""
echo "Total Functions:"
grep -r "^function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . | wc -l

echo ""
echo "Total Methods (public):"
grep -r "public function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . | wc -l

echo ""
echo "Total Methods (protected):"
grep -r "protected function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . | wc -l

echo ""
echo "Total Methods (private):"
grep -r "private function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . | wc -l

echo ""
echo "=== Detailed Class Inventory ==="
grep -r "^class " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . | sed 's/:class /: /' | sort

echo ""
echo "=== Function Signatures in Main Classes ==="
for file in includes/class-wp-mcp-ai-rest.php includes/admin/class-wp-mcp-ai-admin-settings.php includes/assistants/class-wp-mcp-ai-assistant-cpt.php; do
    if [ -f "$file" ]; then
        echo ""
        echo "File: $file"
        grep -n "^\s*\(public\|private\|protected\) function" "$file" | head -20
    fi
done
```

**Run before refactoring:**
```bash
bash bin/code-inventory.sh > BASELINE-INVENTORY.txt
```

### Post-Refactoring Verification
After each milestone and at the end, verify code integrity:

**Verification Checklist:**
- [ ] Total number of public methods unchanged (may move but not removed)
- [ ] Total number of classes increased (expected due to extraction)
- [ ] All public APIs still exist (backward compatibility)
- [ ] All global function definitions preserved
- [ ] All WordPress hooks still registered
- [ ] All REST endpoints still registered
- [ ] No functionality removed (only reorganized)

**Script: `bin/verify-refactoring.sh`**
```bash
#!/bin/bash
# Verify refactoring didn't lose functionality

echo "=== Refactoring Verification Report ==="
echo "Generated: $(date)"
echo ""

# Generate current inventory
bash bin/code-inventory.sh > CURRENT-INVENTORY.txt

# Compare with baseline
echo "=== Changes Summary ==="
echo ""
echo "PHP Files:"
echo "  Before: $(grep "Total PHP Files:" BASELINE-INVENTORY.txt | awk '{print $4}')"
echo "  After:  $(grep "Total PHP Files:" CURRENT-INVENTORY.txt | awk '{print $4}')"

echo ""
echo "Classes:"
echo "  Before: $(grep "Total Classes:" BASELINE-INVENTORY.txt | awk '{print $3}')"
echo "  After:  $(grep "Total Classes:" CURRENT-INVENTORY.txt | awk '{print $3}')"

echo ""
echo "Public Methods:"
echo "  Before: $(grep "Total Methods (public):" BASELINE-INVENTORY.txt | awk '{print $4}')"
echo "  After:  $(grep "Total Methods (public):" CURRENT-INVENTORY.txt | awk '{print $4}')"

echo ""
echo "Protected Methods:"
echo "  Before: $(grep "Total Methods (protected):" BASELINE-INVENTORY.txt | awk '{print $4}')"
echo "  After:  $(grep "Total Methods (protected):" CURRENT-INVENTORY.txt | awk '{print $4}')"

echo ""
echo "Private Methods:"
echo "  Before: $(grep "Total Methods (private):" BASELINE-INVENTORY.txt | awk '{print $4}')"
echo "  After:  $(grep "Total Methods (private):" CURRENT-INVENTORY.txt | awk '{print $4}')"

# Check for removed public methods (critical)
echo ""
echo "=== Checking for Removed Public Methods ==="
grep "public function" BASELINE-INVENTORY.txt | sort > /tmp/baseline-public.txt
grep "public function" CURRENT-INVENTORY.txt | sort > /tmp/current-public.txt
diff /tmp/baseline-public.txt /tmp/current-public.txt | grep "^<" || echo "✓ No public methods removed"

# Check for removed classes (should only increase)
echo ""
echo "=== Checking for Removed Classes ==="
grep "^class " BASELINE-INVENTORY.txt | awk '{print $2}' | sort > /tmp/baseline-classes.txt
grep "^class " CURRENT-INVENTORY.txt | awk '{print $2}' | sort > /tmp/current-classes.txt
diff /tmp/baseline-classes.txt /tmp/current-classes.txt | grep "^<" || echo "✓ No classes removed"

# Verify REST endpoints
echo ""
echo "=== Verifying REST Endpoints ==="
grep -r "register_rest_route" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . | wc -l
echo "REST route registrations found"

# Verify WordPress hooks
echo ""
echo "=== Verifying WordPress Hooks ==="
echo "add_action calls: $(grep -r "add_action" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . | wc -l)"
echo "add_filter calls: $(grep -r "add_filter" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . | wc -l)"

echo ""
echo "=== Verification Complete ==="
```

### Method-Level Tracking
For critical classes, track individual methods:

**Script: `bin/track-methods.sh`**
```bash
#!/bin/bash
# Track all methods in the three main classes

echo "=== Method Tracking Report ==="

for file in includes/class-wp-mcp-ai-rest.php \
            includes/admin/class-wp-mcp-ai-admin-settings.php \
            includes/assistants/class-wp-mcp-ai-assistant-cpt.php; do
    if [ -f "$file" ]; then
        echo ""
        echo "File: $file"
        echo "Methods:"
        grep -n "^\s*\(public\|private\|protected\) function" "$file" | \
            sed 's/^\([0-9]*\).*function \([a-zA-Z_][a-zA-Z0-9_]*\).*/  Line \1: \2/' | \
            sort -k3
    fi
done
```

### Automated Verification in CI
Add to GitHub Actions workflow:

```yaml
- name: Verify Refactoring Integrity
  run: |
    # Only run on refactoring branches
    if [[ $GITHUB_REF == *"refactor"* ]]; then
      bash bin/verify-refactoring.sh
      # Fail if public methods were removed
      if grep -q "^<.*public function" /tmp/public-diff.txt 2>/dev/null; then
        echo "ERROR: Public methods were removed!"
        exit 1
      fi
    fi
```

## Testing Strategy

### Unit Testing
- Each extracted class must have unit tests
- Aim for 80%+ code coverage on new classes
- Mock dependencies to test in isolation
- Use PHPUnit with WordPress test framework

### Integration Testing
- Test REST API endpoints end-to-end
- Test admin UI workflows
- Test authentication flows
- Test cross-component interactions

### Regression Testing
- Run full test suite after each milestone
- Manual testing of critical paths
- Performance benchmarking
- Security audit
- **Code inventory verification** (new requirement)

### Verification Testing
- **Before starting:** Run `bin/code-inventory.sh > BASELINE-INVENTORY.txt`
- **After each milestone:** Run `bin/verify-refactoring.sh`
- **Final check:** Ensure all public methods exist, no functionality removed

## Success Metrics

### Code Quality Metrics
- **REST Class**: Reduce from 8,066 to ~6,000 lines (25% reduction)
- **Admin Settings Class**: Reduce from 6,753 to ~3,000 lines (55% reduction)
- **Assistant CPT Class**: Reduce from 3,800 to ~2,000 lines (47% reduction)
- **Cyclomatic Complexity**: Reduce average complexity per method
- **Method Count**: No class with more than 50 methods

### Maintainability Metrics
- **Test Coverage**: Increase from current to 70%+
- **Documentation**: 100% of public methods documented
- **Code Duplication**: Reduce by 30%
- **PHPCS Compliance**: 100% WordPress coding standards

### Performance Metrics
- **No Performance Regression**: All operations within 5% of baseline
- **Memory Usage**: No increase in memory footprint
- **Page Load Time**: Admin pages within 10% of baseline

## Risk Management

### High-Risk Areas
1. **Authentication Logic**: Critical for security
   - **Mitigation**: Extensive testing, security review, gradual rollout
2. **SSE Streaming**: Complex, hard to test
   - **Mitigation**: Comprehensive integration tests, monitoring
3. **Dependency Injection**: Affects entire application
   - **Mitigation**: Phased implementation, feature flags

### Medium-Risk Areas
1. **OAuth Flows**: Security-sensitive, external dependencies
2. **REST API Changes**: Public API compatibility
3. **Service Layer**: Architecture changes

### Low-Risk Areas
1. **UI Section Renderers**: Isolated, easy to test
2. **Metabox Extraction**: Independent components
3. **AJAX Handlers**: Stateless endpoints

## Rollback Plan

Each milestone should be implemented in a feature branch with the ability to rollback:

1. **Feature Branches**: Each milestone in separate branch
2. **Feature Flags**: Use constants to enable/disable new code
3. **Gradual Rollout**: Deploy to staging, then subset of production
4. **Monitoring**: Track errors, performance, user feedback
5. **Quick Rollback**: Ability to disable new code via constant

Example feature flag:
```php
if ( defined( 'WP_MCP_AI_USE_NEW_AUTH' ) && WP_MCP_AI_USE_NEW_AUTH ) {
    // Use new authenticator
} else {
    // Use legacy authentication
}
```

## Documentation Updates

### Developer Documentation
- [ ] Update architecture diagrams
- [ ] Document new class responsibilities
- [ ] Update contribution guidelines
- [ ] Create migration guide

### User Documentation
- [ ] No user-facing changes (internal refactoring)
- [ ] Update troubleshooting if needed

### Code Documentation
- [ ] PHPDoc for all new classes
- [ ] Inline comments for complex logic
- [ ] Update README if needed

## Conclusion

This refactoring plan will transform the WP oOS plugin from a collection of monolithic classes into a well-structured, maintainable, and testable codebase. The incremental approach ensures that each change can be tested and validated independently, reducing risk and allowing for course corrections.

**Total Estimated Time**: 12 weeks
**Total Lines Reduced**: ~6,300 lines from the three main classes
**Complexity Reduction**: Estimated 40-50% reduction in average method complexity
**Testability**: Significant improvement in unit test coverage
**Maintainability**: Major improvement in code organization and discoverability

The plan prioritizes high-value, low-risk changes first, building confidence before tackling more complex architectural improvements. By following this plan, the plugin will be more durable, easier to maintain, and better positioned for future growth.
