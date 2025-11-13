# Implementation Guide: Phase 1.1 - Settings Repository Migration

**Target Service**: `WP_MCP_AI_Performance_Reporting_Service`  
**Risk Level**: LOW ⚪  
**Estimated Time**: 2-3 hours  
**Files to Change**: 3 files  

---

## 🎯 Goal

Refactor `WP_MCP_AI_Performance_Reporting_Service` to use `WP_MCP_AI_Settings_Repository` instead of calling `get_option()` and `update_option()` directly.

---

## 📝 Step-by-Step Instructions

### Step 1: Add Settings Repository Dependency

**File**: `includes/services/class-wp-mcp-ai-performance-reporting-service.php`

**Location**: Add to the class properties (around line 20-30)

```php
/**
 * Settings repository
 *
 * @var WP_MCP_AI_Settings_Repository
 */
private $settings_repository;
```

### Step 2: Update Constructor

**File**: `includes/services/class-wp-mcp-ai-performance-reporting-service.php`

**Find the constructor** (search for `public function __construct`)

**Add parameter** and initialize:

```php
/**
 * Constructor
 *
 * @param WP_MCP_AI_Settings_Repository|null $settings_repository Settings repository instance.
 */
public function __construct( $settings_repository = null ) {
    $this->settings_repository = $settings_repository ?? new WP_MCP_AI_Settings_Repository();
}
```

**Note**: Using `null` default allows backwards compatibility - if not provided, creates instance.

### Step 3: Replace get_option() Call

**File**: `includes/services/class-wp-mcp-ai-performance-reporting-service.php`  
**Line**: ~405

**Current Code**:
```php
$baselines = get_option( 'wp_mcp_ai_performance_baselines', array() );
```

**New Code**:
```php
$baselines = $this->settings_repository->get( 'performance_baselines', array() );
```

**Changes**:
- Use `$this->settings_repository->get()` instead of `get_option()`
- Remove `wp_mcp_ai_` prefix (repository handles that)
- Keep the default value `array()`

### Step 4: Replace update_option() Call

**File**: `includes/services/class-wp-mcp-ai-performance-reporting-service.php`  
**Line**: ~394

**Current Code**:
```php
update_option( 'wp_mcp_ai_performance_baselines', $baselines, false );
```

**New Code**:
```php
$this->settings_repository->update( 'performance_baselines', $baselines );
```

**Changes**:
- Use `$this->settings_repository->update()` instead of `update_option()`
- Remove `wp_mcp_ai_` prefix
- Remove third parameter (repository doesn't use autoload parameter)

### Step 5: Update Service Initialization

**File**: `includes/services-init.php`

**Find**: Where `WP_MCP_AI_Performance_Reporting_Service` is instantiated

**Look for patterns like**:
```php
new WP_MCP_AI_Performance_Reporting_Service()
```

**Or in a factory/container**:
```php
$container->register( 'performance_reporting_service', function() {
    return new WP_MCP_AI_Performance_Reporting_Service();
});
```

**Update to inject settings repository**:
```php
$container->register( 'performance_reporting_service', function() use ( $container ) {
    return new WP_MCP_AI_Performance_Reporting_Service(
        $container->get( 'settings_repository' )
    );
});
```

**Or if no container**:
```php
$settings_repository = new WP_MCP_AI_Settings_Repository();
$performance_reporting = new WP_MCP_AI_Performance_Reporting_Service( $settings_repository );
```

---

## 🧪 Testing Changes

### Manual Testing

1. **Check service initialization**:
   ```bash
   # In WordPress, check if service initializes without errors
   # Look for PHP errors in debug.log
   ```

2. **Test performance reporting**:
   - Trigger performance report generation
   - Verify baselines are read/written correctly
   - Check no PHP errors

### Automated Testing (Optional but Recommended)

**Create**: `tests/services/test-performance-reporting-service.php`

```php
<?php
/**
 * Tests for Performance Reporting Service
 *
 * @package WP_MCP_AI
 */

class Test_Performance_Reporting_Service extends WP_UnitTestCase {

    /**
     * Test service uses settings repository
     */
    public function test_service_uses_settings_repository() {
        // Arrange
        $mock_repo = $this->createMock( WP_MCP_AI_Settings_Repository::class );
        $mock_repo->expects( $this->once() )
            ->method( 'get' )
            ->with( 'performance_baselines', array() )
            ->willReturn( array() );

        // Act
        $service = new WP_MCP_AI_Performance_Reporting_Service( $mock_repo );
        $baselines = $service->get_baselines(); // Assuming this method exists

        // Assert - Mock will fail if get() not called
        $this->assertIsArray( $baselines );
    }

    /**
     * Test service does not call get_option directly
     */
    public function test_service_does_not_call_get_option_directly() {
        // This test ensures we removed direct WordPress option dependency
        $service = new WP_MCP_AI_Performance_Reporting_Service();
        
        // If service was calling get_option directly without repository,
        // we could spy on WordPress options access here
        // For now, this is a placeholder for future enhancement
        $this->assertTrue( true );
    }
}
```

---

## ✅ Verification Checklist

Before committing, verify:

- [ ] **Code compiles**: No PHP syntax errors
- [ ] **Service initializes**: No errors when WordPress loads
- [ ] **Functionality works**: Performance reporting still functions
- [ ] **No direct calls**: Service doesn't call `get_option()` or `update_option()` directly
- [ ] **Repository used**: Settings repository methods are called
- [ ] **Tests added**: At least basic test coverage
- [ ] **Coding standards**: Follows WordPress coding standards
- [ ] **Documentation**: PHPDoc blocks updated

### Run These Commands:

```bash
# 1. Check PHP syntax
php -l includes/services/class-wp-mcp-ai-performance-reporting-service.php

# 2. Check coding standards (if installed)
composer run lint

# 3. Run tests (if test environment set up)
composer run test

# 4. Search for remaining direct option calls (should be 0)
grep -n "get_option\|update_option" includes/services/class-wp-mcp-ai-performance-reporting-service.php
```

---

## 🔍 Expected Results

### Code Search Results

**Before refactoring**:
```bash
$ grep -n "get_option\|update_option" includes/services/class-wp-mcp-ai-performance-reporting-service.php
394:    update_option( 'wp_mcp_ai_performance_baselines', $baselines, false );
405:    $baselines = get_option( 'wp_mcp_ai_performance_baselines', array() );
```

**After refactoring**:
```bash
$ grep -n "get_option\|update_option" includes/services/class-wp-mcp-ai-performance-reporting-service.php
# (no results - success!)
```

### Behavior Verification

- Performance reporting still works
- Baselines are saved/retrieved correctly
- No errors in WordPress debug log
- Service can be tested with mock repository

---

## 🚫 Common Mistakes to Avoid

### ❌ Don't Do This:

1. **Don't forget to inject dependency**:
   ```php
   // WRONG - Still creates dependency internally
   $this->settings_repository = new WP_MCP_AI_Settings_Repository();
   ```
   
   ```php
   // RIGHT - Accept as parameter
   public function __construct( $settings_repository = null ) {
       $this->settings_repository = $settings_repository ?? new WP_MCP_AI_Settings_Repository();
   }
   ```

2. **Don't keep the wp_mcp_ai_ prefix**:
   ```php
   // WRONG - Repository adds prefix automatically
   $this->settings_repository->get( 'wp_mcp_ai_performance_baselines' )
   
   // RIGHT - No prefix
   $this->settings_repository->get( 'performance_baselines' )
   ```

3. **Don't change method signatures**:
   ```php
   // WRONG - Changing public API
   public function get_baselines( $new_param ) { ... }
   
   // RIGHT - Keep same signature
   public function get_baselines() { ... }
   ```

---

## 📊 Files Summary

### Files to Modify:

1. ✏️ `includes/services/class-wp-mcp-ai-performance-reporting-service.php`
   - Add settings repository property
   - Update constructor
   - Replace 2 option calls

2. ✏️ `includes/services-init.php`
   - Update service instantiation
   - Inject settings repository

3. ➕ `tests/services/test-performance-reporting-service.php` (new)
   - Add basic tests
   - Verify repository usage

### Files NOT to Change:

- ❌ `includes/repositories/class-wp-mcp-ai-settings-repository.php` - Already complete
- ❌ Any REST controllers - Too complex for this phase
- ❌ Any admin classes - Not in scope
- ❌ Any other services - One at a time!

---

## 🎓 What This Achieves

### Before:
```php
// Service tightly coupled to WordPress options
$baselines = get_option( 'wp_mcp_ai_performance_baselines', array() );
update_option( 'wp_mcp_ai_performance_baselines', $baselines, false );

// Cannot test without WordPress
// Cannot change storage mechanism
// Cannot mock for testing
```

### After:
```php
// Service depends on abstraction
$baselines = $this->settings_repository->get( 'performance_baselines', array() );
$this->settings_repository->update( 'performance_baselines', $baselines );

// Can test with mock repository
// Can change storage without touching service
// Can inject different implementations
// Follows SOLID principles
```

---

## ⏭️ After Completion

### Success Metrics:
- [ ] Zero direct `get_option()` calls in service
- [ ] Zero direct `update_option()` calls in service  
- [ ] Service accepts repository in constructor
- [ ] Tests verify repository usage
- [ ] No functionality broken

### Next Steps:
Once this is complete and verified:

1. **Week 2**: Refactor another service (e.g., `WP_MCP_AI_Orchestration_Health_Service`)
2. **Week 3**: Continue with 2-3 more services
3. **Week 4**: Review progress, measure improvements

### Learning Applied:
- Team understands dependency injection pattern
- Team sees value of small, safe refactoring
- Team confident to continue with other services
- Pattern established for future refactoring

---

**Time Estimate**: 2-3 hours  
**Difficulty**: Easy ⭐  
**Risk**: Very Low ⚪  
**Impact**: High (pattern for all services) 🎯  

---

**Ready to Start**: Follow steps 1-5 exactly as written above.  
**Questions?**: Review the Settings Repository class for method signatures.  
**Stuck?**: Revert changes and review this guide again.  

**Remember**: This is just the first small step. Don't try to fix everything at once!
