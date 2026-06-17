# Pro Addon WPCS Compliance - Future Work

**Status:** To Be Addressed  
**Priority:** Enhancement (Non-blocking for WordPress.org submission)  
**Created:** January 30, 2026  
**Related:** Base plugin is 100% WPCS compliant (commit d011c1b)

## Overview

The Pro addon (`addons/pro/`) requires WordPress Coding Standards (WPCS) compliance work. This is separate from the base plugin compliance, as the Pro addon is:
- Excluded from WordPress.org deployment (see `.distignore` line 108)
- A commercial add-on product
- Not required for WordPress.org submission

## Affected Files

The following files in `addons/pro/includes/` need WPCS review and fixes:

### Admin Pages (28 files)
- `admin/class-wp-mcp-ai-architectural-drawing-research-page.php`
- `admin/class-wp-mcp-ai-architectural-project-research-page.php`
- `admin/class-wp-mcp-ai-architectural-specification-research-page.php`
- `admin/class-wp-mcp-ai-consolidate-add-base.php`
- `admin/class-wp-mcp-ai-eca-research-page.php`
- `admin/class-wp-mcp-ai-event-consolidate-page.php`
- `admin/class-wp-mcp-ai-event-research-page.php`
- `admin/class-wp-mcp-ai-health-records-consolidate-page.php`
- `admin/class-wp-mcp-ai-media-consolidate-page.php`
- `admin/class-wp-mcp-ai-media-design-page.php`
- `admin/class-wp-mcp-ai-orchestration-dashboard.php`
- `admin/class-wp-mcp-ai-page-research-page.php`
- `admin/class-wp-mcp-ai-password-vault-admin.php`
- `admin/class-wp-mcp-ai-place-research-page.php`
- `admin/class-wp-mcp-ai-policy-research-page.php`
- `admin/class-wp-mcp-ai-post-research-page.php`
- `admin/class-wp-mcp-ai-product-consolidate-page.php`
- `admin/class-wp-mcp-ai-product-research-page.php`
- `admin/class-wp-mcp-ai-project-research-page.php`
- `admin/class-wp-mcp-ai-quiz-research-page.php`
- `admin/class-wp-mcp-ai-reg-document-page.php`
- `admin/class-wp-mcp-ai-reg-document-research-page.php`
- `admin/class-wp-mcp-ai-reg-migration-page.php`
- `admin/class-wp-mcp-ai-reg-product-research-page.php`
- `admin/class-wp-mcp-ai-registration-dashboard-page.php`
- `admin/class-wp-mcp-ai-registration-research-page.php`
- `admin/class-wp-mcp-ai-regulatory-registration-toolkit-settings-page.php`
- `admin/class-wp-mcp-ai-remote-sites-admin.php`

### Other Components
- Additional files in `addons/pro/includes/` subdirectories (tools, services, etc.)

## Prerequisites

To perform WPCS compliance work on Pro addon:

1. **Dev Dependencies Required:**
   ```bash
   composer install  # Includes dev dependencies
   ```

2. **PHPCS Must Be Available:**
   ```bash
   vendor/bin/phpcs --version
   ```

3. **WordPress Coding Standards:**
   - Already configured in `phpcs.xml.dist`
   - Uses WordPress-Core, WordPress-Docs, WordPress-Extra rulesets

## Recommended Approach

### Step 1: Run PHPCS Scan
```bash
# Scan Pro addon files
vendor/bin/phpcs --standard=WordPress --report=summary addons/pro/includes/

# Get detailed report
vendor/bin/phpcs --standard=WordPress --report=full addons/pro/includes/ > pro-wpcs-errors.txt
```

### Step 2: Fix Common Issues

Based on base plugin WPCS work, expect these common issues:

1. **Missing Translator Comments**
   - Add `/* translators: %s placeholder description */` above translation functions with placeholders

2. **Nonce Verification**
   - Add `phpcs:ignore` with justification for methods called after nonce verification
   - Or refactor to verify nonce in each method

3. **Output Escaping**
   - Ensure all output is properly escaped with `esc_html()`, `esc_attr()`, etc.
   - Add justified `phpcs:ignore` for intentional HTML rendering

4. **Yoda Conditions**
   - Convert `$var === 'value'` to `'value' === $var`

5. **Short Ternaries**
   - Replace `$var ?: 'default'` with `$var ? $var : 'default'`

6. **Date Functions**
   - Replace `date()` with `gmdate()` for timezone safety

7. **PHPDoc Completeness**
   - Add missing `@param`, `@return`, `@throws` tags

### Step 3: Auto-Fix Where Possible
```bash
# Auto-fix formatting issues
vendor/bin/phpcbf --standard=WordPress addons/pro/includes/
```

### Step 4: Manual Fixes
- Address remaining errors that require manual intervention
- Add appropriate `phpcs:ignore` comments with justification
- Document any acceptable warnings

## Expected Timeline

- **Estimated Effort:** 4-8 hours
- **Files to Review:** ~30-50 PHP files
- **Similar to:** Base plugin work (93 errors across 29 files - commit d011c1b)

## Verification

After fixes are complete:

```bash
# Verify 0 errors
vendor/bin/phpcs --standard=WordPress addons/pro/includes/

# Generate summary report
vendor/bin/phpcs --standard=WordPress --report=summary addons/pro/includes/
```

## Notes

- **Base Plugin Status:** ✅ 100% WPCS compliant (0 errors, 4 acceptable warnings)
- **Pro Addon Status:** ⏳ Pending dev environment setup
- **WordPress.org Impact:** None (Pro addon excluded from submission)
- **Commercial Product:** WPCS compliance improves code quality but not required for functionality

## Related Documents

- `/docs/WORDPRESS_ORG_COMPLIANCE_CERTIFICATION.md` - Base plugin compliance certification
- `/.distignore` - Excludes `addons/` from WordPress.org deployment
- `/phpcs.xml.dist` - PHPCS configuration

## Action Items

- [ ] Set up development environment with composer dev dependencies
- [ ] Run PHPCS scan on Pro addon files
- [ ] Create detailed error report
- [ ] Fix all WPCS errors following base plugin patterns
- [ ] Verify 0 errors remaining
- [ ] Update this document with completion status
- [ ] Consider creating separate PR for Pro addon WPCS work

---

**Created:** January 30, 2026  
**Base Plugin Compliance:** Commit d011c1b (100% WPCS compliant)  
**Issue Type:** Enhancement - Code Quality Improvement
