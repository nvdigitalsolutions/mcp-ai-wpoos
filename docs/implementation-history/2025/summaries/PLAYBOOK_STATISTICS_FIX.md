# Playbook Statistics Fix - December 2025

## Issue Summary

The playbook statistics displayed in **Settings → WP oOS → Advanced → Playbook Management** showed inflated attachment counts because it counted ALL attachments that had ever been associated with a profession, including orphaned attachments from version history.

**Example:**
- Display showed: "Total Playbook Attachments: 1475"
- Expected: ~200 (one per profession)
- Issue: Counting 1275 orphaned attachments from version history

## Root Cause

The `get_playbook_statistics()` method in `class-wp-mcp-ai-section-advanced.php` used a SQL query that counted all attachments with the `_wp_mcp_ai_playbook_profession_id` meta key:

```php
// OLD CODE (counting all attachments)
$total_attachments = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = %s
        AND pm.meta_key = %s",
        'attachment',
        '_wp_mcp_ai_playbook_profession_id'
    )
);
```

### Why This Was Wrong

When playbook content changes, the system creates a NEW attachment and orphans the old one:
1. Old attachment keeps its `_wp_mcp_ai_playbook_profession_id` meta
2. Old attachment is removed from profession's `memory_files` array
3. New attachment is added to profession's `memory_files` array

The SQL query counted BOTH attachments, even though only the new one is "active."

## Solution

Changed the logic to count only "active" attachments - those still referenced in a profession's `memory_files` meta:

```php
// NEW CODE (counting only active attachments)
$active_attachments = 0;
$professions_with_playbooks = 0;

// Get all professions
$professions = get_posts(
    array(
        'post_type'      => 'mcp_ai_profession',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    )
);

// Count attachments in memory_files (active)
foreach ( $professions as $profession_id ) {
    $memory_files = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );
    
    if ( is_array( $memory_files ) && ! empty( $memory_files ) ) {
        $has_playbook = false;
        
        foreach ( $memory_files as $attachment_id ) {
            $profession_id_meta = get_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', true );
            if ( ! empty( $profession_id_meta ) ) {
                $active_attachments++;
                $has_playbook = true;
            }
        }
        
        if ( $has_playbook ) {
            $professions_with_playbooks++;
        }
    }
}

$total_attachments = $active_attachments;
```

## What Changed

### 1. Statistics Calculation Logic
- **Before**: SQL query counting all attachments with profession meta
- **After**: Iterate through professions and count only attachments in `memory_files`

### 2. Active vs Orphaned
- **Active**: Attachment is in profession's `memory_files` array
- **Orphaned**: Attachment has profession meta but NOT in `memory_files`

### 3. Test Coverage
Added test `test_statistics_exclude_orphaned_attachments()` that:
- Creates 1 active attachment (in memory_files)
- Creates 1 orphaned attachment (not in memory_files)
- Verifies only the active attachment is counted

## Verification

### Expected Results

For a system with 200 professions, each with one playbook:
- **Total Playbook Attachments**: 200 (only active)
- **Professions with Playbooks**: 200 / 200
- These numbers should match

### Orphaned Attachments

Orphaned attachments:
- ✅ Remain in media library for version history
- ✅ Keep their profession meta for reference
- ❌ Are NOT counted in statistics
- ❌ Are NOT in profession's memory_files

## Edge Cases Handled

1. **Empty memory_files**: Count = 0
2. **Null memory_files**: Count = 0
3. **False memory_files**: Count = 0
4. **Mixed attachments**: Only playbook attachments (with profession meta) are counted

## Files Modified

1. `includes/admin/sections/class-wp-mcp-ai-section-advanced.php`
   - Modified `get_playbook_statistics()` method
   - Changed from SQL query to iteration approach

2. `tests/test-playbook-statistics.php`
   - Updated `test_statistics_with_playbooks()` to add attachment to memory_files
   - Added `test_statistics_exclude_orphaned_attachments()` for orphaned scenario

3. `docs/implementation-history/2025/summaries/DUPLICATE_PLAYBOOK_CLEANUP.md`
   - Updated monitoring section
   - Added changelog entry

## Impact

### Positive
- ✅ Accurate statistics showing only active attachments
- ✅ Better understanding of system state
- ✅ Matches expected 1:1 ratio (one attachment per profession)
- ✅ No data loss - orphaned attachments preserved

### Performance
- **Minimal impact**: Method only called when viewing admin settings page
- **Scales well**: Uses `fields => 'ids'` to minimize memory
- **Efficient**: Direct post meta queries instead of complex SQL

## Testing

### Manual Testing
1. Go to **Settings → WP oOS → Advanced**
2. Scroll to **Playbook Management** section
3. Check statistics:
   - Total Playbook Attachments should match Professions count
   - Numbers should be reasonable (not inflated)

### Automated Testing
```bash
vendor/bin/phpunit tests/test-playbook-statistics.php
```

## Rollback Plan

If needed, revert to SQL-based counting:
```php
$total_attachments = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = %s
        AND pm.meta_key = %s",
        'attachment',
        '_wp_mcp_ai_playbook_profession_id'
    )
);
```

However, this will re-introduce the inflated count issue.

## Future Considerations

1. **Cleanup Tool**: Add admin tool to identify and optionally delete orphaned attachments
2. **Caching**: Cache statistics for better performance on high-volume sites
3. **Audit Trail**: Log when attachments are orphaned
4. **Media Library Filter**: Add filter to show only active or orphaned playbook attachments

## Related Documentation

- `docs/implementation-history/2025/summaries/DUPLICATE_PLAYBOOK_CLEANUP.md` - Original deduplication work
- `docs/guides/user/professionals/PROFESSION_KNOWLEDGE_BASE_SYSTEM.md` - Profession system overview
- `tests/test-playbook-statistics.php` - Test coverage

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Related Issue: Playbook attachment count inflation
