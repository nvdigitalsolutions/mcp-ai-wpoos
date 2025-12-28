# Fixes Documentation

This directory contains detailed documentation for bug fixes and issue resolutions in the WP oOS plugin.

## Professional Selector Model Loading Fix

**Issue Date**: December 2024  
**Status**: ✅ Fixed  
**Severity**: High (Feature broken for logged-in users)

### Quick Links
- [Detailed Fix Documentation](professional-selector-model-loading-fix.md)
- [Visual Flow Diagrams](professional-selector-flow-diagram.md)

### Summary
Fixed a 403 Forbidden error that prevented logged-in users from loading AI model lists in the professional selector widget. The issue was caused by missing WordPress AJAX hook registration for logged-in users, causing requests to be routed to the wrong handler with mismatched nonce validation.

### Solution
Added one line of code to register the `wp_ajax` hook, routing logged-in users to the correct handler that accepts the professional selector's nonce.

### Files Changed
1. `includes/class-wp-mcp-ai-professional-selector-shortcode.php` (1 line added)
2. `tests/test-professional-selector-model-loading.php` (330 lines - new test file)
3. Documentation files (this directory)

### Impact
- ✅ Fixed for logged-in users
- ✅ No regression for guest users
- ✅ Works in Elementor widgets
- ✅ Works with shortcodes
- ✅ Minimal code change (surgical fix)

---

## Documentation Format

Each fix in this directory should include:

1. **Problem Description**: What was broken and how it manifested
2. **Root Cause Analysis**: Why it was broken
3. **Solution**: What was changed to fix it
4. **Files Changed**: List of modified/created files
5. **Testing**: How to verify the fix
6. **Impact Assessment**: Who/what is affected

---

## Contributing

When documenting new fixes:
1. Create a new markdown file named after the issue (e.g., `issue-name-fix.md`)
2. Include code examples showing before/after
3. Add visual diagrams if helpful
4. Document all testing performed
5. List all files changed with line numbers
6. Update this README with a summary

---

*Last Updated: December 28, 2024*
