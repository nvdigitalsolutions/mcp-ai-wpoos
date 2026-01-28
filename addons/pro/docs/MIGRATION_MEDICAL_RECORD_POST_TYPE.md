# Medical Record Post Type Migration

## Overview

This migration updates the medical record post type name from `mcp_ai_medical_record` (21 characters - invalid) to `mcp_ai_med_record` (17 characters - valid) to comply with WordPress post type name length requirements.

## Background

WordPress requires post type names to be between 1 and 20 characters in length. The original post type name `mcp_ai_medical_record` was 21 characters, which caused WordPress to display this notice:

```
Notice: Function register_post_type was called incorrectly. 
Post type names must be between 1 and 20 characters in length. 
Please see Debugging in WordPress for more information. 
(This message was added in version 4.2.0.)
```

## Migration Details

### Automatic Migration

The migration runs automatically on `admin_init` when:
1. The migration has not been run before
2. There are posts with the old post type name in the database

### What Gets Migrated

- All posts in the `wp_posts` table with `post_type = 'mcp_ai_medical_record'`
- Post type is updated to `mcp_ai_med_record`
- WordPress object cache is cleared for affected posts

### Migration Status

The migration stores its status in the WordPress options table:
- Option name: `wp_mcp_ai_migration_medical_record_post_type`
- Value: `1.0.0` (migration version)

## Manual Migration

If you need to run the migration manually:

```php
// Run migration
$result = WP_MCP_AI_Migrate_Medical_Record_Post_Type::run();

// Check status
$status = WP_MCP_AI_Migrate_Medical_Record_Post_Type::get_status();

// Rollback (use with caution - only for testing)
$result = WP_MCP_AI_Migrate_Medical_Record_Post_Type::rollback();
```

## Migration Class

File: `addons/pro/includes/migrations/class-wp-mcp-ai-migrate-medical-record-post-type.php`

### Methods

#### `run()`
Runs the migration. Returns array with status information:
- `status`: 'success', 'already_run', or 'error'
- `message`: Human-readable message
- `migrated`: Number of posts migrated
- `total`: Total posts found

#### `get_status()`
Returns migration status:
- `migration_completed`: Boolean
- `migration_version`: Version string or false
- `old_post_type_count`: Number of posts with old post type
- `new_post_type_count`: Number of posts with new post type
- `needs_migration`: Boolean

#### `rollback()`
Reverts the migration (for testing purposes only).

## Testing

A test has been created to verify post type name lengths:
- File: `tests/test-post-type-name-length.php`
- Tests all plugin post types are within the 1-20 character limit

## Impact

### For New Installations
No impact - the new post type name is used from the start.

### For Existing Installations
- Migration runs automatically on first admin page load after update
- No user action required
- Existing medical records remain accessible with the new post type name
- Post relationships, meta data, and taxonomies are preserved

## Troubleshooting

### Migration Failed
Check the migration result:
```php
$status = WP_MCP_AI_Migrate_Medical_Record_Post_Type::get_status();
print_r($status);
```

### Force Re-run Migration
Delete the migration marker and reload an admin page:
```php
delete_option('wp_mcp_ai_migration_medical_record_post_type');
```

### Database Issues
Check for database errors:
```php
global $wpdb;
echo $wpdb->last_error;
```

## Related Files

### Updated Files
- `addons/pro/includes/class-wp-mcp-ai-health-wellness-cpt.php` - Post type constant
- `addons/pro/includes/health-wellness-management-init.php` - Migration loader
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-create-medical-record.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-delete-medical-record.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-get-medical-record.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-get-member-health-summary.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-list-medical-records.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-search-medical-records.php`
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-update-medical-record.php`

### New Files
- `addons/pro/includes/migrations/class-wp-mcp-ai-migrate-medical-record-post-type.php` - Migration class
- `tests/test-post-type-name-length.php` - Test file

### Documentation Files
- `addons/pro/docs/CPT_RESEARCH_IMPLEMENTATION_REVIEW.md`
- `addons/pro/docs/HEALTH_WELLNESS_IMPLEMENTATION.md`
- `addons/pro/docs/TOOLKIT_ARCHITECTURE_PATTERNS.md`
