# Symfony Phase 2A - Batch 2: Tool Migration Plan

**Date:** December 9, 2025  
**Status:** 📋 Planning  
**Previous Batch:** 9 tools (100% complete)  
**This Batch:** 10 tools (Priority: High-usage core tools)

---

## Overview

Continue Phase 2A Symfony Validator migration with the next batch of high-priority tools. Focus on commonly-used read and write tools that have clear parameter validation requirements.

---

## Batch 2 Target Tools (10 Tools)

### Simple Tools (1-3 parameters)

#### 1. get_user_info ⭐ High Priority
- **Current:** `class-wp-mcp-ai-tool-get-user-info.php`
- **Parameters:** 1 (user_id)
- **Complexity:** Low
- **Usage:** Very common - user profile lookups
- **Validation Needs:**
  - user_id: positive integer, optional

#### 2. delete_cron_job ⭐ High Priority
- **Current:** `class-wp-mcp-ai-tool-delete-cron-job.php`
- **Parameters:** 1-2 (hook name, optionally timestamp)
- **Complexity:** Low
- **Usage:** Common - cron job management
- **Validation Needs:**
  - hook: string, required, not blank
  - timestamp: positive integer, optional

#### 3. get_cron_job ⭐ High Priority
- **Current:** `class-wp-mcp-ai-tool-get-cron-job.php`
- **Parameters:** 1 (hook name, optional)
- **Complexity:** Low
- **Usage:** Common - cron job inspection
- **Validation Needs:**
  - hook: string, optional

### Medium Tools (4-6 parameters)

#### 4. get_woo_products ⭐⭐ High Priority (if WooCommerce used)
- **Current:** `class-wp-mcp-ai-tool-get-woo-products.php`
- **Parameters:** 4-5 (status, limit, category, search)
- **Complexity:** Medium
- **Usage:** High in e-commerce sites
- **Validation Needs:**
  - status: enum (publish, draft, pending, etc.)
  - limit: range (1-100)
  - category: string, optional
  - search: string, optional
  - offset: positive integer, optional

#### 5. get_site_summary ⭐ Medium Priority
- **Current:** `class-wp-mcp-ai-tool-get-site-summary.php`
- **Parameters:** 2-3 (include posts, include users, limit)
- **Complexity:** Medium
- **Usage:** Medium - site overview for AI
- **Validation Needs:**
  - include_posts: boolean
  - include_users: boolean
  - limit: range (1-100)

#### 6. geocode_address ⭐ Medium Priority
- **Current:** `class-wp-mcp-ai-tool-geocode-address.php`
- **Parameters:** 1-2 (address, country)
- **Complexity:** Medium
- **Usage:** Medium - location services
- **Validation Needs:**
  - address: string, required, not blank
  - country: string, optional, 2-char code

### Image/Media Tools (specialized)

#### 7. crop_image ⭐⭐ High Priority
- **Current:** `class-wp-mcp-ai-tool-crop-image.php`
- **Parameters:** 5-6 (source, x, y, width, height, save)
- **Complexity:** Medium
- **Usage:** High - image manipulation
- **Validation Needs:**
  - source: image source (ID/URL)
  - x: integer, range (0-10000)
  - y: integer, range (0-10000)
  - width: integer, range (1-10000)
  - height: integer, range (1-10000)
  - save_as_attachment: boolean

#### 8. convert_image_format ⭐ Medium Priority
- **Current:** `class-wp-mcp-ai-tool-convert-image-format.php`
- **Parameters:** 3-4 (source, format, quality, save)
- **Complexity:** Medium
- **Usage:** Medium - image processing
- **Validation Needs:**
  - source: image source (ID/URL)
  - format: enum (jpeg, png, webp, gif)
  - quality: range (1-100)
  - save_as_attachment: boolean

### Integration Tools (requires third-party plugins)

#### 9. get_jetengine_items ⭐ Medium Priority
- **Current:** `class-wp-mcp-ai-tool-get-jetengine-items.php`
- **Parameters:** 3-4 (content_type, limit, offset, filters)
- **Complexity:** Medium
- **Usage:** High when JetEngine is used
- **Validation Needs:**
  - content_type: string, required
  - limit: range (1-100)
  - offset: positive integer
  - filters: array, optional

#### 10. get_rankmath_seo ⭐ Medium Priority
- **Current:** `class-wp-mcp-ai-tool-get-rankmath-seo.php`
- **Parameters:** 1 (post_id)
- **Complexity:** Low-Medium
- **Usage:** High when Rank Math is used
- **Validation Needs:**
  - post_id: positive integer, required

---

## Migration Pattern (Established in Batch 1)

### Step 1: Create Validation Class
**Location:** `includes/validators/arguments/class-{tool-name}-arguments.php`

```php
<?php
namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

class GetUserInfoArguments {
    #[Assert\Type(type: 'int')]
    #[Assert\Positive]
    public $user_id = 0;
}
```

### Step 2: Create Validated Tool
**Location:** `includes/tools/class-wp-mcp-ai-tool-{name}-validated.php`

```php
<?php
class WP_MCP_AI_Tool_Get_User_Info_Validated extends WP_MCP_AI_Validated_Tool {
    protected $original_tool;
    
    public function __construct() {
        parent::__construct();
        $this->original_tool = new WP_MCP_AI_Tool_Get_User_Info();
    }
    
    protected function get_validation_class() {
        return \WP_MCP_AI\Tools\Arguments\GetUserInfoArguments::class;
    }
    
    protected function execute_validated( $validated_args, $context ) {
        $arguments = (array) $validated_args;
        return $this->original_tool->execute( $arguments, $context );
    }
    
    // Delegate interface methods...
}
```

### Step 3: Create Comprehensive Tests
**Location:** `tests/test-{tool-name}-validated-tool.php`

Minimum 8-12 test methods covering:
- Tool metadata (slug, name, description, schema)
- Successful execution with defaults
- Successful execution with custom parameters
- Validation failures (missing required, invalid types, out of range)
- Permission checks
- Interface delegation
- Capability flags

### Step 4: Register Tool
**Location:** `includes/validators/validated-tools-init.php`

Add to the validated tools registry.

---

## Estimated Effort

### Per Tool Breakdown
- Validation class: 15-20 minutes
- Validated tool wrapper: 15-20 minutes
- Test suite: 30-40 minutes
- Testing & verification: 10-15 minutes
- **Total per tool:** 70-95 minutes (~1.5 hours)

### Batch Total
- **10 tools × 1.5 hours = 15 hours**
- **Spread over:** 2-3 days (5-7 hours per day)

---

## Success Criteria

### Code Quality
- [ ] All validation classes follow PHP 8 attribute pattern
- [ ] All validated tools delegate to original tools
- [ ] Zero duplicate validation logic
- [ ] All tools properly registered

### Test Coverage
- [ ] Minimum 8 test methods per tool
- [ ] All validation rules tested
- [ ] All permission checks tested
- [ ] All interface methods tested

### Documentation
- [ ] Each tool has clear parameter validation
- [ ] Migration examples documented
- [ ] Updated tool reference documentation

---

## Priority Ranking Rationale

### ⭐⭐ Highest Priority (Essential CRUD)
1. **get_user_info** - Core user functionality
2. **get_woo_products** - E-commerce critical
3. **crop_image** - Common image operation

### ⭐ High Priority (Commonly Used)
4. **delete_cron_job** - Cron management
5. **get_cron_job** - Cron inspection
6. **convert_image_format** - Image processing
7. **get_site_summary** - Site overview

### Medium Priority (Specialized but Important)
8. **geocode_address** - Location services
9. **get_jetengine_items** - JetEngine integration
10. **get_rankmath_seo** - SEO integration

---

## Risk Mitigation

### Maintaining Backward Compatibility
- ✅ Original tools remain unchanged
- ✅ Validated tools are separate classes
- ✅ Both versions can coexist
- ✅ Gradual migration path

### Testing Challenges
- ⚠️ WordPress test environment needs external network
- **Mitigation:** Follow established test patterns from Batch 1
- **Verification:** Tests ready for CI/CD execution

### Tool Dependencies
- Some tools require third-party plugins (WooCommerce, JetEngine, Rank Math)
- **Mitigation:** Tools already check for plugin availability
- **Testing:** Mock plugin availability in tests

---

## After Batch 2

### Next Batch Candidates (Batch 3)
- **Write Operations:** update_post, delete_post, update_option
- **Complex Tools:** generate_openai_image, generate_gemini_image
- **Admin Tools:** get_site_health, check_site_security

### Long-term Goal
- Migrate all 89 core tools (currently 9 complete, 10 in batch 2 = 19 total)
- Estimated total remaining: **70 tools** after batch 2
- Can create automated scaffolding tool to speed up migrations

---

## Dependencies

### Required Infrastructure (✅ All Complete)
- [x] Symfony Validator component installed
- [x] Base validated tool class (WP_MCP_AI_Validated_Tool)
- [x] Validator service (WP_MCP_AI_Validator_Service)
- [x] Validated tools registry
- [x] Test patterns established

### Documentation
- [x] Migration pattern documented (Batch 1)
- [x] Test pattern documented (Batch 1)
- [ ] Batch 2 specific examples (this document)

---

## Getting Started

### Preparation
1. Review Batch 1 completed tools for patterns
2. Set up development environment
3. Ensure Symfony Validator is loaded

### Execution Order (Recommended)
1. Start with simplest (get_user_info, delete_cron_job)
2. Move to medium complexity (get_woo_products, crop_image)
3. Finish with specialized tools (get_jetengine_items, get_rankmath_seo)

### Verification
After each tool:
1. Create validation class
2. Create validated tool
3. Create test suite
4. Register in validated tools init
5. Run linting (composer lint)
6. Document in this plan (mark complete)

---

## Progress Tracking

### Batch 2 Progress: 0% (0 of 10 tools)

- [ ] 1. get_user_info
- [ ] 2. delete_cron_job
- [ ] 3. get_cron_job
- [ ] 4. get_woo_products
- [ ] 5. get_site_summary
- [ ] 6. geocode_address
- [ ] 7. crop_image
- [ ] 8. convert_image_format
- [ ] 9. get_jetengine_items
- [ ] 10. get_rankmath_seo

---

**Last Updated:** December 9, 2025  
**Status:** 📋 Planning Complete - Ready for Implementation  
**Next Action:** Begin with get_user_info tool migration
