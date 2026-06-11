# Safe Deletion of Orphaned Playbooks

## Overview

Added safe deletion functionality for orphaned system-created playbook attachments. This addresses the concern about cleaning up old playbooks while ensuring user-uploaded attachments are never deleted.

## Problem

When playbook content changes, the system creates a new attachment and orphans the old one. Over time, these orphaned attachments accumulate in the media library. While they provide version history, they can:
- Clutter the media library
- Increase storage usage
- Inflate statistics (now fixed separately)

## Solution

Added two methods to safely delete orphaned playbooks:

### 1. `delete_orphaned_system_playbooks($limit = 50)`

Public method that safely deletes orphaned system-created playbooks.

**Safety Checks:**
- ✅ Only deletes attachments with `_wp_mcp_ai_playbook_hash` meta (system-created marker)
- ✅ Verifies attachment has NO `_wp_mcp_ai_playbook_profession_id` meta (orphaned)
- ✅ Checks file path contains `wp-mcp-ai/profession-playbooks` (system directory)
- ✅ Skips any attachment not meeting ALL criteria

**Returns:**
```php
array(
    'deleted_count' => 5,         // Number of attachments deleted
    'deleted_ids'   => [123, 456, ...],  // IDs of deleted attachments
    'skipped_ids'   => [789],     // IDs skipped (not safe to delete)
)
```

**Usage:**
```php
// Delete up to 50 orphaned playbooks
$result = WP_MCP_AI_Profession_Playbook_Seeder::delete_orphaned_system_playbooks( 50 );

echo "Deleted: " . $result['deleted_count'];
echo "Skipped: " . count( $result['skipped_ids'] );
```

### 2. `remove_duplicate_playbooks($profession_id, $delete = false)`

Enhanced existing method with optional deletion parameter.

**Before:**
```php
// Only orphaned duplicates (kept in media library)
remove_duplicate_playbooks( $profession_id );
```

**After:**
```php
// Orphan duplicates (default behavior)
remove_duplicate_playbooks( $profession_id, false );

// Delete duplicates safely
remove_duplicate_playbooks( $profession_id, true );
```

When `$delete = true`:
- Verifies each duplicate has `_wp_mcp_ai_playbook_hash` meta
- Checks file path is in `wp-mcp-ai/profession-playbooks`
- Deletes if safe, otherwise just orphans

## Safety Features

### Multiple Safety Layers

```
Attachment Found
    ↓
Has _wp_mcp_ai_playbook_hash? ────NO──→ SKIP (user upload)
    ↓ YES
Has _wp_mcp_ai_playbook_profession_id? ──YES──→ SKIP (active)
    ↓ NO (orphaned)
File in wp-mcp-ai/profession-playbooks? ──NO──→ SKIP (wrong location)
    ↓ YES
Safe to delete ✓
```

### What Gets Deleted

✅ **System-created playbooks** (has hash meta)
✅ **Orphaned** (no profession association)
✅ **In system directory** (wp-mcp-ai/profession-playbooks)

### What Gets Protected

❌ **User uploads** (no hash meta) - NEVER deleted
❌ **Active playbooks** (has profession association) - NEVER deleted
❌ **Wrong directory** (outside system path) - NEVER deleted

## Implementation Details

### SQL Query for Finding Orphans

```php
SELECT p.ID, pm_hash.meta_value as hash
FROM {$wpdb->posts} p
INNER JOIN {$wpdb->postmeta} pm_hash 
    ON p.ID = pm_hash.post_id
LEFT JOIN {$wpdb->postmeta} pm_prof 
    ON p.ID = pm_prof.post_id 
    AND pm_prof.meta_key = '_wp_mcp_ai_playbook_profession_id'
WHERE p.post_type = 'attachment'
AND p.post_status = 'inherit'
AND pm_hash.meta_key = '_wp_mcp_ai_playbook_hash'
AND pm_prof.meta_id IS NULL  -- No profession association
LIMIT 50
```

This query:
- Finds attachments with hash meta (system-created)
- Uses LEFT JOIN to identify orphans (no profession meta)
- Limits results to prevent timeouts

### Verification Steps

For each candidate attachment:

```php
// 1. Double-check hash exists
$hash = get_post_meta( $attachment_id, '_wp_mcp_ai_playbook_hash', true );
if ( empty( $hash ) ) {
    $skipped_ids[] = $attachment_id;
    continue;
}

// 2. Verify no profession association
$profession_id = get_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', true );
if ( ! empty( $profession_id ) ) {
    $skipped_ids[] = $attachment_id;
    continue;
}

// 3. Check file path
$attachment_path = get_attached_file( $attachment_id );
if ( false === strpos( $attachment_path, 'wp-mcp-ai/profession-playbooks' ) ) {
    $skipped_ids[] = $attachment_id;
    continue;
}

// Safe to delete
wp_delete_attachment( $attachment_id, true );
```

## Testing

### Test Cases

1. **test_delete_orphaned_system_playbooks**
   - Creates active playbook (should NOT be deleted)
   - Creates orphaned playbook (should be deleted)
   - Creates user upload (should NOT be deleted)
   - Verifies only orphaned playbook is deleted

2. **test_remove_duplicate_playbooks_with_delete**
   - Creates 3 duplicate playbooks
   - Calls with `delete=true`
   - Verifies newest is kept, older 2 are deleted

### Running Tests

```bash
vendor/bin/phpunit tests/test-profession-playbook-seeder.php
```

## Usage Examples

### Clean Up All Orphaned Playbooks

```php
// Process in batches to avoid timeouts
$total_deleted = 0;

do {
    $result = WP_MCP_AI_Profession_Playbook_Seeder::delete_orphaned_system_playbooks( 50 );
    $total_deleted += $result['deleted_count'];
    
    // Log progress
    error_log( "Deleted {$result['deleted_count']} orphaned playbooks" );
    
    // Continue until no more orphans
} while ( $result['deleted_count'] > 0 );

error_log( "Total deleted: $total_deleted" );
```

### Clean Up Duplicates with Deletion

```php
// Get all professions
$professions = get_posts( array(
    'post_type'      => 'mcp_ai_profession',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'fields'         => 'ids',
) );

// Remove duplicates with deletion
$total_removed = 0;
foreach ( $professions as $profession_id ) {
    // Use reflection to access protected method
    $reflection = new ReflectionClass( 'WP_MCP_AI_Profession_Playbook_Seeder' );
    $method = $reflection->getMethod( 'remove_duplicate_playbooks' );
    $method->setAccessible( true );
    
    $removed = $method->invoke( null, $profession_id, true );
    $total_removed += $removed;
}

error_log( "Total duplicates removed: $total_removed" );
```

## Performance Considerations

- **Batch Processing**: Limit parameter prevents timeout on large datasets
- **SQL Efficiency**: Uses indexed meta keys for fast queries
- **File System**: Uses WordPress core functions for safe file deletion
- **Memory**: Processes results one at a time to minimize memory usage

## Monitoring

Check cleanup results:

```php
$result = WP_MCP_AI_Profession_Playbook_Seeder::delete_orphaned_system_playbooks( 50 );

echo "Statistics:\n";
echo "- Deleted: " . $result['deleted_count'] . "\n";
echo "- Skipped: " . count( $result['skipped_ids'] ) . "\n";
echo "- Total processed: " . ( $result['deleted_count'] + count( $result['skipped_ids'] ) ) . "\n";

if ( ! empty( $result['skipped_ids'] ) ) {
    echo "\nSkipped IDs (investigate why):\n";
    foreach ( $result['skipped_ids'] as $skipped_id ) {
        $title = get_the_title( $skipped_id );
        echo "- ID: $skipped_id - $title\n";
    }
}
```

## Integration Points

This deletion functionality integrates with:

1. **Statistics Fix** - Counts active attachments, deletion cleans up orphaned ones
2. **Sync Operations** - Can be called after playbook sync to clean up
3. **Duplicate Removal** - Enhanced to optionally delete instead of orphan
4. **Admin Tools** - Can be exposed via admin UI for manual cleanup

## Future Enhancements

Potential improvements:

1. **Admin UI** - Add cleanup button in settings
2. **Scheduled Cleanup** - Cron job to automatically clean old orphans
3. **Age Filter** - Only delete orphans older than X days
4. **Dry Run Mode** - Preview what would be deleted without deleting
5. **Batch Size Setting** - Make limit configurable via settings

## Migration Path

For existing installations with many orphaned playbooks:

```php
// Step 1: Analyze
$result = WP_MCP_AI_Profession_Playbook_Seeder::delete_orphaned_system_playbooks( 1 );
// Check $result to understand scale

// Step 2: Clean in batches
for ( $i = 0; $i < 10; $i++ ) {
    $result = WP_MCP_AI_Profession_Playbook_Seeder::delete_orphaned_system_playbooks( 100 );
    if ( $result['deleted_count'] === 0 ) {
        break; // Done
    }
    sleep( 1 ); // Throttle to be safe
}
```

## Rollback

If issues arise, orphaned attachments can be recovered from:
- Database backups
- File system backups
- WordPress trash (if not force deleted)

The method uses `wp_delete_attachment( $id, true )` which bypasses trash and permanently deletes. To make recoverable, change to:
```php
wp_delete_attachment( $attachment_id, false ); // Moves to trash instead
```

## Conclusion

The safe deletion functionality provides a robust way to clean up orphaned playbooks while protecting user data through multiple safety checks. It's designed to be conservative - when in doubt, it skips deletion rather than risking data loss.
