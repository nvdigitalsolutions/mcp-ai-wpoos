# Gemini Cost Tracking Migration - Complete Implementation Guide

## Overview
This implementation adds a UI for the Gemini cost tracking migration feature to the WordPress admin dashboard, making it accessible to administrators without CLI access.

## Location in UI
**Path**: WordPress Admin → Settings → WP oOS → Advanced Settings → Data Management (sub-tab)

## Problem Solved
Previously, the migration function existed but was only accessible via WP-CLI:
```bash
wp mcp-ai token migrate-providers --dry-run
wp mcp-ai token migrate-providers
```

Now administrators can use the UI to:
1. Preview what would be changed (dry run)
2. Execute the migration with confirmation
3. See clear feedback about the results

## What Gets Fixed

### Issue
Historical token tracking records where Gemini tools were incorrectly attributed to OpenAI provider, resulting in:
- Wrong provider attribution (openai instead of gemini)
- Wrong cost calculation (using OpenAI pricing instead of Gemini pricing)
- Estimated flag set incorrectly

### Affected Tools
- `generate_gemini_image`
- `edit_gemini_image`
- `analyze_comment_content`
- `generate_image_alt_text`
- `generate_image_caption`

### Corrections Applied
For each misattributed record:
1. **Provider**: Updated from "openai" to "gemini"
2. **Model**: Inferred correct Gemini model (e.g., gemini-1.5-flash)
3. **Cost**: Recalculated using Gemini pricing
4. **Is Estimated**: Changed from 1 (estimated) to 0 (actual)

## Files Modified

### 1. includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php

#### Action Map Registration (Line 77)
```php
'wp_ajax_wp_mcp_ai_migrate_gemini_costs' => 'handle_migrate_gemini_costs',
```

#### Handler Method (Lines 1709-1788)
```php
private function handle_migrate_gemini_costs() {
    // Nonce verification
    check_ajax_referer('wp_mcp_ai_migrate_gemini_costs', 'nonce');
    
    // Permission check
    if (!current_user_can('manage_options')) {
        wp_send_json_error(...);
        return;
    }
    
    // Get action type: 'preview' or 'migrate'
    $action = isset($_POST['action_type']) 
        ? sanitize_key(wp_unslash($_POST['action_type'])) 
        : 'preview';
    
    // Validate action
    if (!in_array($action, array('preview', 'migrate'), true)) {
        wp_send_json_error(...);
        return;
    }
    
    // Execute migration
    $dry_run = ('preview' === $action);
    $results = WP_MCP_AI_Enhanced_Token_Tracking::migrate_provider_misattributions(
        $dry_run, 
        1000  // Batch size
    );
    
    // Return results
    wp_send_json_success([
        'message' => $message,
        'dry_run' => $dry_run,
        'total_checked' => $results['total_checked'],
        'records_updated' => $results['records_updated']
    ]);
}
```

### 2. includes/admin/sections/class-wp-mcp-ai-section-advanced.php

#### UI Section (Lines 774-917)

**Structure**:
```html
<!-- GEMINI COST TRACKING MIGRATION SECTION -->
<div class="wp-mcp-ai-gemini-migration-section">
    <h3>Gemini Cost Tracking Migration</h3>
    
    <!-- Description -->
    <p class="description">...</p>
    
    <!-- Information Box -->
    <div class="wp-mcp-ai-migration-info">
        <h4>What This Does</h4>
        <ul>
            <li>Identifies misattributed records</li>
            <li>Updates provider to Gemini</li>
            <li>Recalculates costs</li>
            <li>Updates estimated flag</li>
        </ul>
        <p>Affected Tools: ...</p>
    </div>
    
    <!-- Action Buttons -->
    <div class="wp-mcp-ai-migration-actions">
        <h4>Run Migration</h4>
        <button id="wp-mcp-ai-migrate-gemini-preview-btn">
            Preview Changes
        </button>
        <button id="wp-mcp-ai-migrate-gemini-run-btn">
            Run Migration
        </button>
    </div>
    
    <!-- Message Area -->
    <div id="wp-mcp-ai-migrate-gemini-message"></div>
    
    <!-- JavaScript -->
    <script>
        // AJAX handlers for buttons
    </script>
</div>
```

**JavaScript Features**:
- AJAX calls to `wp_mcp_ai_migrate_gemini_costs`
- Loading states (disable buttons, show spinner)
- Success/error/warning message display
- Confirmation dialog before running migration
- Optional page reload after successful migration

### 3. tests/test-gemini-migration-ajax.php (New File)

#### Test Cases

1. **`test_ajax_handler_registered()`**
   - Verifies the AJAX action is registered

2. **`test_migration_preview_no_records()`**
   - Tests preview with empty dataset
   - Expects 0 records checked/updated

3. **`test_migration_preview_with_records()`**
   - Creates a misattributed record
   - Tests preview mode
   - Verifies record is NOT actually updated (dry run)

4. **`test_migration_execution()`**
   - Creates a misattributed record
   - Tests actual migration
   - Verifies record IS updated with correct provider/model

5. **`test_migration_permission_check()`**
   - Tests with non-admin user
   - Expects permission error

6. **`test_migration_invalid_action_type()`**
   - Tests with invalid action type
   - Expects validation error

## User Flow

### Step 1: Navigate to Settings
1. Log in to WordPress admin
2. Go to Settings → WP oOS
3. Click "Advanced Settings" tab
4. Click "Data Management" sub-tab
5. Scroll to "Gemini Cost Tracking Migration" section

### Step 2: Preview Changes
1. Read the description and affected tools
2. Click "Preview Changes" button
3. Wait for processing (button shows spinner)
4. Review message showing how many records would be updated
5. Example: "Preview: Found 15 records (out of 15 checked) that would be migrated..."

### Step 3: Run Migration (Optional)
1. If preview shows records to update:
   - Click "Run Migration" button
   - Confirm in dialog: "This will update historical cost tracking data..."
   - Wait for processing
   - Review success message
   - Optionally reload page to see updated stats

2. If preview shows no records:
   - Message: "No Gemini tool records found that need migration..."
   - No further action needed

## Message Examples

### Preview - Records Found
```
ℹ️  Preview: Found 15 records (out of 15 checked) that would be migrated 
   from OpenAI to Gemini attribution with corrected costs.
```

### Preview - No Records
```
⚠️  No Gemini tool records found that need migration. All cost tracking 
   data is already correct.
```

### Migration Success
```
✅ Migration complete! Successfully updated 15 records with corrected 
   Gemini provider attribution and costs.
```

### Migration - No Updates
```
⚠️  No records were updated. All cost tracking data is already correct.
```

### Error - Permission Denied
```
❌ You do not have permission to perform this action.
```

### Error - Invalid Action
```
❌ Invalid action type.
```

## Security Implementation

### 1. CSRF Protection
```php
check_ajax_referer('wp_mcp_ai_migrate_gemini_costs', 'nonce');
```

### 2. Authorization
```php
if (!current_user_can('manage_options')) {
    wp_send_json_error([
        'message' => __('You do not have permission to perform this action.', 'wp-mcp-ai')
    ]);
}
```

### 3. Input Validation
```php
$action = isset($_POST['action_type']) 
    ? sanitize_key(wp_unslash($_POST['action_type'])) 
    : 'preview';

if (!in_array($action, array('preview', 'migrate'), true)) {
    wp_send_json_error(['message' => __('Invalid action type.', 'wp-mcp-ai')]);
}
```

### 4. Output Escaping
```php
// JavaScript
wp_json_encode(wp_create_nonce('wp_mcp_ai_migrate_gemini_costs'))
esc_js(__('Processing...', 'wp-mcp-ai'))

// HTML
esc_html_e('Gemini Cost Tracking Migration', 'wp-mcp-ai')
esc_attr($class)
```

### 5. Database Security
- Uses existing migration function with prepared statements
- No direct SQL in new code
- Batch size limit (1000) prevents resource exhaustion

## Performance Considerations

### Batch Processing
- **Limit**: 1000 records per execution
- **Reason**: Prevents memory exhaustion and long-running operations
- **Solution for large datasets**: Run multiple times (safe due to idempotency)

### Database Locking
- Updates use WordPress database class
- Proper transaction handling
- Batch size prevents long table locks

### Concurrent Executions
- **Safe**: Operation is idempotent
- **Result**: Running multiple times has same effect as running once
- **Preview mode**: Allows checking before execution

## Translation Support

All user-facing strings use WordPress i18n functions:
```php
__('Text', 'wp-mcp-ai')                    // Translatable string
esc_html_e('Text', 'wp-mcp-ai')            // Escaped and echo
esc_attr_e('Text', 'wp-mcp-ai')            // For attributes
sprintf(__('Format %d', 'wp-mcp-ai'), $n)  // With placeholders
```

## Code Quality Standards

### WordPress Coding Standards
- ✅ Tabs for indentation
- ✅ Proper spacing around operators
- ✅ Consistent bracing style
- ✅ PHPDoc comments for all methods
- ✅ Translatable strings

### DRY Principle
- Reuses existing `migrate_provider_misattributions()` function
- Follows pattern established by profession/team reseeding
- No code duplication

### Maintainability
- Clear method names
- Comprehensive comments
- Logical code organization
- Consistent with codebase patterns

## Testing Strategy

### Unit Tests
- Tests AJAX handler registration
- Tests preview mode
- Tests actual migration
- Tests permission checks
- Tests input validation
- Tests edge cases (no records)

### Manual Testing Checklist
- [ ] Navigate to UI location
- [ ] Verify section displays correctly
- [ ] Click "Preview Changes" without data
- [ ] Create test data with misattributed records
- [ ] Click "Preview Changes" with data
- [ ] Verify preview message is accurate
- [ ] Click "Run Migration" and confirm
- [ ] Verify success message
- [ ] Check database to confirm updates
- [ ] Test as non-admin user (should fail)
- [ ] Test with invalid inputs (should be handled)

## Deployment Notes

### Database Schema
- No schema changes required
- Uses existing `wp_mcp_ai_token_tracking` table

### Dependencies
- Requires `WP_MCP_AI_Enhanced_Token_Tracking` class
- Requires `WP_MCP_AI_Cost_Calculator` class
- Requires `WP_MCP_AI_Token_Tracking_Database` class

### Backwards Compatibility
- ✅ No breaking changes
- ✅ Existing WP-CLI command still works
- ✅ New UI is additive only

### Rollback Plan
If issues arise:
1. Migration is one-way but safe (fixes incorrect data)
2. Can be reverted by git revert of the commits
3. Database changes from migration can't be automatically undone
   (but that's intentional - the migration fixes incorrect data)

## Future Enhancements (Optional)

1. **Progress Bar**: For large datasets (>1000 records)
2. **Migration Log**: Store history of migrations run
3. **CSV Export**: Download report of changes made
4. **Filters**: Allow filtering which tools to migrate
5. **Undo**: Complex feature to reverse migration (may not be needed)

## Support & Troubleshooting

### Common Issues

**Issue**: "No records found" but I know there are Gemini tools
**Solution**: Check that records are actually misattributed (provider = openai, tool = generate_gemini_image)

**Issue**: Permission denied
**Solution**: Ensure user has 'manage_options' capability (administrator role)

**Issue**: AJAX error
**Solution**: Check browser console for details, verify nonce is valid

**Issue**: Migration doesn't complete
**Solution**: Check server error logs, may need to increase PHP timeout

### Debug Mode

Enable WordPress debug mode to see detailed errors:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check `/wp-content/debug.log` for errors.

## Conclusion

This implementation provides a user-friendly interface for the Gemini cost tracking migration, following WordPress and plugin best practices for:
- Security (nonce, capabilities, validation, sanitization, escaping)
- Code quality (standards, patterns, documentation)
- User experience (clear messages, loading states, confirmations)
- Testing (comprehensive test coverage)
- Maintainability (DRY, clear code, consistent patterns)

The feature is production-ready and can be safely deployed.
