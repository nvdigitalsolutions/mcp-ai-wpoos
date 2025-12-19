# Duplicate Playbook Cleanup Implementation

## Problem Statement

The system was creating duplicate playbook attachments for professions:
- **Total attachments:** 482
- **Unique professions:** 291
- **Duplicate count:** 191 (482 - 291)

The issue occurred because `find_existing_playbook_attachment()` only looked for one attachment per profession, but when multiple attachments existed, they would accumulate over time instead of being replaced.

## Root Cause

The original `find_existing_playbook_attachment()` method:
```php
$args = array(
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => 1,  // Only finds 1 attachment
    'meta_query'     => array(
        array(
            'key'     => '_wp_mcp_ai_playbook_profession_id',
            'value'   => $profession_id,
            'compare' => '=',
        ),
    ),
);
```

**Problem:** If multiple attachments existed for a profession, this would only find one (randomly), leaving duplicates orphaned.

## Solution Implemented

### 1. Enhanced Attachment Finding

**Updated `find_existing_playbook_attachment()`:**
- Now sorts by ID DESC to find the MOST RECENT attachment
- Ensures we always work with the latest playbook

```php
$args = array(
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => 1,
    'orderby'        => 'ID',      // NEW: Sort by ID
    'order'          => 'DESC',    // NEW: Most recent first
    'meta_query'     => array(
        array(
            'key'     => '_wp_mcp_ai_playbook_profession_id',
            'value'   => $profession_id,
            'compare' => '=',
        ),
    ),
);
```

**Added `find_all_playbook_attachments()`:**
- Finds ALL attachments for a profession
- Used by cleanup routines to identify duplicates

```php
protected static function find_all_playbook_attachments( $profession_id ) {
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,        // Get ALL attachments
        'orderby'        => 'ID',
        'order'          => 'DESC',
        'meta_query'     => array(
            array(
                'key'     => '_wp_mcp_ai_playbook_profession_id',
                'value'   => $profession_id,
                'compare' => '=',
            ),
        ),
    );
    $query = new WP_Query( $args );
    return $query->posts;
}
```

### 2. Duplicate Removal Logic

**Added `remove_duplicate_playbooks()`:**
- Keeps only the most recent attachment associated with the profession
- Removes older attachments from profession's memory files
- **Important:** Attachments remain in media library for reference

```php
protected static function remove_duplicate_playbooks( $profession_id ) {
    $attachments = self::find_all_playbook_attachments( $profession_id );
    
    if ( count( $attachments ) <= 1 ) {
        return 0; // No duplicates
    }
    
    $removed_count = 0;
    
    // Keep the first (most recent) attachment
    $keep_attachment_id = $attachments[0]->ID;
    
    // Remove older attachments from profession
    for ( $i = 1; $i < count( $attachments ); $i++ ) {
        $attachment_id = $attachments[ $i ]->ID;
        
        // Remove from profession's memory files
        self::remove_attachment_from_memory_files( $profession_id, $attachment_id );
        
        // Remove profession association, but KEEP in media library
        delete_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id' );
        
        $removed_count++;
    }
    
    // Ensure kept attachment is properly associated
    self::ensure_attachment_in_memory_files( $profession_id, $keep_attachment_id );
    
    return $removed_count;
}
```

**Added `remove_attachment_from_memory_files()`:**
- Safely removes attachment from profession's memory files array
- Maintains array integrity by re-indexing

```php
protected static function remove_attachment_from_memory_files( $profession_id, $attachment_id ) {
    $memory_files = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
    
    if ( ! is_array( $memory_files ) ) {
        return;
    }
    
    $key = array_search( $attachment_id, $memory_files, true );
    if ( false !== $key ) {
        unset( $memory_files[ $key ] );
        $memory_files = array_values( $memory_files ); // Re-index
        update_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, $memory_files );
    }
}
```

### 3. Automatic Cleanup

**Updated `sync_profession_playbook()`:**
- Now automatically removes duplicates before syncing
- Ensures only one attachment per profession

```php
protected static function sync_profession_playbook( $profession, $loader, $force = false ) {
    $slug = $profession->post_name;
    
    // NEW: Remove duplicates first
    self::remove_duplicate_playbooks( $profession->ID );
    
    // Build playbook content...
    // (rest of sync logic)
}
```

### 4. Bulk Cleanup Method

**Added `cleanup_all_duplicates()`:**
- Public method to clean up duplicates across ALL professions
- Returns statistics about cleanup operation

```php
public static function cleanup_all_duplicates() {
    $repository  = new WP_MCP_AI_Profession_Repository();
    $professions = $repository->find_all();
    
    if ( empty( $professions ) ) {
        return array(
            'professions_processed' => 0,
            'duplicates_removed'    => 0,
        );
    }
    
    $total_removed         = 0;
    $professions_processed = 0;
    
    foreach ( $professions as $profession ) {
        $removed = self::remove_duplicate_playbooks( $profession->ID );
        $total_removed += $removed;
        
        if ( $removed > 0 ) {
            $professions_processed++;
        }
    }
    
    return array(
        'professions_processed' => $professions_processed,
        'duplicates_removed'    => $total_removed,
    );
}
```

## Usage

### Automatic Cleanup (Recommended)

Duplicates are automatically cleaned up in multiple scenarios:

#### 1. On Profession Save
When you save a profession in the admin, deduplication runs automatically:
- Edit any profession in WordPress admin
- Make changes and click "Update" or "Publish"
- Duplicates are automatically removed after save

#### 2. Via Sync Buttons (Admin UI)
Navigate to **Settings → WP oOS → Advanced → Playbook Management**:

1. **Sync Changed Playbooks** - Updates only changed playbooks and removes duplicates (fast, safe)
2. **Force Regenerate All Playbooks** - Regenerates all playbooks and removes duplicates (slower, use after major updates)

Both buttons now explicitly mention duplicate removal in their descriptions and success messages.

#### 3. Programmatically via PHP
```php
// Sync all playbooks - duplicates are cleaned automatically
WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );

// Force regenerate all - duplicates are cleaned automatically
WP_MCP_AI_Profession_Playbook_Seeder::sync_all( true );
```

#### 4. On Metabox Display
When viewing a profession's playbook metabox in the admin, duplicates are automatically cleaned before display.

### Manual Cleanup (One-Time)

To clean up existing duplicates without regenerating playbooks:

```php
$result = WP_MCP_AI_Profession_Playbook_Seeder::cleanup_all_duplicates();

echo "Professions processed: " . $result['professions_processed'];
echo "Duplicates removed: " . $result['duplicates_removed'];
```

## Expected Results

### Before Fix
- Total attachments: 482
- Professions with playbooks: 291
- Duplicates: 191

### After Fix
- Total attachments: 291 (active) + 191 (orphaned in media library)
- Professions with playbooks: 291
- Duplicates associated with professions: 0

### Key Outcomes
✅ Each profession has exactly ONE playbook attachment (the most recent)
✅ Older playbooks remain in media library for reference/history
✅ Profession's memory files only reference the most recent playbook
✅ Statistics now show correct 1:1 ratio of attachments to professions

## Testing

### Unit Tests Added

**`test_remove_duplicate_playbooks()`:**
- Creates 3 duplicate attachments for a profession
- Verifies only most recent is kept
- Confirms older attachments remain in media library
- Validates profession association meta is removed from old attachments

**`test_cleanup_all_duplicates()`:**
- Creates 2 professions with 2 attachments each
- Runs bulk cleanup
- Verifies statistics are correct
- Confirms each profession has exactly 1 attachment

### Manual Verification

Run the verification script:
```bash
php verify-duplicate-cleanup.php
```

## Technical Details

### Meta Keys Used

- `_wp_mcp_ai_playbook_profession_id` - Links attachment to profession
- `WP_MCP_AI_Profession_CPT::META_MEMORY_FILES` - Array of attachment IDs on profession

### Attachment Lifecycle

1. **Creation:** Playbook generated and attached to profession
2. **Update:** If content changes, existing attachment is updated
3. **Duplicate Detection:** System finds all attachments for profession
4. **Cleanup:** Keeps most recent, removes association from older ones
5. **Preservation:** Old attachments remain in media library (not deleted)

### Why Keep Old Attachments?

- Provides history of playbook changes
- Allows recovery if needed
- Maintains referential integrity
- Follows WordPress best practices (soft removal)

## Maintenance

### Periodic Cleanup

While automatic cleanup runs during sync operations, you may want to run a one-time cleanup:

```php
// Via WP-CLI (if available)
wp eval "WP_MCP_AI_Profession_Playbook_Seeder::cleanup_all_duplicates();"

// Via admin AJAX
// Just click "Sync Changed Playbooks" in the admin UI
```

### Monitoring

Check playbook statistics in **Settings → WP oOS → Advanced → Playbook Management**:
- Total Playbook Attachments
- Professions with Playbooks

These numbers should match if there are no duplicates.

## Security Considerations

- All methods are `protected static` or `public static`
- Capability checks are enforced at AJAX handler level
- No user input is processed in cleanup methods
- Uses WordPress core functions for all operations
- Follows WordPress coding standards

## Performance Impact

- **Minimal:** Cleanup runs once per profession during sync
- **Efficient:** Uses WP_Query with proper meta queries
- **Scalable:** Batch processing already in place for large datasets
- **Safe:** No database locks or race conditions

## Backward Compatibility

✅ Fully backward compatible
✅ No breaking changes to existing APIs
✅ Existing code continues to work
✅ New methods are additive only

## Related Files

- `includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php` - Main deduplication implementation
- `includes/professions/class-wp-mcp-ai-profession-cpt.php` - save_post deduplication hook
- `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-playbook.php` - Display-time deduplication
- `tests/test-profession-playbook-seeder.php` - Unit tests including save_post test
- `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` - AJAX handlers with updated messages
- `includes/admin/sections/class-wp-mcp-ai-section-advanced.php` - Admin UI with updated button descriptions

## Changelog

### Version 1.7.1 (December 2025) - Enhanced Deduplication
**Issue:** Duplicates not being removed on save and inconsistent messaging about deduplication.

**Changes:**
1. **Added save_post deduplication** - Automatically removes duplicates when saving a profession
2. **Added metabox deduplication** - Cleans duplicates before displaying playbook info
3. **Updated sync button UI** - Explicitly mentions duplicate removal in descriptions
4. **Updated success messages** - Confirms duplicates were removed after sync operations
5. **Added test coverage** - New test for save_post deduplication scenario

**Files Modified:**
- `includes/professions/class-wp-mcp-ai-profession-cpt.php` - Added deduplication after save
- `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-playbook.php` - Added deduplication before display
- `includes/admin/sections/class-wp-mcp-ai-section-advanced.php` - Updated button descriptions
- `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` - Updated success messages
- `tests/test-profession-playbook-seeder.php` - Added save_post test

**Impact:**
- Users now see confirmation that duplicates are being removed
- Duplicates are automatically cleaned up in more scenarios
- More transparent about what operations do
- Better test coverage

## Future Enhancements

Potential improvements for future consideration:

1. **Automatic Cleanup Cron:** Schedule periodic cleanup to catch any stragglers
2. **Cleanup Logging:** Log cleanup operations for audit trail
3. **Admin Notice:** Show notice if duplicates are detected
4. **Media Library View:** Filter to show orphaned playbook attachments
5. **Bulk Delete:** Option to permanently delete orphaned attachments

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: `docs/PROFESSION_KNOWLEDGE_BASE_SYSTEM.md`
