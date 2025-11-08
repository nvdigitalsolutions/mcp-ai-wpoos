# WP oOS Refactoring: Baseline Metrics

**Date Established:** November 8, 2025
**Purpose:** Track code metrics before and after refactoring to ensure no functionality is lost

## Current State (Before Refactoring)

### Overall Plugin Metrics

| Metric | Count |
|--------|-------|
| Total PHP Files | 333 |
| Total Lines of Code | 138,188 |
| Total Classes | 270 |
| Global Functions | 6 |
| Public Methods | 2,319 |
| Protected Methods | 765 |
| Private Methods | 76 |
| Total Methods | 3,160 |

### WordPress Integration

| Metric | Count |
|--------|-------|
| add_action calls | 128 |
| add_filter calls | 298 |
| REST route registrations | 30 |
| Global wp_mcp_ai_* functions | 4 |

### Three Main Classes (Focus of Refactoring)

#### 1. WP_MCP_AI_REST
**File:** `includes/class-wp-mcp-ai-rest.php`

| Metric | Count |
|--------|-------|
| Lines of Code | 8,066 |
| Public Methods | 24 |
| Protected Methods | 98 |
| Private Methods | 1 |
| **Total Methods** | **123** |

**Responsibilities:**
- REST API route registration (30 routes)
- Authentication (nonces, tokens, mesh keys, Auth0, guest tokens)
- Request validation and sanitization
- Permission checking
- Chat message handling and SSE streaming
- Assistant management endpoints
- File upload/download handling
- Tool execution
- Memory/attachment processing
- Rate limiting
- Token budget management

#### 2. WP_MCP_AI_Admin_Settings
**File:** `includes/admin/class-wp-mcp-ai-admin-settings.php`

| Metric | Count |
|--------|-------|
| Lines of Code | 6,753 |
| Public Methods | 109 |
| Protected Methods | 15 |
| Private Methods | 15 |
| **Total Methods** | **139** |

**Responsibilities:**
- Settings page registration
- Settings field registration
- 88+ UI rendering methods for different sections
- AJAX handlers (Ollama, LM Studio, Cloudflare, token management, etc.)
- OAuth flows (Gmail integration)
- Settings validation and sanitization
- Database operations
- Admin notices
- Tool limit management

#### 3. WP_MCP_AI_Assistant_CPT
**File:** `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`

| Metric | Count |
|--------|-------|
| Lines of Code | 3,800 |
| Public Methods | 14 |
| Protected Methods | 10 |
| Private Methods | 0 |
| **Total Methods** | **24** |

**Responsibilities:**
- Custom Post Type registration
- Metabox rendering (credentials, capabilities, settings, defaults)
- Credential management
- Assistant capability management
- Assistant-specific settings
- Default assistant handling
- Post list customization

## Refactoring Goals

### Line Reduction Targets
| Class | Current | Target | Reduction |
|-------|---------|--------|-----------|
| WP_MCP_AI_REST | 8,066 | ~6,000 | 25% |
| WP_MCP_AI_Admin_Settings | 6,753 | ~3,000 | 55% |
| WP_MCP_AI_Assistant_CPT | 3,800 | ~2,000 | 47% |
| **Total** | **18,619** | **~11,000** | **41%** |

### New Classes to Create
Estimated 29 new classes will be created through extraction:

| Layer | New Classes | Purpose |
|-------|-------------|---------|
| REST Authentication | 1 | Authentication logic |
| REST Validation | 1 | Request validation |
| REST SSE | 1 | Server-Sent Events streaming |
| Admin UI Sections | 8 | Settings section renderers |
| Admin AJAX | 4 | Specialized AJAX handlers |
| Admin OAuth | 1 | OAuth integration |
| Assistant Metaboxes | 4 | Metabox renderers |
| Services | 5 | Business logic layer |
| Repositories | 4 | Data access layer |
| **Total** | **29** | |

### Expected Final State
| Metric | Before | After | Change |
|--------|--------|-------|--------|
| Total Classes | 270 | ~299 | +29 |
| Total Methods | 3,160 | ~3,160 | 0 (redistributed) |
| Public Methods | 2,319 | ~2,319 | 0 (maintained) |
| Avg Lines/Class (main 3) | 6,206 | ~3,667 | -41% |
| Avg Methods/Class (main 3) | 95 | ~45 | -53% |

## Verification Procedure

### Before Refactoring
```bash
cd /home/runner/work/wp-mcp-ai/wp-mcp-ai
bash bin/code-inventory.sh > BASELINE-INVENTORY.txt
```

### After Each Milestone
```bash
bash bin/verify-refactoring.sh
```

### Verification Checks
The verification script will ensure:

✅ **No public methods removed** - All public APIs maintained
✅ **No classes removed** - Only additions and reorganization
✅ **No global functions removed** - Plugin functions preserved
✅ **No REST routes removed** - All endpoints maintained
✅ **WordPress hooks unchanged** - Same number of add_action/add_filter calls
✅ **Method count preserved** - Methods move but aren't deleted

### Critical Invariants

These must remain constant or increase:

| Invariant | Baseline | Must Be |
|-----------|----------|---------|
| Global Functions | 6 | ≥ 6 |
| Public Methods | 2,319 | = 2,319 |
| REST Routes | 30 | ≥ 30 |
| add_action calls | 128 | ≥ 128 |
| add_filter calls | 298 | ≥ 298 |

These can increase:

| Metric | Baseline | Expected |
|--------|----------|----------|
| Classes | 270 | ~299 |
| PHP Files | 333 | ~362 |

These should decrease in main classes:

| Metric | Baseline | Target |
|--------|----------|--------|
| Lines in 3 main classes | 18,619 | ~11,000 |
| Methods in 3 main classes | 286 | ~139 |

## Automated CI Verification

GitHub Actions workflow will run on refactoring branches:

```yaml
- name: Verify Refactoring Integrity
  run: |
    if [[ $GITHUB_REF == *"refactor"* ]]; then
      bash bin/verify-refactoring.sh
      exit_code=$?
      if [ $exit_code -ne 0 ]; then
        echo "❌ Refactoring verification failed!"
        exit 1
      fi
    fi
```

## Manual Verification Checklist

After refactoring is complete, manually verify:

- [ ] All REST API endpoints still work
- [ ] Admin settings page renders correctly
- [ ] All settings sections display properly
- [ ] AJAX handlers work (test Ollama, LM Studio connections)
- [ ] OAuth flows work (Gmail integration)
- [ ] Assistant CPT creates/edits correctly
- [ ] All metaboxes render and save
- [ ] Credentials system functions
- [ ] Chat API works with all authentication methods
- [ ] SSE streaming works
- [ ] Tool execution works
- [ ] File uploads/downloads work
- [ ] All existing tests pass
- [ ] No new PHP errors or warnings
- [ ] Performance is maintained (no degradation)

## Rollback Plan

If verification fails:

1. **Review the failure** - Check which invariants were violated
2. **Attempt fix** - If minor, fix the extracted code
3. **Rollback if needed** - Revert to previous commit
4. **Use feature flags** - If deployed, use constants to disable new code:
   ```php
   define('WP_MCP_AI_USE_NEW_AUTH', false);
   ```

## Documentation

Baseline inventory stored in:
- `BASELINE-INVENTORY.txt` - Full baseline report
- This file (`BASELINE-METRICS.md`) - Summary metrics

Verification results will be stored in:
- `CURRENT-INVENTORY.txt` - Current state (regenerated each check)
- `VERIFICATION-REPORT.txt` - Latest verification output

## Conclusion

This baseline establishes the current state of the codebase. All refactoring must maintain or improve these metrics while preserving functionality. The automated verification scripts will catch any unintended removals or regressions.

**Key Success Criteria:**
1. ✅ No public methods removed
2. ✅ No REST endpoints removed  
3. ✅ No WordPress hooks removed
4. ✅ All tests passing
5. ✅ Main classes reduced in size by 40%+
6. ✅ Better separation of concerns
7. ✅ Improved testability
