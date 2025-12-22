# PHP 7.4 Compatibility - Quick Reference

## TL;DR

**Q: What happens if the plugin is on PHP 7.4?**

**A: The base plugin works fine, but validated tools are disabled.**

## Details

### What Works on PHP 7.4 ✅

- ✅ Plugin activation and deactivation
- ✅ All original (non-validated) tools
- ✅ Symfony Cache service
- ✅ Symfony Filesystem service
- ✅ All existing features and functionality

### What Doesn't Work on PHP 7.4 ❌

- ❌ Validated tools (`*_validated` versions)
- ❌ Tools using Symfony Validator with attributes

### Why?

PHP 8.0 introduced **attributes** (`#[...]` syntax). The validation classes use attributes which cause a **parse error** on PHP 7.4:

```php
// This syntax doesn't exist in PHP 7.4
#[Assert\NotBlank(message: 'Title is required.')]
public $title = '';
```

### The Fix

**Version check added** to prevent fatal errors:

```php
// In WP_MCP_AI_Validated_Tool::execute()
if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
    return new WP_Error(
        'php_version_too_old',
        'This tool requires PHP 8.0+ for validation support. 
         You are running PHP 7.4. Please upgrade PHP.'
    );
}
```

### User Experience

**On PHP 7.4:**
- Plugin works normally
- When calling a validated tool:
  - Returns clear error message
  - Suggests upgrading PHP
  - No fatal errors or crashes

**On PHP 8.0+:**
- All features work
- Validated tools enabled
- Full functionality

## Recommendations

### For Plugin Users

**If you're on PHP 7.4:**
1. Plugin works but validated tools are disabled
2. **Recommended:** Upgrade to PHP 8.0+ to access all features
3. Contact your hosting provider to upgrade PHP

**If you're on PHP 8.0+:**
- ✅ All features work
- ✅ No action needed

### For Plugin Developers

**Current State (This PR):**
- Validated tools check PHP version at runtime
- Graceful error if PHP < 8.0
- Original tools remain available as fallback

**Future (Version 2.0.0):**
- Require PHP 8.0+ globally
- Remove original tool versions
- Use validated tools exclusively

## Migration Path

### Phase 1 (Now)
- ✅ Add PHP version checks
- ✅ Prevent fatal errors
- ✅ Document requirements
- Tools: Both versions available

### Phase 2 (6 months)
- Recommend users upgrade
- Show admin notices for PHP 7.4 users
- Tools: Both versions available

### Phase 3 (Version 2.0.0)
- Require PHP 8.0+
- Remove original tools
- Tools: Validated versions only

## Technical Details

### Files Requiring PHP 8.0+

All validation argument classes use PHP 8 attributes:

1. `includes/validators/arguments/class-save-post-arguments.php`
2. `includes/validators/arguments/class-create-post-arguments.php`
3. `includes/validators/arguments/class-create-assistant-arguments.php`
4. `includes/validators/arguments/class-search-content-arguments.php`
5. `includes/validators/arguments/class-create-cron-job-arguments.php`

### Error Message

When a PHP 7.4 user tries to use a validated tool:

```json
{
  "code": "php_version_too_old",
  "message": "This tool uses Symfony Validator which requires PHP 8.0 or higher for attribute support. You are running PHP 7.4.33. Please upgrade PHP or use the non-validated version of this tool.",
  "data": {
    "required_php": "8.0.0",
    "current_php": "7.4.33",
    "tool_slug": "save_post_validated"
  }
}
```

## FAQ

**Q: Will my site break if I'm on PHP 7.4?**  
A: No. The plugin checks PHP version and returns a clear error instead of crashing.

**Q: Can I still use the plugin on PHP 7.4?**  
A: Yes. All base features work. Only validated tools are disabled.

**Q: Do I need to upgrade PHP?**  
A: Recommended but not required. Upgrade to access validated tools and improved performance.

**Q: What about Symfony Cache and Filesystem?**  
A: Those work fine on PHP 7.4. Only the Validator (using attributes) requires PHP 8.0+.

**Q: When will PHP 8.0+ be required?**  
A: Planned for version 2.0.0 (timeline TBD, likely 6-12 months).

## Related Documentation

- [Full compatibility analysis](../../troubleshooting/common/PHP_VERSION_COMPATIBILITY_ISSUE.md)
- [Symfony Integration Guide](../../implementation-history/2025/implementations/integrations/SYMFONY_INTEGRATION_GUIDE.md)
- [Phase 2 Implementation Plan](../../implementation-history/2025/implementations/symfony-phases/SYMFONY_PHASE2_IMPLEMENTATION_PLAN.md)

---

**Last Updated:** December 8, 2025  
**Status:** Fixed in this PR  
**PHP 7.4 Status:** Supported with limitations  
**PHP 8.0+ Status:** Fully supported
