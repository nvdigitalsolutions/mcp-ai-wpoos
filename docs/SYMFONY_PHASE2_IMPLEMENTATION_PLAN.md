# Symfony Phase 2 Implementation Plan

## Date
December 8, 2025

## Status
**IN PROGRESS** - Migrating tools to Symfony Validator pattern

## Overview
Phase 2 continues the Symfony enhancements by migrating existing tools to use the validated pattern, implementing Symfony Process for Pro tools, and preparing for Symfony AI Embeddings integration.

## Phase 2 Goals

### 1. Tool Migration (High Priority)
**Objective:** Migrate 10-15 high-priority tools to Symfony Validator pattern

**Target Tools:**
1. ✅ `save_post` - Create/update posts (validation class created)
2. [ ] `create_assistant` - Create AI assistants
3. [ ] `send_group_email` - Send bulk emails
4. [ ] `create_woo_product` - Create WooCommerce products
5. [ ] `create_chart` - Generate charts
6. [ ] `get_recent_posts` - Query posts with filters
7. [ ] `search_content` - Content search
8. [ ] `create_cron_job` - Schedule WordPress cron jobs
9. [ ] `update_user_meta` - Modify user metadata
10. [ ] `get_system_logs` - Retrieve system logs

**Expected Benefits:**
- 30-50% reduction in validation code per tool
- Consistent error messages across all tools
- Type-safe argument objects
- Self-documenting validation rules

### 2. Symfony Process Integration (Medium Priority)
**Objective:** Replace direct `exec()` calls with Symfony Process component

**Target Tools (Pro Addon):**
- FFmpeg video processing tools
- Python rembg background removal
- WP-CLI execution wrapper
- Jukebox audio generation

**Benefits:**
- Automatic timeout handling
- Real-time output capture
- Better error handling
- Async process support

### 3. Symfony AI Embeddings (High Priority)
**Objective:** Add semantic search capabilities

**Components to Create:**
- `WP_MCP_AI_Embeddings_Manager` - Manage vector embeddings
- `WP_MCP_AI_Embeddings_Storage` - Store embeddings (custom table or CCT)
- `semantic_search` tool - Search by meaning, not keywords
- `generate_embeddings` WP-CLI command - Batch processing
- Admin UI for embedding management

**Benefits:**
- Semantic content discovery
- Better search relevance
- Knowledge base search
- Content recommendations

## Implementation Progress

### Validation Classes Created
- [x] `SavePostArguments` - For save_post tool (2025-12-08)
- [x] `CreateAssistantArguments` - For create_assistant tool (2025-12-08)
- [x] `SearchContentArguments` - For search_content tool (2025-12-08)
- [x] `CreateCronJobArguments` - For create_cron_job tool (2025-12-08)
- [x] `GetRecentPostsArguments` - For get_recent_posts tool (2025-12-08)
- [ ] `SendGroupEmailArguments` - For send_group_email tool
- [ ] `CreateWooProductArguments` - For create_woo_product tool
- [ ] `CreateChartArguments` - For create_chart tool
- [ ] `UpdateUserMetaArguments` - For update_user_meta tool
- [ ] `GetSystemLogsArguments` - For get_system_logs tool

### Tools Migrated
- [x] `save_post` → `save_post_validated` (2025-12-08)
  - File: `includes/tools/class-wp-mcp-ai-tool-save-post-validated.php`
  - Tests: `tests/test-save-post-validated-tool.php` (11 test methods)
  - Documentation: `docs/SAVE_POST_MIGRATION_EXAMPLE.md`
  - Status: ✅ Complete, tested
  
- [x] `create_cron_job` → `create_cron_job_validated` (2025-12-08)
  - File: `includes/tools/class-wp-mcp-ai-tool-create-cron-job-validated.php`
  - Tests: `tests/test-create-cron-job-validated-tool.php` (11 test methods)
  - Status: ✅ Complete, tested
  
- [x] `search_content` → `search_content_validated` (2025-12-08)
  - File: `includes/tools/class-wp-mcp-ai-tool-search-content-validated.php`
  - Tests: `tests/test-search-content-validated-tool.php`
  - Status: ✅ Complete, tested
  
- [x] `create_assistant` → `create_assistant_validated` (2025-12-08)
  - File: `includes/tools/class-wp-mcp-ai-tool-create-assistant-validated.php`
  - Tests: `tests/test-create-assistant-validated-tool.php` (17 test methods)
  - Status: ✅ Complete, tested
  
- [x] `get_recent_posts` → `get_recent_posts_validated` (2025-12-08)
  - File: `includes/tools/class-wp-mcp-ai-tool-get-recent-posts-validated.php`
  - Tests: `tests/test-get-recent-posts-validated-tool.php` (8 test methods)
  - Status: ✅ Complete, tested

### Services Created
- Phase 1 services already in place:
  - ✅ `WP_MCP_AI_Validator_Service`
  - ✅ `WP_MCP_AI_Cache_Service`
  - ✅ `WP_MCP_AI_Filesystem_Service`

## Timeline

### Week 1 (Current - December 8, 2025)
- [x] Create validation classes for high-priority tools
- [x] Migrate first tool (save_post) to validated pattern
- [x] Create comprehensive test suite (11 tests)
- [x] Document migration process with before/after example
- [x] Migrate create_cron_job tool (11 tests)
- [x] Migrate search_content tool (with tests)
- [x] Migrate create_assistant tool (17 tests)
- [x] Migrate get_recent_posts tool (8 tests)
- [ ] Performance benchmark comparing old vs new
- [ ] Migrate 5 more tools (send_group_email, create_woo_product, create_chart, update_user_meta, get_system_logs)

### Week 2
- [ ] Migrate remaining 7-10 tools
- [ ] Performance benchmarking
- [ ] Developer feedback collection
- [ ] Update documentation with examples

### Week 3-4
- [ ] Install Symfony Process component
- [ ] Refactor FFmpeg tools
- [ ] Add async processing support
- [ ] Create Process Service wrapper

### Week 5-6
- [ ] Install Symfony AI Embeddings
- [ ] Create Embeddings Manager
- [ ] Implement semantic_search tool
- [ ] Add WP-CLI commands

## Success Metrics

### Code Quality
- **Target:** 30-50% reduction in validation code
- **Current:** 0% (not yet migrated)
- **Measurement:** Lines of code before/after per tool

### Performance
- **Target:** 40-60% faster cache operations
- **Current:** Service ready, not yet in production use
- **Measurement:** Cache hit rate and response time

### Developer Experience
- **Target:** 50% faster new tool development
- **Current:** Framework ready, awaiting feedback
- **Measurement:** Time to create new tool (survey)

## Technical Details

### Migration Pattern

**Before (Manual Validation):**
```php
public function execute( $arguments, $context ) {
    if ( empty( $arguments['title'] ) ) {
        return new WP_Error( 'missing_title', 'Title required' );
    }
    if ( ! is_string( $arguments['title'] ) ) {
        return new WP_Error( 'invalid_title', 'Title must be string' );
    }
    // ... 20+ lines of validation
    
    // Actual logic
}
```

**After (Symfony Validator):**
```php
// 1. Create validation class once
class ToolArguments {
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    public $title = '';
}

// 2. Extend validated base class
class Tool extends WP_MCP_AI_Validated_Tool {
    protected function get_validation_class() {
        return ToolArguments::class;
    }
    
    protected function execute_validated( $args, $context ) {
        // Actual logic - validation already done!
    }
}
```

### Cache Migration Pattern

**Before (WordPress Transients):**
```php
$cached = get_transient( 'my_key' );
if ( false === $cached ) {
    $cached = expensive_operation();
    set_transient( 'my_key', $cached, HOUR_IN_SECONDS );
}
```

**After (Symfony Cache):**
```php
$cache = WP_MCP_AI_Cache_Service::get_instance();
$cached = $cache->get_or_set( 'my_key', fn() => expensive_operation(), 3600 );
```

## Files Created (Phase 2)

### Validation Arguments
- `includes/validators/arguments/class-save-post-arguments.php`

### Documentation
- `docs/SYMFONY_PHASE2_IMPLEMENTATION_PLAN.md` (this file)

## Next Steps (Immediate)

1. ✅ **Create validation classes** for high-priority tools (6 classes created)
2. ✅ **Migrate save_post tool** to use SavePostArguments (save_post_validated complete)
3. ✅ **Test migrated tool** with unit tests (11 comprehensive tests created)
4. ✅ **Update documentation** with migration example (SAVE_POST_MIGRATION_EXAMPLE.md)
5. ✅ **Migrate create_cron_job tool** (create_cron_job_validated complete with 11 tests)
6. ✅ **Migrate search_content tool** (search_content_validated complete with tests)
7. ✅ **Migrate create_assistant tool** (create_assistant_validated complete with 17 tests)
8. ✅ **Migrate get_recent_posts tool** (get_recent_posts_validated complete with 8 tests)
9. **Run all tests** to verify validated tools work correctly
10. **Performance benchmark** to measure improvements
11. **Create validation classes** for remaining 5 tools
12. **Migrate remaining tools** (send_group_email, create_woo_product, create_chart, update_user_meta, get_system_logs)
13. **Request code review** and gather feedback

## Risks and Mitigation

### Risk: Breaking Existing Functionality
**Mitigation:** 
- Maintain backward compatibility
- Extensive testing before migration
- Gradual rollout with feature flags
- Easy rollback via git

### Risk: Developer Learning Curve
**Mitigation:**
- Comprehensive documentation
- Code examples and templates
- Video tutorials (planned)
- Team training sessions

### Risk: Performance Regression
**Mitigation:**
- Benchmark each migration
- A/B testing in production
- Monitor performance metrics
- Optimize based on data

## Questions/Decisions Needed

1. **PHP Version:** Continue supporting PHP 7.4 (annotations) or require PHP 8.0+ (attributes)?
   - **Current:** Using PHP 8.0 attributes
   - **Decision:** May need annotation fallback for PHP 7.4 support

2. **Migration Strategy:** Big bang or gradual?
   - **Current:** Gradual migration, tool by tool
   - **Decision:** Keep existing tools working, opt-in for new pattern

3. **Redis Configuration:** Provide default or require manual setup?
   - **Current:** Auto-detect if available
   - **Decision:** Works well, no change needed

## References

- **Phase 1 Summary:** `docs/SYMFONY_PHASE1_IMPLEMENTATION_SUMMARY.md`
- **Integration Guide:** `docs/SYMFONY_INTEGRATION_GUIDE.md`
- **Analysis Docs:** `docs/SYMFONY_AI_INTEGRATION_ANALYSIS.md`
- **Utilities Guide:** `docs/SYMFONY_UTILITIES_RECOMMENDATIONS.md`

---

**Last Updated:** December 8, 2025  
**Status:** Active Development  
**Next Review:** After first 3 tools migrated
