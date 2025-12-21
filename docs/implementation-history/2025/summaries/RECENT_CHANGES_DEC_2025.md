# Recent Changes - December 2025

This document consolidates all significant changes made to Open Operator System during December 2025.

**Document Created:** December 1, 2025  
**Covers Period:** December 1-31, 2025

---

## Major Features Added

### Token Management UI (PR #1871)
**Added:** December 1, 2025

A centralized UI for managing all external agent access tokens across assistants.

**Location:** `WP oOS → Token Manager` admin menu

**Features:**
- Centralized token lifecycle management (create, view, revoke, delete)
- Security best practice: Show token only once after creation
- Clear listing with metadata (creation date, status, associated assistant)
- Audit trail (who created/revoked tokens)
- Bulk visibility across all assistants
- Industry-standard approach (similar to GitHub, Stripe, Auth0)

**Files Added:**
- `includes/admin/class-wp-mcp-ai-admin-token-manager.php`
- `includes/admin/sections/class-wp-mcp-ai-section-token-manager.php`
- `includes/rest/class-wp-mcp-ai-rest-token-manager.php`
- `assets/js/token-manager-charts.js`

**Documentation:** See `docs/token-management.md`

---

### Product Actualization Tool (PR #1872)
**Added:** December 1, 2025  
**Type:** Pro Add-on Tool

Composites product images into AI-generated scenes while preserving original product pixels.

**Tool Slug:** `product_actualization`

**Capabilities:**
- Composite products into generated scenes (image or video)
- Image mode: Static composited images
- Video mode: Google Gemini VEO animation around product
- Auto background removal when needed
- Professional compositing with shadows and reflections
- Perfect for lifestyle marketing shots, social ads, product visualization

**Requirements:**
- PHP Imagick or GD extension
- Google Gemini API (for video mode)

**Files Added:**
- `addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-product-actualization.php`

**Documentation:** See `docs/tool-reference.md#product-actualization`

---

### Lookup Product Price Tool (PR #1874, #1877)
**Added:** December 1, 2025  
**Type:** Pro Add-on Tool

Multi-source product price discovery and comparison, similar to Google Lens Shopping and browser price extensions.

**Tool Slug:** `lookup_product_price`

**Capabilities:**
- Image-based product identification using Google Cloud Vision
- Document processing for invoices/quotes (PDF, Word, Excel, TXT, CSV)
- Single URL or batch URL price comparison
- Multi-retailer price discovery (Amazon, Walmart, eBay, Target, etc.)
- Schema.org structured data extraction
- Normalized pricing data (currency, availability)

**Requirements:**
- Crawl4AI integration (required)
- Google Cloud Vision API (optional, for image recognition)
- LLM provider (for document processing)

**Files Added:**
- `addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-lookup-product-price.php`

**Documentation:** See `docs/PRODUCT-PRICE-LOOKUP-GUIDE.md`

---

### Enhanced scrape_product Tool (PR #1874)
**Enhanced:** December 1, 2025

Enhanced with Schema.org JSON-LD extraction for better product data capture.

**New Features:**
- Automatic Schema.org JSON-LD parsing from `<script type="application/ld+json">` tags
- Product schema extraction (offers, pricing, availability)
- Support for @graph structures
- Multi-currency support (USD, EUR, GBP)
- Product identifier extraction (SKU, GTIN, brand, model, MPN)
- Multiple extraction methods with fallbacks

**Files Modified:**
- `includes/tools/class-wp-mcp-ai-tool-scrape-product.php`

---

## Bug Fixes & Improvements

### Pro Addon Tools Visibility Fix (PR #1879, #1878, #1875)
**Fixed:** December 1, 2025

**Issue:** Pro addon tools were not appearing in the assistant editor due to missing dependency checks and timing issues.

**Root Causes:**
1. Missing `wp_mcp_ai_core_loaded()` function check
2. Tool registry timing issues (pro tools loaded before core ready)
3. Tools not added to tool group map

**Solutions:**
- Added `wp_mcp_ai_core_loaded()` function to main plugin
- Updated pro addon to check for core before registering tools
- Added pro tools to tool group map for visibility
- Fixed interface mismatches

**Files Modified:**
- `mcp-ai-wpoos.php` - Added `wp_mcp_ai_core_loaded()` function
- `addons/pro/wp-mcp-ai-pro.php` - Added dependency check
- Tool registry timing improvements

**Tests Added:**
- `tests/test-pro-addon-integration.php`

---

### OpenAI File Cleanup 404 Fix (PR #1870)
**Fixed:** December 1, 2025

**Issue:** Users saw "Failed to delete old OpenAI file" errors when cleaning up files that no longer exist on OpenAI's API.

**Solution:** When deleting old files during cleanup, treat 404 (file not found) errors as successful cleanup by:
- Incrementing deleted_count
- Removing local tracking data
- Not logging as failure

**Rationale:** A 404 error means the file is already gone (deleted, expired, or never existed), which is the desired state.

**Files Modified:**
- `includes/class-wp-mcp-ai-openai-client.php` - `cleanup_old_files()` method

---

### edit_gemini_image Tool Format Field Fix (PR #1865)
**Fixed:** December 1, 2025

**Issue:** The `edit_gemini_image` tool was missing the format field in responses, breaking agentic workflows that expected image metadata.

**Solution:** Added format field to tool responses with proper MIME type information.

**Files Modified:**
- `includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php`

---

### CodeSniffer Systematic Cleanup (PR #1876)
**Completed:** December 1, 2025

**Achievement:** 64.4% reduction in CodeSniffer issues

**Baseline:**
- 1,933 errors
- 2,477 warnings
- 4,410 total issues in 204 files

**After Cleanup:**
- 541 errors
- 1,031 warnings
- 1,572 total issues in 115 files

**Fixed:** 2,838 issues (1,392 errors + 1,446 warnings)

**Phases Completed:**
1. **Phase 1:** Auto-fix with PHPCBF - Fixed 2,516 issues
2. **Phase 2:** Critical fixes and exclusions - Fixed 250 issues
3. **Phase 3:** Test helpers and inline comments - Fixed 72 issues

**Files Added:**
- `docs/CODESNIFFER_CLEANUP_SUMMARY.md`

**Files Modified:**
- 186 files auto-fixed by PHPCBF
- 16 files with inline comment fixes
- Updated `composer.json` with exclusions

---

### Missing File Doc Comment Fix (PR #1869)
**Fixed:** December 1, 2025

**Issue:** PHPCS error in test file due to missing file-level doc comment.

**Solution:** Added proper file doc comment before require_once statements.

**Files Modified:**
- `tests/test-gemini-image-tool-sanitization.php`

---

### WP_MCP_AI_Error_Handler Integration (PR #1866)
**Added:** December 1, 2025

**Enhancement:** Integrated error handler in AI Peer CPT for better error tracking and debugging.

**Files Modified:**
- AI Peer CPT implementation files

---

## Architecture Improvements

### wp_mcp_ai_core_loaded() Function
**Added:** December 1, 2025

A marker function that allows add-ons and third-party plugins to verify that the core plugin is active before registering features.

**Purpose:**
- Dependency verification for pro addon
- Safe feature registration timing
- Prevents errors when core is inactive

**Location:** `mcp-ai-wpoos.php` (main plugin file)

**Usage Example:**
```php
if ( function_exists( 'wp_mcp_ai_core_loaded' ) && wp_mcp_ai_core_loaded() ) {
    // Register pro features
    add_action( 'wp_mcp_ai_register_tools', 'my_register_pro_tools', 20 );
}
```

---

### Tool Registry Timing Improvements
**Improved:** December 1, 2025

Fixed timing issues where pro addon tools were being registered before the core tool registry was fully initialized.

**Changes:**
- Core fires `wp_mcp_ai_register_tools` action at priority 10
- Pro addon hooks into the action at priority 20
- Ensures proper initialization order

---

## Build Process Updates

### Three Build Variants
The plugin now supports three distinct build variants:

1. **Base Version** (`wp-mcp-ai-base-X.Y.Z.zip`)
   - Standalone free version
   - Works independently
   - WordPress.org compatible

2. **Pro Add-on** (`wp-mcp-ai-pro-X.Y.Z.zip`)
   - Requires base plugin
   - Premium tools and features
   - Proprietary license

3. **Combined** (`wp-mcp-ai-X.Y.Z.zip`)
   - Base + Pro together
   - Full feature set
   - Convenience package

**Build Command:**
```bash
./bin/build-plugin-zip.sh --all
```

---

## Documentation Updates

### New Documentation Files
- `docs/RECENT_CHANGES_DEC_2025.md` (this file)
- `docs/CODESNIFFER_CLEANUP_SUMMARY.md`
- `docs/PRODUCT-PRICE-LOOKUP-GUIDE.md` (comprehensive guide)

### Updated Documentation
- `docs/ACTION_ITEMS.md` - Updated task completion status
- `docs/REMAINING_ISSUES.md` - Removed resolved issues
- `docs/tool-reference.md` - Added new pro tools
- `docs/ARCHITECTURE-CORE-PRO.md` - Updated for new architecture
- `README.md` - Updated features and tool count

---

## Testing Updates

### New Test Files
- `tests/test-pro-addon-integration.php` - Pro addon dependency tests
- `tests/test-token-manager-ajax-handlers.php` - Token Manager AJAX tests
- `tests/test-token-manager-provider-display.php` - Token Manager UI tests
- `tests/test-rest-token-manager.php` - Token Manager REST API tests

### Test Coverage Improvements
- Pro addon integration testing
- Token lifecycle testing
- Product price lookup testing
- Product actualization testing

---

## Summary Statistics

**Pull Requests Merged:** 13  
**Files Changed:** 200+  
**New Tools Added:** 2 (pro addon)  
**Enhanced Tools:** 1 (scrape_product)  
**New Admin Pages:** 1 (Token Manager)  
**CodeSniffer Issues Fixed:** 2,838 (64.4% reduction)  
**Test Files Added:** 4+  
**Documentation Files Added:** 3  

---

## Related Documentation

- [Tool Reference](reference/tools/tool-reference.md) - Complete tool listing
- [Product Price Lookup Guide](PRODUCT-PRICE-LOOKUP-GUIDE.md) - Detailed guide
- [Token Management](token-management.md) - Token Manager documentation
- [CodeSniffer Cleanup Summary](CODESNIFFER_CLEANUP_SUMMARY.md) - Cleanup details
- [Architecture: Core/Pro](ARCHITECTURE-CORE-PRO.md) - Architecture overview
- [Feature Matrix](reference/models/FEATURE-MATRIX-CORE-PRO.md) - Core vs Pro features
- [Action Items](guides/developer/planning/ACTION_ITEMS.md) - Pending tasks
- [Remaining Issues](REMAINING_ISSUES.md) - Known issues

---

## Breaking Changes

**None.** All changes are backwards compatible.

---

## Upgrade Notes

### For Base Plugin Users
No action required. Update through WordPress admin as normal.

### For Pro Addon Users
1. Ensure base plugin is updated first
2. Update pro addon
3. Visit Token Manager to review credentials

### For Developers
- If using `wp_mcp_ai_core_loaded()`, verify your dependency checks
- New pro tools available: `product_actualization`, `lookup_product_price`
- Token Manager API available for custom integrations

---

**Contributors:** copilot-swe-agent[bot], NV Digital Solutions  
**Review Period:** December 1-31, 2025  
**Next Review:** January 1, 2026

---

## Code Quality & Documentation Updates

### Comprehensive Code Review (December 6, 2025)
**Status:** ✅ Complete

A thorough code review was performed covering all aspects of the codebase:

**Review Document:** [CODE_REVIEW_2025-12-06.md](CODE_REVIEW_2025-12-06.md)

**Overall Score:** 96/100 (Excellent) - Improved from 95/100

**Key Findings:**

| Category | Score | Status |
|----------|-------|--------|
| Security | 100/100 | ✅ Excellent - No critical vulnerabilities |
| Architecture | 98/100 | ✅ Excellent - Clean design patterns |
| Documentation | 100/100 | ✅ Excellent - 454 comprehensive files |
| Code Standards | 88/100 | ✅ Very Good - 64% improvement |
| Testing | 85/100 | ✅ Very Good - 2,106 tests |
| Performance | 90/100 | ✅ Excellent - Well optimized |
| PHP Compatibility | 100/100 | ✅ Excellent - PHP 7.4-8.3 |

**Security Assessment:**
- ✅ No critical vulnerabilities detected
- ✅ Comprehensive input sanitization
- ✅ Multi-tier authentication system
- ✅ Granular capability-based access control
- ✅ Rate limiting and abuse protection

**Code Quality Improvements:**
- **PHPCS Errors:** Reduced from 1,933 to 541 (72% reduction)
- **PHPCS Warnings:** Reduced from 2,477 to 1,031 (58% reduction)
- **Total Issues:** Reduced from 4,410 to 1,572 (64% reduction)

**Linting Results:**
- **JavaScript:** ✅ ESLint passes cleanly (0 errors)
- **PHP:** ✅ PHP Compatibility check passes (7.4-8.3)
- **WordPress Standards:** 88/100 compliance

**Documentation Updates:**
- Created comprehensive CODE_REVIEW_2025-12-06.md
- Updated CODE-REVIEW-MASTER.md with latest findings
- Updated DOCUMENTATION_INDEX.md with new review
- Updated README.md tool count (65+ tools documented)

**Repository Statistics:**
- **PHP Files:** 365 files in includes/
- **Lines of Code:** 108,475+ lines of PHP
- **Test Files:** 461 comprehensive tests
- **Documentation:** 454 markdown files (2.5+ MB)
- **Built-in Tools:** 65+ (35 core + 30 pro)

**Conclusion:** ✅ **APPROVED FOR PRODUCTION**

The WP oOS codebase demonstrates excellent quality with professional security practices, clean architecture, comprehensive documentation, and strong WordPress standards compliance.
