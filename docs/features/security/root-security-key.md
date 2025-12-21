# Root Directory Security Key

The Root Directory Security Key feature provides an additional layer of security for plugin initialization, particularly useful during emergency shutdown scenarios.

## Overview

When configured, the root security key can be enabled to block plugin initialization until the correct key is provided. This prevents unauthorized users from re-enabling the plugin after an emergency shutdown, even if they have administrator access to WordPress.

## Configuration

### Step 1: Define the Security Key

Add the following constant to your `wp-config.php` file:

```php
define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'your-secure-random-key-here' );
```

**Important Security Notes:**
- Use a strong, random key (32+ characters recommended)
- Never commit this key to version control
- Store it securely (password manager, encrypted vault, etc.)
- Change it immediately if compromised

**Generating a Secure Key:**

You can generate a secure key using:
```bash
# Using OpenSSL
openssl rand -base64 32

# Using WordPress CLI
wp eval 'echo wp_generate_password(64, false, false);'
```

### Step 2: Enable Key Requirement (Optional)

The security key requirement can be enabled in two ways:

#### Automatic Activation
When the Nefarious Usage Monitor detects suspicious activity and triggers an emergency shutdown, the root security key requirement is automatically enabled (if a key is configured).

#### Manual Activation
You can manually enable the key requirement using PHP:

```php
$security_key = WP_MCP_AI_Root_Security_Key::get_instance();
$security_key->enable_key_requirement( 'Manual activation for security audit' );
```

## How It Works

### Plugin Initialization Flow

1. **Normal Operation** (Key not required):
   - Plugin initializes normally
   - All features are available

2. **Key Required**:
   - Plugin initialization is blocked for non-admin contexts
   - Admin interface remains accessible to allow key verification
   - REST API endpoints are unavailable
   - Chat interfaces are disabled
   - Tool execution is blocked

### Key Verification Process

When the key requirement is enabled:

1. Administrators see a prominent admin notice with a verification form
2. They enter the root security key
3. The system verifies the key using timing-attack-safe comparison
4. On success:
   - Key requirement is disabled
   - Failed attempt counter is reset
   - Plugin functionality is restored
5. On failure:
   - Failed attempt is logged
   - After 5 failed attempts in 5 minutes, a 15-minute lockout is triggered
   - IP address and user ID are logged for security auditing

## Admin Interface

### Verification Form

When the key requirement is active, administrators will see:

- **Error Notice**: Large, dismissible notice at the top of all admin pages
- **Verification Form**: Password input field for entering the key
- **Lockout Message**: After too many failed attempts
- **Context Information**: Why the key was enabled and when

### Success/Error Messages

The system provides clear feedback:

- **Success**: "Root security key verified. Plugin has been unlocked."
- **Invalid Key**: "Invalid root security key provided."
- **Empty Input**: "Please enter a root security key."
- **Locked Out**: "Too many failed verification attempts. Please wait 15 minutes."

## Security Features

### Protection Against Attacks

1. **Timing Attack Prevention**:
   - Uses `hash_equals()` for constant-time string comparison
   - Prevents attackers from determining key length or content

2. **Brute Force Protection**:
   - Automatic lockout after 5 failed attempts
   - 15-minute cooldown period
   - Tracked per IP address and user ID

3. **Comprehensive Logging**:
   - All verification attempts are logged
   - Failed attempts include IP address and user ID
   - Lockout events are recorded

### Failed Attempt Tracking

The system tracks:
- Timestamp of each failed attempt
- User ID (if logged in)
- Client IP address
- Total attempts in the last 5 minutes

### Automatic Lockout

When 5 failed attempts occur within 5 minutes:
- A 15-minute lockout is triggered
- A transient is set to prevent further attempts
- Verification form is replaced with a lockout message
- Event is logged for security monitoring

## Integration with Emergency Shutdown

### Automatic Enablement

When the Nefarious Usage Monitor triggers an emergency shutdown:

1. Emergency shutdown is activated
2. If `WP_MCP_AI_ROOT_SECURITY_KEY` is defined:
   - Root key requirement is automatically enabled
   - Reason is set to: "Emergency shutdown triggered by security monitor"
   - Notification is sent to admin email (if configured)

### Clearing Emergency Shutdown

To fully restore the plugin after an emergency shutdown:

1. Clear the emergency shutdown via the admin interface
2. Verify the root security key (if enabled)
3. Both conditions must be satisfied for full restoration

## API Reference

### Main Class

`WP_MCP_AI_Root_Security_Key`

#### Methods

**`get_instance()`**
Returns the singleton instance.

**`is_key_configured()`**
Returns `true` if `WP_MCP_AI_ROOT_SECURITY_KEY` constant is defined.

**`is_key_required()`**
Returns `true` if key verification is currently required.

**`can_initialize()`**
Returns `true` if plugin initialization should proceed.
- Always returns `true` if key is not required
- Returns `true` in admin context even when key is required
- Returns `false` in non-admin contexts when key is required

**`enable_key_requirement( $reason )`**
Enables the root key requirement.
- **Parameters**: `$reason` (string) - Reason for enabling
- **Returns**: `true` on success, `false` if key not configured

**`disable_key_requirement( $provided_key )`**
Disables the requirement after verifying the key.
- **Parameters**: `$provided_key` (string) - The security key to verify
- **Returns**: `true` on success, `WP_Error` on failure

**`verify_key( $provided_key )`**
Verifies a provided key against the configured key.
- **Parameters**: `$provided_key` (string) - The key to verify
- **Returns**: `true` if valid, `WP_Error` if invalid

**`get_status()`**
Returns current status information.
- **Returns**: Array with keys:
  - `configured` (bool) - Whether key is configured
  - `required` (bool) - Whether verification is required
  - `locked_out` (bool) - Whether currently locked out
  - `failed_attempts` (int) - Number of recent failed attempts
  - `enabled_at` (string) - When requirement was enabled (if active)
  - `reason` (string) - Why it was enabled (if active)

### Admin Handler

`WP_MCP_AI_Security_Monitor_Admin::handle_verify_root_key()`

Processes root key verification form submissions. Accessible via:
```
POST /wp-admin/admin-post.php
action=wp_mcp_ai_verify_root_key
```

## Logging

All security events are logged via `WP_MCP_AI_Logger`:

### Event Types

- `root_key_enabled` - Key requirement enabled
- `root_key_disabled` - Key requirement disabled
- `root_key_verification_success` - Successful key verification
- `root_key_verification_failed` - Failed verification attempt
- `root_key_lockout` - Lockout triggered

### Log Data

Each log entry includes:
- Event type
- Timestamp
- User ID (if applicable)
- IP address
- Contextual information (reason, attempt count, etc.)

## Best Practices

### Security

1. **Strong Keys**: Use long (32+ characters), random keys
2. **Secure Storage**: Store keys in password managers, not plain text files
3. **Limited Access**: Only share with trusted administrators
4. **Regular Rotation**: Consider rotating keys periodically
5. **Monitor Logs**: Review verification attempts regularly

### Operational

1. **Document Key Location**: Ensure team knows where to find the key
2. **Emergency Access**: Maintain offline backup of the key
3. **Test Recovery**: Periodically test the unlock process
4. **Clear Communication**: Document when and why key is enabled

### Recovery

If you lose the root security key:

1. Access your `wp-config.php` file
2. Remove or comment out the `WP_MCP_AI_ROOT_SECURITY_KEY` constant
3. Generate and define a new key
4. The requirement will remain enabled, but can no longer be verified
5. Manually clear the requirement via database:
   ```sql
   DELETE FROM wp_options WHERE option_name = 'wp_mcp_ai_root_key_required';
   ```

## Troubleshooting

### "Key is required but I don't have it"

1. Check `wp-config.php` for the `WP_MCP_AI_ROOT_SECURITY_KEY` constant
2. Contact your site administrator or hosting provider
3. As a last resort, remove the constant and clear the database option

### "Too many failed attempts"

1. Wait 15 minutes for the lockout to expire
2. Verify you have the correct key from `wp-config.php`
3. Check for typos or extra spaces when entering the key

### "Plugin won't initialize after verification"

1. Verify the emergency shutdown is also cleared
2. Check for other security restrictions
3. Review error logs for additional issues

## Example Usage

### Scenario: Emergency Shutdown Response

1. Security monitor detects suspicious activity
2. Emergency shutdown is triggered automatically
3. Root key requirement is enabled (if configured)
4. Admin receives email notification
5. Admin investigates the security issue
6. After resolving the issue:
   - Admin clears emergency shutdown
   - Admin verifies root security key
   - Plugin functionality is restored

### Scenario: Scheduled Maintenance

```php
// Before maintenance
$security_key = WP_MCP_AI_Root_Security_Key::get_instance();
$security_key->enable_key_requirement( 'Scheduled security audit' );

// Perform maintenance...

// After maintenance
$result = $security_key->disable_key_requirement( WP_MCP_AI_ROOT_SECURITY_KEY );
if ( is_wp_error( $result ) ) {
    error_log( 'Failed to disable key requirement: ' . $result->get_error_message() );
}
```

## See Also

- [Nefarious Usage Monitor Documentation](./nefarious-usage-monitor.md)
- [Security Best Practices](../../guides/developer/best-practices/BEST_PRACTICES.md)
- [Emergency Shutdown Procedures](./emergency-shutdown.md)
