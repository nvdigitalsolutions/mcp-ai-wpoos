# Remaining Code Quality Issues

This document tracks code quality issues that need manual review or cannot be automatically fixed.

**Last Updated:** 2025-11-30 (Re-checked for task status review)

## Variable Naming Convention Issues

### External Library Properties
Some errors are from external library object properties (e.g., DOMNode, plugin headers) that cannot be renamed as they come from WordPress core or PHP extensions:

**Files Affected:**
- `includes/tools/class-wp-mcp-ai-tool-get-update-status.php` (lines 137, 140, 164) - WordPress plugin header properties (`$plugin->Name`, `$plugin->Version`)
- `includes/tools/class-wp-mcp-ai-tool-get-site-health.php` (line 452) - DOM API property (`$anchor->textContent`)
- `includes/class-wp-mcp-ai-rest.php` (line 4716) - DOM API property (`$reader->nodeType`)

**Status:** ⚠️ **STILL AN ISSUE**

**Action Required:** Add phpcs:ignore comments with explanations for external API requirements. These properties cannot be renamed as they come from WordPress core or PHP DOM extensions.

### Internal Variable Naming
**Status:** ✅ **RESOLVED**

All internal camelCase variables in the following files have been fixed:
- ~~`bin/check-plugin-environment.php`~~ - Variables now use snake_case (`$min_php_version`, `$current_php_version`, `$plugin_file`, `$plugin_contents`)
- ~~`includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php`~~ - Variables follow WordPress naming conventions

## Missing @package Tags

**Status:** ✅ **RESOLVED**

All files now have the required `@package WP_MCP_AI` tag in their docblocks.

Files that have been **fixed:**
- ~~`includes/class-assistant-cpt.php`~~ - ✅ Has @package tag
- ~~`includes/class-rest-endpoints.php`~~ - ✅ Has @package tag
- ~~`includes/class-admin-settings.php`~~ - ✅ Has @package tag
- ~~`includes/class-openai-client.php`~~ - ✅ Has @package tag
- ~~`includes/class-tool-registry.php`~~ - ✅ Has @package tag
- ~~`tests/bootstrap.php`~~ - ✅ Has @package tag
- ~~`tests/wp-tests-config.php`~~ - ✅ Has @package tag
- ~~`tests/test-jetengine-assistants-cct.php`~~ - ✅ Has @package tag (added 2025-11-30)
- ~~`includes/tools-init.php`~~ - ✅ Has @package tag
- ~~`includes/tools/tools-init.php`~~ - ✅ Has @package tag
- ~~`bin/check-plugin-environment.php`~~ - ✅ Has @package tag

## Missing Parameter Documentation

**Status:** ⚠️ **STILL AN ISSUE**

Multiple tool `execute()` methods are missing `@param` documentation for:
- `$arguments` parameter (array)
- `$context` parameter (array)

This affects approximately **60 files** (120 missing @param instances) and should be standardized across all tool implementations.

**Example files affected:**
- Various tool classes in `includes/tools/` directory
- `includes/class-wp-mcp-ai-jetengine-tool-handlers.php` (also missing `$path_params`, `$requires_id`)

**Action Required:** Add proper @param documentation to all execute() methods following WordPress documentation standards.

## Suppressed Warnings

### File Operations
**Status:** ✅ **PROPERLY HANDLED**

- `file_get_contents()` usage in `bin/check-plugin-environment.php` (line 25) - Now has phpcs:ignore comment with proper explanation: "CLI context, not web request"
- `file()` usage in `bin/check-plugin-environment.php` (line 94) - Now has phpcs:ignore comment explaining intentional graceful error handling

### Error Suppression
**Status:** ✅ **RESOLVED**

- ~~`@file()` usage~~ - No longer uses error suppression operator (@). The code now uses phpcs:ignore comments instead of suppressing errors with @.

## WordPress Coding Standards Exceptions

Some violations are intentional or required by external APIs:

1. **Namespace curly brace syntax** - Used in integration files for PHP 7.4+ compatibility
2. **Object properties** - External API properties can't be renamed (WordPress plugin headers, PHP DOM API)
3. **Unused parameters** - Interface implementations may have unused parameters (e.g., `$request` in integration classes)
4. **Slow query warnings** - Some use of `meta_key` and `meta_value` in queries are intentional for specific functionality
5. **File operations in CLI context** - Direct file operations in `bin/` scripts are appropriate (not web requests)

## Summary of Current Issues

### High Priority (Remaining)
- [ ] Add phpcs:ignore comments for external API properties in 3 files (30-40 minutes)

### Medium Priority (Remaining)
- [ ] Add missing parameter documentation to ~60 files with execute() methods (4-6 hours)
- [ ] Add missing file docblocks to test files (30 minutes)

### Low Priority
- [ ] Create coding standards documentation (2-3 hours)
- [ ] Set up automated checks in CI/CD (1-2 hours)
- [ ] Review and address slow query warnings (1-2 hours)

### Completed ✅
- [x] Fix variable naming in bin/check-plugin-environment.php
- [x] Add @package tags to includes/tools-init.php and includes/tools/tools-init.php
- [x] Replace @file() error suppression with proper phpcs:ignore comments
- [x] Document file operations in bin/check-plugin-environment.php
- [x] Add @package tags to includes/class-assistant-cpt.php
- [x] Add @package tags to includes/class-rest-endpoints.php
- [x] Add @package tags to includes/class-admin-settings.php
- [x] Add @package tags to includes/class-openai-client.php
- [x] Add @package tags to includes/class-tool-registry.php
- [x] Add @package tags to tests/bootstrap.php
- [x] Add @package tags to tests/wp-tests-config.php
- [x] Add @package tags to tests/test-jetengine-assistants-cct.php

## Recommendations

1. **Add phpcs:ignore selectively** for external API properties with explanation in:
   - `includes/tools/class-wp-mcp-ai-tool-get-update-status.php` (lines 137, 140, 164)
   - `includes/tools/class-wp-mcp-ai-tool-get-site-health.php` (line 452)
   - `includes/class-wp-mcp-ai-rest.php` (line 4716)

2. ~~**Refactor internal variables** to use snake_case consistently~~ ✅ COMPLETED

3. ~~**Add missing @package tags** to remaining files~~ ✅ COMPLETED

4. **Document parameters** in all tool execute() methods following this pattern:
   ```php
   /**
    * Execute the tool.
    *
    * @param array $arguments Tool arguments.
    * @param array $context   Execution context including user_id.
    * @return array|WP_Error Tool results or error.
    */
   public function execute( array $arguments = array(), array $context = array() ) {
   ```

5. **Create coding standards guide** documenting exceptions and patterns for WordPress/PHP external APIs

## Notes

- Many issues mentioned in the original document have been resolved through recent code improvements
- The remaining issues are mostly documentation-related (missing @package tags and @param docs)
- External API property naming issues are legitimate exceptions that should be documented with phpcs:ignore comments
- Test files have many linting issues but these are lower priority as they don't affect production code
