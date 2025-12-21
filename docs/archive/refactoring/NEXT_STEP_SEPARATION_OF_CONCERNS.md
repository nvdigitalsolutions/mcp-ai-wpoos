# Next Step: Separation of Concerns Refactoring

**Date**: 2025-11-13  
**Status**: Ready to Implement  
**Risk Level**: LOW ⚪  
**Estimated Time**: 1 week  

---

## 🎯 Objective

Implement the **smallest, safest** separation of concerns improvement that:
- Demonstrates the refactoring pattern
- Doesn't break existing functionality
- Provides immediate value
- Builds team confidence for larger refactoring

---

## 📋 Phase 1.1: Settings Repository Migration (Week 1)

### What We're Fixing

Currently, **12 service classes** directly call `get_option()` and `update_option()`, violating separation of concerns. Services should not know about the storage mechanism (WordPress options).

**Good News**: We already have `WP_MCP_AI_Settings_Repository` that abstracts this!

### Scope: Refactor 1-2 Services Only

**Start with the simplest service**: `WP_MCP_AI_Performance_Reporting_Service`

### Current Code (❌ Violation)

```php
// includes/services/class-wp-mcp-ai-performance-reporting-service.php:394
$baselines = get_option( 'wp_mcp_ai_performance_baselines', array() );
update_option( 'wp_mcp_ai_performance_baselines', $baselines, false );
```

### Refactored Code (✅ Correct)

```php
// Step 1: Add settings repository to constructor
private $settings_repository;

public function __construct( $settings_repository = null ) {
    $this->settings_repository = $settings_repository ?? wp_mcp_ai_get_settings_repository();
}

// Step 2: Use repository instead of direct option access
$baselines = $this->settings_repository->get_setting( 'performance_baselines', array() );
$this->settings_repository->update_setting( 'performance_baselines', $baselines );
```

### Implementation Steps

1. **Update Settings Repository** (if needed)
   - Ensure `get_setting()` and `update_setting()` methods exist
   - Add any missing helper methods

2. **Refactor Service Class**
   - Add settings repository as constructor dependency
   - Replace all `get_option()` calls with `$this->settings_repository->get_setting()`
   - Replace all `update_option()` calls with `$this->settings_repository->update_setting()`

3. **Update Container/Service Initialization**
   - Ensure settings repository is injected when service is created
   - Update in `includes/services-init.php`

4. **Add Tests**
   - Test service with mock settings repository
   - Verify behavior unchanged
   - Test that service doesn't directly call `get_option()`

5. **Verify**
   - Run all existing tests
   - Manually test affected features
   - Check no errors in logs

---

## 📊 Files to Change

### Primary Changes (Must Do)

1. `includes/repositories/class-wp-mcp-ai-settings-repository.php`
   - Verify `get_setting()` and `update_setting()` methods exist
   - Add if missing

2. `includes/services/class-wp-mcp-ai-performance-reporting-service.php`
   - Add dependency injection for settings repository
   - Replace direct option calls

3. `includes/services-init.php`
   - Update service instantiation to inject settings repository

### Test Files (Must Add)

4. `tests/test-performance-reporting-service.php` (create new)
   - Test settings repository integration
   - Test that direct option calls are gone

---

## ✅ Success Criteria

### Before Merging, Verify:

- [ ] All existing tests pass
- [ ] New tests added for refactored service
- [ ] Service no longer calls `get_option()` or `update_option()` directly
- [ ] Manual testing shows no behavior changes
- [ ] No PHP errors or warnings
- [ ] Settings repository is properly injected

### Code Quality Checks:

- [ ] Settings repository is dependency-injected (constructor parameter)
- [ ] Service has no knowledge of WordPress options storage
- [ ] Code is more testable (can mock settings repository)
- [ ] Following WordPress coding standards

---

## 🔬 Testing Strategy

### Unit Tests

```php
class Test_Performance_Reporting_Service extends WP_UnitTestCase {
    
    public function test_uses_settings_repository() {
        // Create mock repository
        $mock_repo = $this->createMock( WP_MCP_AI_Settings_Repository::class );
        
        // Expect get_setting to be called
        $mock_repo->expects( $this->once() )
            ->method( 'get_setting' )
            ->with( 'performance_baselines' )
            ->willReturn( array() );
        
        // Create service with mock
        $service = new WP_MCP_AI_Performance_Reporting_Service( $mock_repo );
        
        // Call method that uses settings
        $service->get_baselines();
        
        // Mock verifies get_setting was called
    }
    
    public function test_does_not_call_get_option_directly() {
        // Use WordPress test spy to ensure get_option is never called
        // This test ensures we truly removed the direct dependency
    }
}
```

### Integration Tests

- Test service in real WordPress environment
- Verify settings are read/written correctly
- Check backwards compatibility

---

## 🚫 What NOT to Do (Stay Focused)

### ❌ Don't Do These (Yet):

- Don't refactor multiple services at once
- Don't touch the REST controller (too complex for first step)
- Don't split any large classes
- Don't change public APIs
- Don't modify tool implementations
- Don't update admin UI
- Don't change database schema

### ✅ Only Do This:

- Refactor 1-2 service classes to use settings repository
- Add tests for those services
- Verify nothing breaks

---

## 📈 Expected Benefits

### Immediate Benefits:

1. **Better Testability**: Service can be tested with mock repository
2. **Less Coupling**: Service doesn't know about WordPress options
3. **Pattern Established**: Shows team how to do similar refactoring
4. **Confidence Built**: Team sees that refactoring is safe

### Future Benefits:

1. **Easier Storage Changes**: Can change from WordPress options to custom table
2. **Better Performance**: Can add caching in repository
3. **Consistent Access**: All settings go through same interface
4. **Audit Trail**: Repository can log all settings changes

---

## ⏭️ After This Step

Once this small refactoring is successful:

### Week 2: Continue Settings Repository Migration

- Refactor 2-3 more services
- Same pattern, more confidence

### Week 3: Extract One Database Query

- Find ONE `$wpdb` query in REST controller
- Extract to repository
- Same careful approach

### Future: Bigger Refactoring

- Only after several successful small refactorings
- Split REST controller (Phase 3)
- Remove hard-coded dependencies (Phase 2)

---

## 🛡️ Risk Mitigation

### Low Risk Because:

1. **Small Scope**: Only 1-2 files changing
2. **Tested**: Comprehensive tests before merging
3. **Reversible**: Easy to revert if issues found
4. **Internal**: No API or behavior changes
5. **Incremental**: Can stop at any point

### Safety Checks:

- [ ] Create branch for changes
- [ ] Run tests after each change
- [ ] Manual testing in development environment
- [ ] Code review before merging
- [ ] Deploy to staging first
- [ ] Monitor logs after deployment

---

## 📚 Related Documentation

- [Separation of Concerns Index](SEPARATION_OF_CONCERNS_INDEX.md)
- [Separation of Concerns Violations](SEPARATION_OF_CONCERNS_VIOLATIONS.md)
- [Separation of Concerns Summary](../SEPARATION_OF_CONCERNS_SUMMARY.md)
- [Settings Repository](includes/repositories/class-wp-mcp-ai-settings-repository.php)

---

## 🎓 Learning Outcomes

After this refactoring, the team will understand:

1. How to use dependency injection in WordPress
2. How to use repositories for data access
3. How to write tests with mock dependencies
4. How to refactor safely without breaking things
5. The value of small, incremental improvements

---

## 💡 Key Principle

> **"Make the change easy, then make the easy change."** - Kent Beck

This first step makes future refactoring easier by:
- Establishing the pattern
- Building team confidence
- Creating test infrastructure
- Demonstrating the benefits

---

**Ready to Implement**: Yes ✅  
**Risk Level**: Low ⚪  
**Team Confidence**: High 💪  
**Expected Duration**: 1 week 📅  

---

**Next Action**: 
1. Review this plan with team
2. Create implementation branch
3. Start with `WP_MCP_AI_Performance_Reporting_Service`
4. Follow the steps exactly as outlined
5. Test thoroughly
6. Ship when confident

**Remember**: Go slow to go fast. Small, safe changes build confidence for bigger improvements.
