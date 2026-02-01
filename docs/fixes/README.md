# Fixes Documentation

This directory contains detailed documentation for bug fixes and issue resolutions in the NV oOS plugin.

## Tool Preset Multiplier Application Fix

**Issue Date**: January 18, 2026  
**Status**: ✅ Fixed (PR #2990)  
**Severity**: High (Core functionality broken for token management)

### Quick Links
- [Tool Preset Multiplier Fix](TOOL_PRESET_MULTIPLIER_FIX.md) - Detailed fix documentation
- [Testing Plan](TOOL_PRESET_MULTIPLIER_TESTING_PLAN.md) - Comprehensive manual testing procedures

### Summary
Fixed a critical issue where the "Apply Preset" button on the Token Manager page was not working. When users selected a preset (Conservative, Balanced, Performance, or Aggressive) and clicked "Apply Preset", nothing happened - no tool multipliers were updated.

**Root Cause**: The `get_all_recommendations()` method only queried the tool registry, which returned an empty array during preset application, causing 0 tools to be processed.

### Solution
Modified `get_all_recommendations()` to iterate through the `$tool_categories` static property first (containing all 200+ defined tools), then check the registry as a fallback for dynamically registered tools.

### Files Changed
1. `includes/class-wp-mcp-ai-tool-recommendations.php` - Refactored into helper methods
   - Added `process_tools_from_categories()` private method
   - Added `add_tools_from_registry()` private method
   - Modified `get_all_recommendations()` to use both sources
2. Documentation files (this directory)

### Impact
- ✅ Preset application now works correctly for all 200+ tools
- ✅ Maintains backward compatibility with dynamically registered tools
- ✅ Better code organization with extracted private methods
- ✅ No security vulnerabilities introduced
- ✅ Improved maintainability and code clarity

---

## Chart Rendering and Display Fixes

**Issue Date**: December 2024  
**Status**: ✅ Fixed  
**Severity**: Medium (Charts not displaying properly in chat UI)

### Quick Links
- [Get Open Meteo Forecast Fix](GET_OPEN_METEO_FIX.md) - Weather forecast chart iframe rendering
- [Before/After Comparison](BEFORE_AFTER_COMPARISON.md) - Visual comparison of the fix
- [Chart Bubble Width Fix](CHART_BUBBLE_WIDTH_FIX.md) - CSS fix for chart bubble width
- [Chart Width Fix Summary](CHART_WIDTH_FIX_SUMMARY.md) - Summary of width-related fixes
- [Chart Fix Testing Guide](CHART_FIX_TESTING.md) - Comprehensive testing procedures

### Summary
Fixed multiple issues related to chart rendering in the chat UI:
1. **Weather forecast charts not rendering**: The `get_open_meteo_forecast` tool was including a redundant `data` field that caused response truncation, preventing charts from displaying as iframes.
2. **Chart bubbles too narrow**: Chart bubbles were constrained by default message width (80%), making charts appear cramped on larger screens.
3. **3x3 pixel canvas bug**: Charts were displaying with canvas dimensions of 3x3 pixels instead of the intended dimensions due to Chart.js responsive mode.

### Solution
1. Removed the `data` field from chart output responses (already embedded in HTML)
2. Updated CSS to make chart bubbles full width (minimum 600px or 100% on smaller screens)
3. Set Chart.js options `responsive: false` and `maintainAspectRatio: false` as defaults

### Files Changed
1. `includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php` - Removed redundant data field
2. `includes/tools/class-wp-mcp-ai-tool-create-chart.php` - Fixed responsive options
3. `assets/css/chat.css` - Chart bubble width CSS improvements
4. `assets/js/chat.js` - Debug logging and tool message restoration
5. `tests/test-open-meteo-forecast-tool.php` - Updated test expectations
6. `tests/test-tool-create-chart.php` - New responsive options tests

### Impact
- ✅ Weather forecast charts now render as interactive iframes
- ✅ Charts display at optimal width for readability
- ✅ Charts maintain proper dimensions (not 3x3 pixels)
- ✅ Works across all templates (default, compact, sidebar)
- ✅ Responsive on mobile devices

---

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

## Remote Connection Tool Fixes

**Issue Date**: January 2026  
**Status**: ✅ Fixed  
**Severity**: High (Remote WordPress/WooCommerce connections broken)

### Quick Links
- [Remote Connection Fix Summary](REMOTE_CONNECTION_FIX_SUMMARY.md) - Edit/delete connection ID case sensitivity fix
- [Remote Connection Filter Fix](REMOTE_CONNECTION_FILTER_FIX.md) - Tool filtering by assistant's enabled connections
- [Remote Connection Schema Fix](REMOTE_CONNECTION_SCHEMA_FIX.md) - OpenAI function calling compatibility
- [Remote Connection Workflow Fix](REMOTE_CONNECTION_WORKFLOW_FIX.md) - AI workflow guidance improvements

### Summary
Fixed multiple issues with the `remote_wp_connection` tool that prevented proper editing, deleting, and usage of remote site connections:

1. **Edit/Delete broken**: Connection IDs were case-sensitive but `sanitize_key()` lowercased them, causing lookup failures
2. **Filter not working**: Tool showed ALL connections instead of only those enabled for the specific assistant
3. **OpenAI incompatibility**: Tool schema used `oneOf` which OpenAI doesn't support at root level
4. **Workflow confusion**: AI wasn't including `connection_id` parameter even when user intent was clear

### Solution
1. Normalize connection IDs to lowercase during generation and storage (preventing case mismatches)
2. Filter connections by assistant's `_wp_mcp_ai_pro_remote_connections` post meta
3. Remove `oneOf` from root schema, make `connection_id` always present in schema but contextually required
4. Enhanced descriptions and self-healing error messages that include available connections list

### Impact
- ✅ Remote connections can now be edited and deleted
- ✅ Assistants only see their enabled connections
- ✅ Works with OpenAI, Gemini, and Ollama providers
- ✅ Better AI understanding of required workflow
- ✅ Improved error messages guide users to resolution

---

## Gmail OAuth Integration Fix

**Issue Date**: December 2024  
**Status**: ✅ Fixed  
**Severity**: High (OAuth connections completely broken)

### Quick Links
- [Gmail OAuth Fix Summary](gmail-oauth-fix-summary.md) - Complete fix documentation
- [Google OAuth Setup Guide](../getting-started/installation-setup/google-oauth-setup.md) - Setup instructions
- [OAuth Settings Architecture](../architecture/integrations/oauth-settings-architecture.md) - Technical architecture

### Summary
Fixed a critical issue where the Gmail OAuth "Connect" button returned a 400 Bad Request error. The problem was that the `WP_MCP_AI_Admin_Settings` class was never being instantiated during plugin initialization, so WordPress action hooks for OAuth flows were never registered.

### Solution
1. Added `admin.settings` service registration to the DI container
2. Initialized the service during plugin bootstrap to ensure OAuth hooks are registered
3. Created comprehensive setup guide for Google Cloud Console configuration

### Files Changed
1. `includes/class-wp-mcp-ai-container.php` - Added admin.settings service registration
2. `mcp-ai-wpoos.php` - Initialize admin.settings on plugin load
3. Documentation files (setup guide, architecture, fix summary)

### Impact
- ✅ Gmail OAuth connection flow now works
- ✅ Meta (Facebook, Instagram, WhatsApp) OAuth now works
- ✅ QuickBooks OAuth now works
- ✅ Mailjet OAuth now works
- ✅ All OAuth integrations fixed with single change
- ✅ Backward compatible with no breaking changes

---

## Vectorizer Tool Fixes

**Issue Date**: January 2026  
**Status**: ✅ Fixed  
**Severity**: Critical (Tool completely broken in production)

### Quick Links
- [Vectorizer Fix Summary](VECTORIZER_FIX_SUMMARY.md) - Production deployment fix for missing native modules
- [Vectorize Image Fix Test Plan](VECTORIZE_IMAGE_FIX_TEST_PLAN.md) - SSE streaming response fix

### Summary
Fixed critical issues with the `vectorize_image` tool:

1. **Production failure**: Tool failed on cloned repos without `node_modules` because `@neplex/vectorizer` native modules weren't available
2. **SSE streaming**: Tool responses weren't being returned to chat client in Server-Sent Events mode

### Solution
1. Vendor the `@neplex/vectorizer` package into `assets/js/vendor/neplex-vectorizer/` following Chart.js pattern
2. Copy native `.node` files to vendor directory via postinstall script
3. Add exception handling and data normalization for SSE streaming
4. Implement fallback URL generation for SVG attachments

### Files Changed
- `bin/copy-vectorizer-to-vendor.sh` - Vendor copy script
- `assets/js/vendor/neplex-vectorizer/` - Vendored package with native modules
- `includes/tools/class-wp-mcp-ai-tool-vectorize-image.php` - Load from vendor, add error handling
- `package.json` - Postinstall script hook
- Various SSE and error handling improvements

### Impact
- ✅ Tool works in production without requiring `npm install`
- ✅ Native modules properly loaded from vendor directory
- ✅ SSE streaming responses work correctly
- ✅ Better error messages and debugging

---

## Project Management Assistant Modal Fixes (2025)

**Issue Date**: 2025  
**Status**: ✅ Fixed  
**Severity**: Medium (Modal functionality issues)

### Quick Links
- [PM AI Assistant Modal Fix](PM_AI_ASSISTANT_MODAL_FIX.md) - Modal functionality fix
- [PM Assistant Button Fix Summary](PM_ASSISTANT_BUTTON_FIX_SUMMARY.md) - Button interaction fix
- [Fix PM AI Modal Buttons](FIX_PM_AI_MODAL_BUTTONS.md) - Modal button fix details
- [Fix Summary PM AI Buttons](FIX_SUMMARY_PM_AI_BUTTONS.md) - Button fix summary
- [Fix Summary PM Modal](FIX_SUMMARY_PM_MODAL.md) - Modal fix summary
- [Fix Summary](FIX_SUMMARY.md) - General fix summary
- [PR Summary](../archive/PR_SUMMARY.md) - Pull request summary (archived)
- [PR Summary PM AI Modal Fix](PR_SUMMARY_PM_AI_MODAL_FIX.md) - Modal fix PR summary

### Summary
Fixed various issues with the Project Management Assistant modal functionality including:
- Modal button interactions
- Form submission handling
- UI/UX improvements

### Impact
- ✅ PM Assistant modal now functions correctly
- ✅ Buttons respond properly to user interactions
- ✅ Form submissions work as expected

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

*Last Updated: January 2, 2026*
