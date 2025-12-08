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
- [ ] `CreateAssistantArguments` - For create_assistant tool
- [ ] `SendGroupEmailArguments` - For send_group_email tool

### Tools Migrated
- [ ] None yet (validation classes being created first)

### Services Created
- Phase 1 services already in place:
  - ✅ `WP_MCP_AI_Validator_Service`
  - ✅ `WP_MCP_AI_Cache_Service`
  - ✅ `WP_MCP_AI_Filesystem_Service`

## Timeline

### Week 1 (Current)
- [x] Create validation classes for 5 high-priority tools
- [ ] Migrate first 3 tools to validated pattern
- [ ] Update tests for migrated tools
- [ ] Document migration process

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

1. **Create validation classes** for remaining 4 target tools
2. **Migrate save_post tool** to use SavePostArguments
3. **Test migrated tool** with unit tests
4. **Update documentation** with migration example
5. **Commit progress** and gather feedback

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
