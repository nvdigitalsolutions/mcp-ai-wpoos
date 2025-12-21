# Master Key Rotation

## Overview

The WP MCP AI plugin includes a secure master key rotation mechanism for re-encrypting sensitive data. This feature ensures that when the master encryption key is rotated, all stored secrets are properly re-encrypted with the new key, with full rollback support in case of any failures.

## Key Features

- **Transactional rotation**: All secrets are re-encrypted in a single atomic operation
- **Automatic rollback**: If any re-encryption fails, all changes are reverted
- **Integrity protection**: Failed decryption prevents data corruption
- **Comprehensive logging**: All rotation events are logged for audit purposes

## The Problem It Solves

Without proper rollback handling, a naive key rotation implementation could corrupt stored credentials:

1. **Partial rotation**: Some secrets get re-encrypted with the new key
2. **Failure occurs**: A decrypt or re-encrypt operation fails
3. **Rollback only restores the key**: The master key is restored to the old value
4. **Data corruption**: Secrets that were already re-encrypted with the new key are now unreadable

Additionally, a failed decrypt might return `false`, which could be passed to `encrypt()` and saved as an empty secret, permanently losing the data.

## How It Works

The `WP_MCP_AI_Encryption::rotate_master_key()` function implements a three-phase approach:

### Phase 1: Decrypt and Re-encrypt

```php
foreach ( $secrets as $secret ) {
    // Store original value for rollback
    $original_values[$id] = $secret;
    
    // Decrypt with old key
    $decrypted = decrypt( $secret, $old_key );
    
    // CRITICAL: Check if decrypt failed
    if ( false === $decrypted ) {
        // Trigger rollback immediately
        rollback_rotation( $original_values, $re_encrypted, $old_key );
        return WP_Error( 'decrypt_failed' );
    }
    
    // Re-encrypt with new key
    $new_encrypted = encrypt( $decrypted, $new_key );
    
    // CRITICAL: Check if re-encrypt failed
    if ( false === $new_encrypted ) {
        rollback_rotation( $original_values, $re_encrypted, $old_key );
        return WP_Error( 'encrypt_failed' );
    }
    
    $re_encrypted[$id] = $new_encrypted;
}
```

### Phase 2: Update Database

```php
foreach ( $re_encrypted as $id => $new_value ) {
    $updated = update_post_meta( $id, $new_value );
    
    if ( false === $updated ) {
        // Database update failed - rollback everything
        rollback_rotation( $original_values, $re_encrypted, $old_key );
        return WP_Error( 'db_update_failed' );
    }
}
```

### Phase 3: Update Master Key

```php
// Only update master key after ALL secrets are successfully re-encrypted
update_option( 'master_key', $new_key );
```

## Rollback Process

If any step fails, the `rollback_rotation()` function:

1. Restores all updated secrets to their original encrypted values
2. Restores the old master key
3. Clears all caches
4. Logs the rollback event

This ensures that either **all secrets are rotated** or **no secrets are rotated** - there's no partial state.

## Usage

### Rotating the Master Key

```php
$result = WP_MCP_AI_Encryption::rotate_master_key();

if ( is_wp_error( $result ) ) {
    // Handle error
    error_log( 'Key rotation failed: ' . $result->get_error_message() );
} else {
    // Success
    echo 'Master key rotated successfully';
}
```

### Encrypting Data

```php
$api_key = 'sk-abc123...';
$encrypted = WP_MCP_AI_Encryption::encrypt( $api_key );

if ( false !== $encrypted ) {
    update_post_meta( $post_id, 'wp_mcp_ai_encrypted_secret', $encrypted );
}
```

### Decrypting Data

```php
$encrypted = get_post_meta( $post_id, 'wp_mcp_ai_encrypted_secret', true );
$decrypted = WP_MCP_AI_Encryption::decrypt( $encrypted );

if ( false === $decrypted ) {
    // Decryption failed - handle gracefully
    error_log( 'Failed to decrypt secret' );
} else {
    // Use the decrypted value
    $api_key = $decrypted;
}
```

## Security Considerations

### Master Key Storage

The master encryption key is stored in the WordPress options table. For enhanced security, consider:

1. **Environment variables**: Store the key in `wp-config.php` or environment variables
2. **Hardware security modules**: Use HSM for key storage in enterprise environments
3. **Key rotation schedule**: Rotate the master key periodically (e.g., every 90 days)

### Encryption Algorithm

The implementation uses:
- **Algorithm**: AES-256-CBC
- **IV**: Random 16-byte initialization vector for each encryption
- **Key size**: 256-bit (32-byte) keys

### Error Handling

**Never ignore decrypt failures**:

```php
// BAD - Silently fails
$decrypted = decrypt( $value );
$new_encrypted = encrypt( $decrypted ); // Encrypts 'false' as empty!

// GOOD - Check for errors
$decrypted = decrypt( $value );
if ( false === $decrypted ) {
    throw new Exception( 'Decryption failed' );
}
$new_encrypted = encrypt( $decrypted );
```

## Testing

The plugin includes comprehensive tests in `tests/test-master-key-rotation.php`:

```bash
# Run master key rotation tests
composer test -- --filter WP_MCP_AI_Master_Key_Rotation_Tests
```

### Test Coverage

- ✅ Master key generation
- ✅ Encryption/decryption roundtrip
- ✅ Successful rotation with multiple secrets
- ✅ Rollback on decrypt failure
- ✅ Rollback on re-encrypt failure
- ✅ Rollback on database update failure
- ✅ Partial rotation rollback
- ✅ Failed decrypt not re-encrypted as empty value

## API Reference

### `WP_MCP_AI_Encryption::get_master_key()`

Gets the current master encryption key, generating one if it doesn't exist.

**Returns**: `string` The base64-encoded master key

### `WP_MCP_AI_Encryption::generate_key()`

Generates a new random encryption key.

**Returns**: `string` A new base64-encoded 256-bit key

### `WP_MCP_AI_Encryption::encrypt( $data, $key = null )`

Encrypts data using AES-256-CBC.

**Parameters**:
- `$data` (string) The data to encrypt
- `$key` (string|null) Optional custom key (defaults to master key)

**Returns**: `string|false` Encrypted data or false on failure

### `WP_MCP_AI_Encryption::decrypt( $encrypted, $key = null )`

Decrypts data using AES-256-CBC.

**Parameters**:
- `$encrypted` (string) The encrypted data
- `$key` (string|null) Optional custom key (defaults to master key)

**Returns**: `string|false` Decrypted data or false on failure

### `WP_MCP_AI_Encryption::rotate_master_key()`

Rotates the master encryption key and re-encrypts all secrets.

**Returns**: `bool|WP_Error` True on success, WP_Error on failure

### `WP_MCP_AI_Encryption::is_encrypted( $value )`

Checks if a value appears to be encrypted.

**Parameters**:
- `$value` (string) The value to check

**Returns**: `bool` True if encrypted, false otherwise

## Logging

All key rotation events are logged via `WP_MCP_AI_Logger`:

- `master_key_rotated`: Successful rotation
- `master_key_rotation_rollback`: Failed rotation rolled back
- `wp_mcp_ai_decrypt_failed`: Decryption failure during rotation
- `wp_mcp_ai_encrypt_failed`: Re-encryption failure during rotation
- `wp_mcp_ai_db_update_failed`: Database update failure during rotation

## Best Practices

1. **Always check return values**: Never assume encryption/decryption succeeds
2. **Test rotations in staging**: Always test key rotation in a non-production environment first
3. **Backup before rotation**: Create a database backup before rotating keys
4. **Monitor logs**: Check logs after rotation to ensure success
5. **Rotate regularly**: Implement a key rotation schedule for security

## Troubleshooting

### Rotation fails with "decrypt_failed" error

**Cause**: One or more secrets cannot be decrypted with the current master key.

**Solution**: Check if secrets were encrypted with a different key or if data is corrupted.

### Rotation succeeds but secrets are unreadable

**This should never happen** due to the rollback mechanism. If it does:
1. Check logs for rollback events
2. Verify master key value in options table
3. Restore from backup if necessary

### Performance issues with large number of secrets

For installations with thousands of encrypted secrets:
1. Run rotation during maintenance window
2. Consider batch processing
3. Monitor database performance

## Related Documentation

- [Security Best Practices](../../guides/developer/best-practices/BEST_PRACTICES.md)
- [Credential Management](../../reference/api/mcp-server-authentication.md)
- [Root Security Key](./root-security-key.md)
