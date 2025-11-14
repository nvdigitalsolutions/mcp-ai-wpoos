# Token Manager - Verification Checklist

**Purpose:** Manual testing checklist for verifying Token Manager Phase 7 implementation  
**Time Required:** 30-45 minutes  
**Prerequisites:** Local WordPress environment with WP oOS plugin active

## Pre-Test Setup

### Environment Setup
- [ ] WordPress 6.0+ installed locally
- [ ] WP oOS plugin activated
- [ ] Admin user logged in
- [ ] Browser console open (F12)
- [ ] Clear browser cache

### Test Data Preparation
- [ ] Create 3-5 test users
- [ ] Assign different tiers (free, pro, enterprise)
- [ ] Generate some token usage (use any AI tool)
- [ ] Ensure usage data exists for at least 7 days

## Dashboard Widgets Verification

### Step 1: Widget Display
1. Navigate to WordPress Dashboard (wp-admin)
2. Look for three WP oOS widgets:
   - **AI Token Usage Overview**
   - **AI Cost Breakdown**
   - **AI Usage Forecast**

**Expected Results:**
- [ ] All 3 widgets are visible
- [ ] Widgets have proper titles
- [ ] No PHP errors displayed
- [ ] No JavaScript errors in console

**Troubleshooting:**
- If widgets don't appear: Check Settings → Screen Options (top-right)
- Enable WP oOS widgets if hidden
- Check PHP error log: `/wp-content/debug.log`

### Step 2: AI Token Usage Overview Widget

**2.1: Quick Stats Grid**
- [ ] "Today" stat shows current day's token count
- [ ] "This Week" stat shows 7-day total
- [ ] "This Month" stat shows 30-day total
- [ ] "Active Users" shows user count
- [ ] Numbers are formatted with commas (1,234)

**2.2: Gauge Chart**
- [ ] Half-circle gauge chart renders
- [ ] Percentage displayed (0-100%)
- [ ] "of daily limit used" label visible
- [ ] "X / Y tokens" text shows usage/limit
- [ ] Gauge color changes based on usage:
  - Green: 0-50%
  - Yellow: 50-75%
  - Orange: 75-90%
  - Red: 90-100%

**2.3: Period Selector**
- [ ] Dropdown shows options: Today, 7 Days, 30 Days, 90 Days
- [ ] Default selection is "7 Days"
- [ ] Changing period triggers chart update
- [ ] Chart updates without page reload (AJAX)
- [ ] No console errors during update

**2.4: Export Button**
- [ ] Export button visible with download icon
- [ ] Click triggers PNG download
- [ ] Downloaded file opens as valid image
- [ ] Chart is clearly visible in exported PNG

**2.5: Usage Trend Chart**
- [ ] Line chart renders below controls
- [ ] X-axis shows dates (last 7 days by default)
- [ ] Y-axis shows token counts
- [ ] Line color is visible and distinct
- [ ] Chart is responsive (resizes with window)

**2.6: Enhanced Tooltips**
- [ ] Hover over data point shows tooltip
- [ ] Tooltip shows:
  - Date label
  - Token count with formatting (e.g., "1.2K tokens")
  - Peak percentage (e.g., "Peak: 85.3%")
- [ ] Tooltip background is dark/readable
- [ ] Tooltip follows mouse movement

**2.7: View Full Report Link**
- [ ] "View Full Report" button at bottom
- [ ] Click navigates to Token Manager tab
- [ ] Link works correctly

### Step 3: AI Cost Breakdown Widget

**3.1: Widget Render**
- [ ] Widget displays without errors
- [ ] Shows cost-related data

**3.2: Cost Display**
- [ ] Total cost shown with $ formatting
- [ ] Cost by provider (if data exists)
- [ ] Cost breakdown makes sense

**Note:** Cost tracking depends on actual AI usage. If no costs shown, verify:
- AI tools have been used recently
- Provider pricing is configured
- Usage is being tracked

### Step 4: AI Usage Forecast Widget

**4.1: Widget Render**
- [ ] Widget displays without errors
- [ ] Shows forecast data

**4.2: Forecast Information**
- [ ] Projected usage displayed
- [ ] Projected date shown
- [ ] Confidence level indicated
- [ ] Trend direction (increasing/stable/decreasing)

## Browser Compatibility Testing

Repeat widget verification in each browser:

### Chrome (Desktop)
- [ ] All widgets render correctly
- [ ] Charts display properly
- [ ] Interactions work (period change, export)
- [ ] No console errors

### Firefox (Desktop)
- [ ] All widgets render correctly
- [ ] Charts display properly
- [ ] Interactions work
- [ ] No console errors

### Safari (Desktop)
- [ ] All widgets render correctly
- [ ] Charts display properly
- [ ] Interactions work
- [ ] No console errors

### Edge (Desktop)
- [ ] All widgets render correctly
- [ ] Charts display properly
- [ ] Interactions work
- [ ] No console errors

## Mobile Responsiveness Testing

### Chrome (Mobile/Tablet)
- [ ] Widgets render on mobile view
- [ ] Charts are readable
- [ ] Period selector works with touch
- [ ] Gauge chart displays correctly
- [ ] Export button accessible

### Safari (iOS)
- [ ] Widgets render on mobile view
- [ ] Charts are readable
- [ ] Touch interactions work
- [ ] No layout issues

## Performance Testing

### Page Load Performance
- [ ] Dashboard loads in < 5 seconds
- [ ] Widgets don't block page rendering
- [ ] No JavaScript errors delay display

### Chart Rendering Performance
- [ ] Charts render in < 1 second
- [ ] Period changes update in < 2 seconds
- [ ] Export completes in < 3 seconds

### With Large Datasets
If you have 100+ users or 1000+ data points:
- [ ] Dashboard still loads in < 10 seconds
- [ ] Charts render without freezing
- [ ] Memory usage is reasonable (<200MB)

## REST API Verification

### Cost Endpoints (Using REST Client or Browser DevTools)

**Test 1: User Cost Breakdown**
```bash
GET /wp-json/mcp-ai/v1/users/{user_id}/cost-breakdown?start_date=2025-11-01&end_date=2025-11-14
```
- [ ] Returns 200 status
- [ ] Response includes:
  - `user_id`
  - `breakdown` object
  - `total_cost`
  - `formatted` cost string

**Test 2: Site-Wide Cost**
```bash
GET /wp-json/mcp-ai/v1/cost/total?start_date=2025-11-01&end_date=2025-11-14
```
- [ ] Returns 200 status
- [ ] Aggregates all users
- [ ] Breakdown by provider/model/tool

**Test 3: Cost by Provider**
```bash
GET /wp-json/mcp-ai/v1/cost/by-provider?days=30
```
- [ ] Returns 200 status
- [ ] Chart-ready data structure
- [ ] Labels and values arrays

**Test 4: Permission Checks**
```bash
GET /wp-json/mcp-ai/v1/cost/total
# Without authentication or as subscriber
```
- [ ] Returns 403 Forbidden or 401 Unauthorized
- [ ] Error message is clear

## Security Verification

### AJAX Security
- [ ] Nonce verification in Network tab
- [ ] Requests include `nonce` parameter
- [ ] Invalid nonce returns error

### Capability Checks
Test as non-admin user:
- [ ] Dashboard widgets not visible to subscribers
- [ ] REST endpoints return permission errors
- [ ] No sensitive data exposed

### Input Sanitization
- [ ] Period selector only accepts valid values (1, 7, 30, 90)
- [ ] Chart ID validated against whitelist
- [ ] SQL injection not possible (using WordPress APIs)

### Output Escaping
- [ ] View page source, check widget HTML
- [ ] User data properly escaped
- [ ] No unescaped HTML in tooltips

## Accessibility Testing

### Keyboard Navigation
- [ ] Tab key navigates through widgets
- [ ] Period selector accessible via keyboard
- [ ] Export button activatable with Enter/Space
- [ ] Focus indicators visible

### Screen Reader Compatibility
- [ ] Widget headings announced
- [ ] Chart data has text alternatives
- [ ] ARIA labels present where needed

### Color Contrast
- [ ] Gauge colors readable
- [ ] Chart colors distinguishable
- [ ] Text meets WCAG 2.1 AA standards (4.5:1 minimum)

## Issue Documentation

### Record Any Issues Found

For each issue:
1. **Issue #**: Sequential number
2. **Component**: Which widget/feature
3. **Description**: What went wrong
4. **Steps to Reproduce**: How to recreate
5. **Expected**: What should happen
6. **Actual**: What actually happened
7. **Browser/Device**: Where it occurred
8. **Screenshot**: If applicable
9. **Console Errors**: Copy from DevTools
10. **Severity**: Critical / High / Medium / Low

### Example Issue Template

```markdown
**Issue #1: Gauge Chart Not Rendering**

**Component:** AI Token Usage Overview Widget → Gauge Chart

**Description:** The half-circle gauge chart does not appear in the widget.

**Steps to Reproduce:**
1. Log in as admin
2. Navigate to Dashboard
3. Look at AI Token Usage Overview widget

**Expected:** Half-circle gauge chart showing usage percentage

**Actual:** Empty space where gauge should be, console error: "Chart is not defined"

**Browser/Device:** Chrome 120 on Windows 11

**Screenshot:** [Attach screenshot]

**Console Errors:**
```
Uncaught ReferenceError: Chart is not defined
    at initGaugeChart (analytics-dashboard.js:45)
```

**Severity:** High (core feature not working)

**Possible Cause:** Chart.js library not loaded before analytics-dashboard.js
```

## Success Criteria

### Must Pass (Critical)
- ✅ All 3 widgets visible on dashboard
- ✅ Gauge chart renders correctly
- ✅ Usage trend chart displays data
- ✅ No critical JavaScript errors
- ✅ Period selector changes data
- ✅ Charts export as PNG

### Should Pass (Important)
- ✅ Charts render in < 1 second
- ✅ AJAX updates work without page reload
- ✅ Tooltips show enhanced information
- ✅ Mobile layout is usable
- ✅ REST API endpoints return correct data
- ✅ Security checks pass (nonces, capabilities)

### Nice to Have (Enhancement)
- ✅ Charts animate smoothly
- ✅ Export PNG has high quality
- ✅ Keyboard navigation works perfectly
- ✅ Screen reader announces all content
- ✅ All browsers render identically

## Final Sign-Off

After completing all checks:

**Tester Name:** ___________________  
**Date:** ___________________  
**Environment:** WordPress _____ / PHP _____  
**Overall Status:** ☐ Pass ☐ Pass with Minor Issues ☐ Fail

**Issues Found:** _____ Critical | _____ High | _____ Medium | _____ Low

**Recommendation:** ☐ Ready for Production ☐ Needs Fixes ☐ Needs Major Work

**Comments:**
___________________________________________________________________________
___________________________________________________________________________
___________________________________________________________________________

---

## Quick Reference

### Expected Files Loaded
```
assets/js/vendor/chart.min.js
assets/js/analytics-dashboard.js
assets/css/analytics-dashboard.css
```

### Expected AJAX Actions
```
wp_ajax_wp_mcp_ai_update_chart_period
wp_ajax_wp_mcp_ai_refresh_chart
```

### Expected REST Endpoints
```
/wp-json/mcp-ai/v1/users/{id}/cost-breakdown
/wp-json/mcp-ai/v1/cost/total
/wp-json/mcp-ai/v1/cost/by-provider
/wp-json/mcp-ai/v1/cost/trend
/wp-json/mcp-ai/v1/users/{id}/roi
/wp-json/mcp-ai/v1/cost/dashboard-summary
```

### Browser DevTools Shortcuts
- **Open Console:** F12 or Cmd+Option+I (Mac)
- **Network Tab:** See AJAX requests
- **Elements Tab:** Inspect DOM structure
- **Clear Console:** Cmd+K or Ctrl+L

### WordPress Debugging
Enable in `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

View errors in: `/wp-content/debug.log`
