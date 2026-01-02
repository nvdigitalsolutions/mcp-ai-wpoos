# Remote Connection Edit/Delete Fix

## Issue Description

Remote WordPress/WooCommerce site connections could not be edited or deleted in the admin interface. When users clicked the "Edit" link, they saw a "Connection not found" error instead of the edit form.

## Root Cause

The issue was caused by a mismatch between how connection IDs were stored and how they were retrieved:

1. **Connection ID Generation**: The `generate_connection_id()` method used `wp_generate_password(12, false)`, which generates passwords with both uppercase and lowercase characters (e.g., `conn_2VKy3HQfI4kI`)

2. **Connection ID Retrieval**: When retrieving connections, the code used `sanitize_key()` which converts strings to lowercase (e.g., `conn_2vky3hqfi4ki`)

3. **The Mismatch**: 
   - Stored ID: `conn_2VKy3HQfI4kI`
   - Sanitized ID: `conn_2vky3hqfi4ki`
   - These don't match, so `get_connection()` returns `null`

## Solution

The fix ensures connection IDs are always lowercase, making them compatible with `sanitize_key()`:

### 1. New Connection ID Generation

Updated `generate_connection_id()` to always generate lowercase IDs:

```php
protected static function generate_connection_id() {
    return 'conn_' . strtolower( wp_generate_password( 12, false ) );
}
```

### 2. Automatic Migration

Added `migrate_connection_ids()` method that automatically normalizes existing connection IDs:

```php
protected static function migrate_connection_ids( $connections ) {
    $needs_migration = false;
    $migrated = array();

    foreach ( $connections as $key => $connection ) {
        $lowercase_key = strtolower( $key );
        
        // Check if key needs migration.
        if ( $key !== $lowercase_key ) {
            $needs_migration = true;
            // Update the id field to match the new lowercase key.
            $connection['id'] = $lowercase_key;
            $migrated[ $lowercase_key ] = $connection;
        } else {
            $migrated[ $key ] = $connection;
        }
    }

    // Save migrated data if changes were made.
    if ( $needs_migration ) {
        update_option( self::OPTION_NAME, $migrated );
    }

    return $migrated;
}
```

### 3. Transparent Migration on Access

Updated `get_all_connections()` to call the migration method:

```php
public static function get_all_connections() {
    $connections = get_option( self::OPTION_NAME, array() );

    if ( ! is_array( $connections ) ) {
        return array();
    }

    // Migrate connection IDs to lowercase if needed.
    $connections = self::migrate_connection_ids( $connections );

    return $connections;
}
```

## Impact

### Existing Connections
- Existing connections with mixed-case IDs are automatically migrated to lowercase on first access
- No data loss - all connection details are preserved
- Migration happens once and is persisted to the database

### New Connections
- All new connections are created with lowercase IDs
- No compatibility issues with `sanitize_key()`

### Edit/Delete Functionality
- Edit links now work correctly
- Delete links now work correctly
- Test connection functionality continues to work

## Migration Example

Before migration:
```php
array(
    'conn_2VKy3HQfI4kI' => array(
        'id' => 'conn_2VKy3HQfI4kI',
        'name' => 'My Connection',
        // ... other fields
    )
)
```

After migration:
```php
array(
    'conn_2vky3hqfi4ki' => array(
        'id' => 'conn_2vky3hqfi4ki',
        'name' => 'My Connection',
        // ... other fields (unchanged)
    )
)
```

## Testing

### Automated Tests

Created `tests/test-remote-site-manager-id-normalization.php` with three test cases:

1. **test_connection_id_normalization**: Verifies that mixed-case IDs are migrated to lowercase
2. **test_new_connection_ids_are_lowercase**: Verifies new connections use lowercase IDs
3. **test_sanitize_key_matches_stored_ids**: Verifies sanitize_key() doesn't change migrated IDs

### Verification Script

Created `bin/verify-remote-connection-fix.sh` that tests:

1. Migration logic with real data
2. sanitize_key() compatibility
3. Edit URL flow
4. New ID generation

All tests pass successfully.

## Files Modified

1. `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`
   - Updated `generate_connection_id()` method
   - Added `migrate_connection_ids()` method
   - Updated `get_all_connections()` method

2. `tests/test-remote-site-manager-id-normalization.php` (new)
   - Added comprehensive test suite

## Security Considerations

- The fix maintains all existing security measures
- Connection credentials remain encrypted
- No sensitive data is exposed during migration
- `sanitize_key()` continues to provide input sanitization

## Backward Compatibility

- **Fully backward compatible**: Existing connections are automatically migrated
- **No manual intervention required**: Migration happens automatically on first access
- **One-time operation**: Migration only runs when mixed-case IDs are detected
- **No breaking changes**: All existing functionality continues to work

## Manual Testing Instructions

To manually verify the fix works:

1. **View Connections**:
   - Navigate to WP Admin → NV oOS → Remote Sites
   - Verify all existing connections are displayed

2. **Edit Connection**:
   - Click "Edit" on any connection
   - Verify the edit form is displayed (not "Connection not found" error)
   - Verify all fields are populated correctly
   - Make a change and save
   - Verify the connection is updated

3. **Delete Connection**:
   - Create a test connection
   - Click "Delete" on the test connection
   - Verify the connection is deleted

4. **Test Connection**:
   - Click "Test" on any connection
   - Verify the test completes (success or failure based on actual connectivity)

5. **Create New Connection**:
   - Click "Add New Connection"
   - Fill in the form
   - Save the connection
   - Verify you can immediately edit and delete it

## Related Issues

This fix resolves the issue described in PR #2521 where connection edit/delete functionality was not working due to ID normalization issues.

## Future Considerations

- Connection IDs are now always lowercase
- Any future features that generate or handle connection IDs should use lowercase
- The migration logic can be removed in a future major version (after all sites have migrated)
