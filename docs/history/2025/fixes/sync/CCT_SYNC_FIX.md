# CCT Sync Fix - Auto-Draft Prevention

## Issue
Auto-drafts, drafts, and other non-published assistant posts were being synced to the JetEngine Custom Content Type (CCT), causing unwanted entries to appear in the assistants CCT list.

## Root Cause
The `sync_to_cct()` method in `WP_MCP_AI_Assistant_CPT` class was being called on every post save without checking the post status. This meant that even auto-drafts (created automatically by WordPress when you visit "Add New Assistant") were being synced to the CCT.

## Solution
The fix implements a three-pronged approach:

### 1. Post Status Check in sync_to_cct()
```php
// Only sync published assistants to CCT. Auto-drafts, drafts, and other statuses should not be synced.
if ( 'publish' !== $post->post_status ) {
    // If the post is not published but has a CCT item, delete it to keep CCT clean.
    $this->delete_cct_item( $post_id );
    return;
}
```

### 2. Status Transition Hook
A new `handle_post_status_transition()` method watches for when assistants are unpublished:

```php
// If transitioning from publish to any other status, remove the CCT item.
if ( 'publish' === $old_status && 'publish' !== $new_status ) {
    $this->delete_cct_item( $post->ID );
}
```

This ensures that if you:
- Unpublish an assistant (publish → draft)
- Trash a published assistant (publish → trash)
- Change any published assistant to any other status

The CCT item is automatically removed to keep the CCT clean.

### 3. Cleanup Utility
A new static method `cleanup_orphaned_cct_items()` allows you to clean up any existing orphaned CCT items:

**Via WP-CLI:**
```bash
wp mcp-ai cleanup-cct
```

**Programmatically:**
```php
$result = WP_MCP_AI_Assistant_CPT::cleanup_orphaned_cct_items();
// Returns: array( 'cleaned' => 5, 'errors' => array() )
```

## What Gets Synced Now

### ✅ Synced to CCT
- Published assistants only

### ❌ NOT Synced to CCT
- Auto-drafts (temporary posts created when clicking "Add New")
- Drafts (unpublished work in progress)
- Pending (awaiting review)
- Trash (deleted assistants)
- Any other non-published status

## Cleanup After Deployment

After deploying this fix, you should run the cleanup command once to remove any existing orphaned CCT items:

```bash
wp mcp-ai cleanup-cct
```

This will:
1. Find all assistant posts with linked CCT items
2. Check if they are published
3. Delete CCT items for non-published assistants
4. Report how many items were cleaned up

## Testing

Comprehensive tests have been added in `tests/test-assistant-cct-sync-autodraft.php`:

- Test that auto-drafts are not synced
- Test that drafts are not synced
- Test that pending posts are not synced
- Test that published posts are synced
- Test that unpublishing removes CCT items
- Test that status transitions work correctly
- Test that the cleanup method exists and works

Run tests with:
```bash
composer test -- tests/test-assistant-cct-sync-autodraft.php
```

## Files Modified

1. `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`
   - Added post status check in `sync_to_cct()`
   - Added `handle_post_status_transition()` method
   - Added `cleanup_orphaned_cct_items()` static method
   - Registered `transition_post_status` hook

2. `includes/class-wp-mcp-ai-cli-command.php`
   - Added `cleanup_cct` WP-CLI command

3. `tests/test-assistant-cct-sync-autodraft.php` (new)
   - Comprehensive test suite for sync behavior

## Impact

### Positive
- CCT now only contains published assistants
- Auto-drafts no longer pollute the CCT list
- Cleaner data in JetEngine endpoints
- Better data integrity

### Backward Compatibility
- Existing published assistants continue to sync normally
- Existing CCT items for published assistants are unaffected
- Only orphaned items (linked to non-published posts) are removed

## Future Considerations

1. **Scheduled Cleanup**: Consider adding a cron job to periodically clean up orphaned items
2. **Admin Notice**: Show an admin notice after the plugin update suggesting to run the cleanup command
3. **Sync Verification**: Add a tool to verify CCT sync integrity (compare CPT posts vs CCT items)

## Related Issues

This fix ensures that the JetEngine CCT assistants list (`/wp-admin/admin.php?page=jet-cct-assistants`) only shows published assistants, making it a reliable source of truth for the assistant directory.
