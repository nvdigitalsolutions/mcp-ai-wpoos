# Manual Test Plan: Pro Workflow Builder Fix

## Test Environment Setup

1. **WordPress Version:** 6.0 or higher
2. **PHP Version:** 7.4 or higher
3. **Plugin:** NV oOS (Open Operator System) with Pro addons enabled
4. **User:** Administrator with `manage_options` capability

## Pre-Test Cleanup

Before testing, clear all caches to ensure fresh state:

```bash
# Clear WordPress caches
wp cache flush

# Clear admin menu cache
wp eval-file wp-content/plugins/mcp-ai-wpoos/bin/clear-admin-menu-cache.php

# Restart PHP-FPM (adjust version as needed)
sudo systemctl restart php8.1-fpm
```

## Test Cases

### Test 1: Menu Item Appears

**Objective:** Verify the Pro Workflows menu item appears in the admin sidebar

**Steps:**
1. Log into WordPress admin as administrator
2. Look for "NV oOS Pro" in the admin sidebar
3. Hover over or click to expand the submenu

**Expected Result:**
- "Pro Workflows" menu item is visible under "NV oOS Pro"

**Status:** [ ] Pass [ ] Fail

---

### Test 2: Correct URL Format

**Objective:** Verify the menu link uses the correct URL format

**Steps:**
1. Log into WordPress admin (use incognito/private browsing mode)
2. Navigate to "NV oOS Pro" → "Pro Workflows"
3. Check the URL in the browser address bar

**Expected Result:**
- URL should be: `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`
- URL should NOT be: `/wp-admin/nvoos-pro-workflow-builder`

**Status:** [ ] Pass [ ] Fail

---

### Test 3: Page Loads Successfully

**Objective:** Verify the Pro Workflow Builder page loads without errors

**Steps:**
1. Click on "NV oOS Pro" → "Pro Workflows"
2. Wait for the page to load

**Expected Result:**
- Page loads successfully (no 404 error)
- Page title shows "Pro Workflow Builder"
- React application root div is present: `<div id="mcp-ai-pro-workflow-builder-root"></div>`

**Status:** [ ] Pass [ ] Fail

---

### Test 4: React Application Loads

**Objective:** Verify the React-based workflow builder UI loads

**Steps:**
1. Navigate to Pro Workflow Builder page
2. Open browser console (F12 → Console tab)
3. Check for JavaScript errors
4. Look for the workflow builder UI elements

**Expected Result:**
- No JavaScript console errors related to workflow builder
- React application renders inside the root div
- Workflow builder UI is functional (if React app is built)

**Status:** [ ] Pass [ ] Fail

---

### Test 5: No Duplicate Menu Registrations

**Objective:** Verify the menu is not registered twice

**Steps:**
1. Install Query Monitor plugin (for debugging)
2. Navigate to Pro Workflow Builder page
3. Check Query Monitor's Hooks panel
4. Look for `admin_menu` hook registrations

**Expected Result:**
- `WP_MCP_AI_Pro_Workflow_Builder_Page::register_page` should appear only ONCE
- No duplicate registrations

**Status:** [ ] Pass [ ] Fail

---

### Test 6: Cross-Browser Testing

**Objective:** Verify the fix works across different browsers

**Steps:**
1. Test in Chrome/Chromium
2. Test in Firefox
3. Test in Safari (if available)

**Expected Result:**
- URL format is correct in all browsers
- Page loads successfully in all browsers

**Status:** 
- Chrome: [ ] Pass [ ] Fail
- Firefox: [ ] Pass [ ] Fail  
- Safari: [ ] Pass [ ] Fail

---

## PHPUnit Tests

Run the automated test suite to verify menu registration:

```bash
# Install dependencies if needed
composer install

# Run the specific test file
vendor/bin/phpunit tests/test-pro-workflow-builder-menu.php

# Or run all tests
vendor/bin/phpunit
```

**Expected Results:**
- `test_workflow_builder_registered_under_pro_dashboard` - PASS
- `test_workflow_builder_slug_format` - PASS
- `test_workflow_builder_url_format` - PASS

**Status:** [ ] Pass [ ] Fail [ ] Not Run

---

## Regression Testing

### Test 7: Other Pro Pages Still Work

**Objective:** Ensure the fix didn't break other pro admin pages

**Steps:**
1. Test "NV oOS Pro" → "Architect Agent"
2. Test "NV oOS Pro" → "Pro Dashboard"
3. Test other Pro submenu items

**Expected Result:**
- All other Pro pages load successfully
- No 404 errors

**Status:** [ ] Pass [ ] Fail

---

## Browser Console Checks

Open browser console (F12) and verify:

- [ ] No 404 errors for workflow builder page
- [ ] No JavaScript errors related to menu or URL
- [ ] All assets (CSS, JS) load successfully

---

## Test Results Summary

**Test Date:** _________________

**Tested By:** _________________

**Overall Result:** [ ] All Tests Pass [ ] Some Tests Fail [ ] Major Issues Found

**Notes:**
```
(Add any additional notes or observations here)
```

---

## Rollback Plan

If tests fail:

1. **Revert the changes:**
   ```bash
   git revert HEAD
   ```

2. **Clear caches again:**
   ```bash
   wp cache flush
   sudo systemctl restart php-fpm
   ```

3. **Investigate the specific failure:**
   - Check PHP error logs: `/var/log/php/error.log`
   - Check WordPress debug log: `wp-content/debug.log`
   - Review the test output for specific failures

4. **Report findings:**
   - Document which test failed
   - Include error messages and logs
   - Note the WordPress and PHP versions
