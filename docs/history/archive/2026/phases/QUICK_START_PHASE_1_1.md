# Quick Start: Phase 1.1 Refactoring

> **Goal**: Make ONE service use the Settings Repository instead of calling `get_option()` directly.

---

## 📋 The Change (In 30 Seconds)

### What Service?
`WP_MCP_AI_Performance_Reporting_Service`

### How Many Lines Changed?
- **4 lines** of core logic
- **3 files** total
- **~30 minutes** of work

### Risk Level?
🟢 **VERY LOW** - Internal change only, easy to revert

---

## 🔧 The 3 Changes

### 1️⃣ Add Constructor Parameter

**File**: `includes/services/class-wp-mcp-ai-performance-reporting-service.php`

```php
// Add property
private $settings_repository;

// Add constructor
public function __construct( $settings_repository = null ) {
    $this->settings_repository = $settings_repository ?? new WP_MCP_AI_Settings_Repository();
}
```

### 2️⃣ Replace get_option (Line ~405)

```php
// BEFORE
$baselines = get_option( 'wp_mcp_ai_performance_baselines', array() );

// AFTER  
$baselines = $this->settings_repository->get( 'performance_baselines', array() );
```

### 3️⃣ Replace update_option (Line ~394)

```php
// BEFORE
update_option( 'wp_mcp_ai_performance_baselines', $baselines, false );

// AFTER
$this->settings_repository->update( 'performance_baselines', $baselines );
```

---

## ✅ Verify It Works

```bash
# 1. Check syntax
php -l includes/services/class-wp-mcp-ai-performance-reporting-service.php

# 2. Verify no more direct option calls
grep -n "get_option\|update_option" includes/services/class-wp-mcp-ai-performance-reporting-service.php
# Should return: (nothing)

# 3. Test in WordPress
# - Trigger performance report
# - Check debug.log for errors
# - Verify baselines work
```

---

## 🎯 What This Achieves

### Before ❌
```php
// Tightly coupled to WordPress
$baselines = get_option( 'wp_mcp_ai_performance_baselines', array() );

// Can't test without WordPress
// Can't change storage easily  
// Can't mock for testing
```

### After ✅
```php
// Depends on abstraction
$baselines = $this->settings_repository->get( 'performance_baselines', array() );

// Can test with mock
// Can change storage anytime
// Follows SOLID principles
```

---

## 📚 Need More Details?

- **Full Guide**: See `IMPLEMENTATION_GUIDE_PHASE_1_1.md`
- **Why We're Doing This**: See `NEXT_STEP_SEPARATION_OF_CONCERNS.md`
- **Overall Analysis**: See `SEPARATION_OF_CONCERNS_INDEX.md`

---

## ⏭️ What's Next?

After this works:
1. ✅ Week 2: Refactor 2-3 more services
2. ✅ Week 3: Extract a database query
3. ✅ Week 4+: Continue incrementally

**Remember**: Small steps, verify often, don't break things! 🚀
