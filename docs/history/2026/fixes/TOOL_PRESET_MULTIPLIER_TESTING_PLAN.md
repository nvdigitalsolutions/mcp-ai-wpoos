# Manual Testing Plan - Tool Preset Multiplier Fix

## Prerequisites
- WordPress installation with NV oOS plugin active
- Admin access to WordPress
- At least one AI model configured (OpenAI, Gemini, etc.)

## Test Environment
- **URL**: `wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=token_manager&view=per_tool`
- **User Role**: Administrator (manage_options capability)
- **Browser**: Chrome, Firefox, or Safari (latest version)

## Test Cases

### Test Case 1: Verify Preset Selection UI
**Steps:**
1. Navigate to Token Manager → Per Tool view
2. Locate the "Optimization Available" or "Current Preset" notice box
3. Verify the preset selector dropdown is visible
4. Verify "Apply Preset" button is visible

**Expected Result:**
- Dropdown shows 4 options: Conservative, Balanced, Performance, Aggressive
- Button is enabled and clickable
- Current preset is indicated

### Test Case 2: Apply Balanced Preset (Baseline)
**Steps:**
1. Select "Balanced (Recommended)" from preset dropdown
2. Read the preset description text
3. Click "Apply Preset" button
4. Confirm the action in the prompt dialog
5. Wait for success message
6. Wait for page reload

**Expected Result:**
- Confirmation prompt: "Apply the Balanced preset to all tools? This will overwrite your current settings."
- Success message: "Successfully applied Balanced preset to X tools!"
- Page reloads automatically
- Tool table shows multipliers at base values (1.0x adjustment)

**Verification:**
- Sample tool multipliers should match baseline:
  - High resource tools (e.g., search_content): 2.5x
  - Medium resource tools (e.g., get_recent_posts): 1.7x
  - Low resource tools (e.g., count_tokens): 1.0x

### Test Case 3: Apply Conservative Preset
**Steps:**
1. Select "Conservative" from preset dropdown
2. Click "Apply Preset" button
3. Confirm the action
4. Wait for success message and reload

**Expected Result:**
- Success message indicates tools were updated
- Tool multipliers are reduced by 20% (multiplier × 0.8):
  - High resource tools: 2.5 × 0.8 = 2.0x
  - Medium resource tools: 1.7 × 0.8 = 1.4x
  - Low resource tools: 1.0 × 0.8 = 0.8x

**Verification:**
- Check tool table - multipliers should be 80% of balanced values
- Effective limits should be 20% lower than balanced preset

### Test Case 4: Apply Performance Preset
**Steps:**
1. Select "Performance" from preset dropdown
2. Click "Apply Preset" button
3. Confirm the action
4. Wait for success message and reload

**Expected Result:**
- Success message indicates tools were updated
- Tool multipliers are increased by 30% (multiplier × 1.3):
  - High resource tools: 2.5 × 1.3 = 3.3x (rounded to 3.3)
  - Medium resource tools: 1.7 × 1.3 = 2.2x
  - Low resource tools: 1.0 × 1.3 = 1.3x

**Verification:**
- Check tool table - multipliers should be 130% of balanced values
- Effective limits should be 30% higher than balanced preset

### Test Case 5: Apply Aggressive Preset
**Steps:**
1. Select "Aggressive" from preset dropdown
2. Click "Apply Preset" button
3. Confirm the action
4. Wait for success message and reload

**Expected Result:**
- Success message indicates tools were updated
- Tool multipliers are increased by 50% (multiplier × 1.5):
  - High resource tools: 2.5 × 1.5 = 3.8x (rounded to 3.8)
  - Medium resource tools: 1.7 × 1.5 = 2.6x (rounded to 2.6)
  - Low resource tools: 1.0 × 1.5 = 1.5x

**Verification:**
- Check tool table - multipliers should be 150% of balanced values
- Effective limits should be 50% higher than balanced preset

### Test Case 6: Cancel Preset Application
**Steps:**
1. Select any preset from dropdown
2. Click "Apply Preset" button
3. Click "Cancel" in the confirmation prompt

**Expected Result:**
- No changes are made to tool multipliers
- Page does not reload
- Tool table remains unchanged

### Test Case 7: Verify Database Persistence
**Steps:**
1. Apply a preset (e.g., Performance)
2. Navigate away from the Token Manager page
3. Return to Token Manager → Per Tool view
4. Check tool multipliers in the table

**Expected Result:**
- Applied preset persists across page loads
- Multipliers remain at the values set by the preset
- Current preset indicator shows the correct preset

### Test Case 8: Verify All 200+ Tools Updated
**Steps:**
1. Before applying preset, check database option: `wp_mcp_ai_tool_multipliers`
2. Apply any preset
3. After reload, check database option again

**Expected Result:**
- Database option contains 200+ entries (one per tool)
- All tools from `$tool_categories` are included
- No tools are skipped

**Database Check (via WP-CLI or phpMyAdmin):**
```bash
wp option get wp_mcp_ai_tool_multipliers --format=json | jq 'length'
```
Should return: ~200 (exact number depends on enabled integrations)

### Test Case 9: Error Handling - Invalid Preset
**Steps:**
1. Use browser developer tools to modify dropdown value to "invalid_preset"
2. Click "Apply Preset" button

**Expected Result:**
- Error message displayed
- No changes made to tool multipliers
- User can retry with valid preset

### Test Case 10: Concurrent User Updates
**Steps:**
1. Open Token Manager in two browser tabs
2. Apply different presets in each tab (e.g., Conservative in tab 1, Aggressive in tab 2)
3. Observe which preset wins

**Expected Result:**
- Last applied preset wins (last write wins)
- No data corruption
- Both tabs show consistent values after reload

## Success Criteria

**PASS Criteria:**
- [ ] All 10 test cases pass
- [ ] Tool multipliers are correctly calculated for each preset
- [ ] Database persistence works correctly
- [ ] All 200+ tools are updated
- [ ] No JavaScript errors in browser console
- [ ] No PHP errors in server logs
- [ ] Performance is acceptable (<2 seconds to apply preset)

**FAIL Criteria:**
- Any test case fails
- Tool multipliers are incorrect
- Database updates fail
- JavaScript errors occur
- PHP errors occur

## Rollback Plan

If testing fails:
1. Revert commits from this PR
2. Restore previous version of `includes/class-wp-mcp-ai-tool-recommendations.php`
3. Clear WordPress cache
4. Report failure details

## Notes

- Test on a staging environment first
- Keep database backups before testing
- Monitor server error logs during testing
- Check browser console for JavaScript errors
- Use WordPress debug mode: `define('WP_DEBUG', true);`

## Screenshot Requirements

Capture screenshots of:
1. Preset selector and "Apply Preset" button (before applying)
2. Confirmation dialog
3. Success message
4. Tool table showing updated multipliers (after applying)
5. Different presets side-by-side comparison

## Database Queries for Verification

```sql
-- Check tool multipliers option
SELECT option_value FROM wp_options WHERE option_name = 'wp_mcp_ai_tool_multipliers';

-- Check model preferences option  
SELECT option_value FROM wp_options WHERE option_name = 'wp_mcp_ai_tool_model_preferences';

-- Count number of tools configured
SELECT LENGTH(option_value) - LENGTH(REPLACE(option_value, '":', '')) AS tool_count 
FROM wp_options 
WHERE option_name = 'wp_mcp_ai_tool_multipliers';
```

## Testing Checklist

- [ ] Test Case 1: Verify Preset Selection UI - PASS/FAIL
- [ ] Test Case 2: Apply Balanced Preset - PASS/FAIL
- [ ] Test Case 3: Apply Conservative Preset - PASS/FAIL
- [ ] Test Case 4: Apply Performance Preset - PASS/FAIL
- [ ] Test Case 5: Apply Aggressive Preset - PASS/FAIL
- [ ] Test Case 6: Cancel Preset Application - PASS/FAIL
- [ ] Test Case 7: Verify Database Persistence - PASS/FAIL
- [ ] Test Case 8: Verify All 200+ Tools Updated - PASS/FAIL
- [ ] Test Case 9: Error Handling - PASS/FAIL
- [ ] Test Case 10: Concurrent User Updates - PASS/FAIL

**Overall Result**: PASS / FAIL

**Tested By**: _____________
**Date**: _____________
**Environment**: _____________
