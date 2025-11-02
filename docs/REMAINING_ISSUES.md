# Remaining Code Quality Issues

This document tracks code quality issues that need manual review or cannot be automatically fixed.

## Variable Naming Convention Issues

### External Library Properties
Some errors are from external library object properties (e.g., DOMNode, plugin headers) that cannot be renamed as they come from WordPress core or PHP extensions:

**Examples:**
- `$plugin_object->Name` - WordPress plugin header property
- `$plugin_object->Version` - WordPress plugin header property  
- `$domNode->wholeText` - DOM API property
- `$domElement->tagName` - DOM API property

**Action:** These can be suppressed with phpcs:ignore comments as they're external API requirements.

### Internal Variable Naming
Variables that should be converted from camelCase to snake_case:

**bin/check-plugin-environment.php:**
- Line 7: `$minPhpVersion` → `$min_php_version`
- Line 11: `$currentPhpVersion` → `$current_php_version`
- Line 18-20: `$pluginFile` → `$plugin_file`
- Line 24-25: `$pluginContents` → `$plugin_contents`

**includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php:**
Multiple instances of camelCase variables that should follow WordPress naming conventions.

## Missing @package Tags

Files that need @package tag added to file docblock:

1. includes/tools-init.php
2. includes/tools/tools-init.php
3. bin/check-plugin-environment.php
4. Various tool files (check with linter)

## Missing Parameter Documentation

Multiple tool execute() methods are missing @param documentation for:
- `$arguments` parameter
- `$context` parameter

This should be standardized across all tool classes.

## Suppressed Warnings

### File Operations
- `file_get_contents()` usage in bin/check-plugin-environment.php (line 24)
  - Warning suggests using `wp_remote_get()` but this is a CLI script, not web context
  - Consider documenting why direct file access is appropriate here

### Error Suppression
- `@file()` usage should be replaced with proper error checking:
  ```php
  // Instead of:
  $lines = @file( $path );
  
  // Use:
  if ( file_exists( $path ) && is_readable( $path ) ) {
      $lines = file( $path );
  } else {
      // Handle error
  }
  ```

## WordPress Coding Standards Exceptions

Some violations are intentional or required by external APIs:

1. **Namespace curly brace syntax** - Used in integration files for PHP 7.4+ compatibility
2. **Object properties** - External API properties can't be renamed
3. **Unused parameters** - Interface implementations may have unused parameters

## Recommendations

1. **Add phpcs:ignore selectively** for external API properties with explanation
2. **Refactor internal variables** to use snake_case consistently
3. **Add missing @package tags** to all files
4. **Document parameters** in all tool execute() methods
5. **Create coding standards guide** documenting exceptions and patterns

## Priority Actions

High Priority:
- [ ] Add @package tag to main files (10 minutes)
- [ ] Fix variable naming in bin/check-plugin-environment.php (15 minutes)
- [ ] Add phpcs:ignore comments with explanations for external properties (30 minutes)

Medium Priority:
- [ ] Add missing parameter documentation (1-2 hours)
- [ ] Review and refactor variable naming in tool files (2-3 hours)

Low Priority:
- [ ] Create coding standards documentation (2-3 hours)
- [ ] Set up automated checks in CI/CD (1-2 hours)
