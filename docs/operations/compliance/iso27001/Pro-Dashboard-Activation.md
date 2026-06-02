# Pro Dashboard Activation Guide

This guide explains how to enable the NV oOS Pro Dashboard using the `WP_MCP_AI_PRO_DASHBOARD_ENABLED` constant in `wp-config.php`.

## Overview

The Pro Dashboard provides enterprise-grade ISO/IEC 27001 compliance monitoring, reporting, and management tools. There are two methods to activate it:

1. **Recommended:** wp-config.php constant (this guide)
2. **Legacy:** WordPress filter (backward compatibility)

## Method 1: wp-config.php Constant (Recommended)

### Why Use the Constant?

- **Standard WordPress practice:** Follows established WordPress patterns for configuration
- **Early loading:** Loaded during WordPress bootstrap before plugins initialize
- **Clean and simple:** No need for additional code snippets or filters
- **Better security:** Configuration is in wp-config.php, not in the database
- **Performance:** No filter overhead on every check

### How to Enable

1. Open your `wp-config.php` file (located in your WordPress root directory)
2. Add this line anywhere before `/* That's all, stop editing! */`:

```php
define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );
```

3. Save the file
4. Refresh your WordPress admin dashboard

### Example wp-config.php

```php
<?php
/**
 * WordPress configuration file
 */

// Database settings
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'password' );
define( 'DB_HOST', 'localhost' );

// ... other configuration ...

// Enable NV oOS Pro Dashboard
define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );

/* That's all, stop editing! Happy publishing. */
```

### Verification

After enabling the constant:

1. Log in to WordPress admin
2. Look for "NV oOS Pro" in the admin menu (left sidebar)
3. The Pro Dashboard should be accessible without any "upgrade" notices

## Method 2: WordPress Filter (Legacy)

For backward compatibility, you can still use the filter method:

### Using a Code Snippets Plugin

1. Install a code snippets plugin (like WPCode)
2. Create a new snippet with this code:

```php
add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
```

3. Activate the snippet

### Using theme functions.php

Add this to your theme's `functions.php` file:

```php
/**
 * Enable NV oOS Pro Dashboard
 */
add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
```

**Note:** This method is maintained for backward compatibility but the constant method is recommended for new installations.

## Disabling Pro Dashboard

To disable the Pro Dashboard:

### If using constant:

1. Open `wp-config.php`
2. Remove the line or change it to:

```php
define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', false );
```

### If using filter:

1. Remove or deactivate the code snippet
2. Or remove the filter from `functions.php`

## Priority Order

If both methods are configured, the system checks in this order:

1. **First:** `WP_MCP_AI_PRO_DASHBOARD_ENABLED` constant
2. **Second:** `wp_mcp_ai_pro_dashboard_available` filter
3. **Third:** License validation (if Pro license is installed)

If the constant is `true`, the Pro Dashboard will be enabled regardless of filter or license status.

## Troubleshooting

### Pro Dashboard not appearing

1. Verify the constant is defined correctly in `wp-config.php`
2. Check for PHP syntax errors in `wp-config.php`
3. Clear WordPress caches
4. Verify you're logged in as an administrator
5. Check WordPress debug logs for errors

### Syntax errors

Common mistakes:

```php
// ❌ Wrong - missing semicolon
define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true )

// ❌ Wrong - outside PHP tags
define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );

// ✅ Correct
define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );
```

## Security Considerations

- Keep `wp-config.php` secure with proper file permissions (0644 or 0640)
- Never commit `wp-config.php` to version control
- Use environment-specific configuration for staging/production
- The constant itself doesn't bypass WordPress user capabilities - administrators still need `manage_options` capability

## Development vs Production

### Development Environment

Enable Pro Dashboard for testing:

```php
// In wp-config.php
define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );
```

### Production Environment

For production, consider using license validation instead:

1. Obtain a Pro license key
2. Enter it in **Settings → NV oOS → License**
3. The system will validate automatically

Or use the constant if you prefer configuration-based activation.

## Integration with License System

The constant **overrides** license validation. This means:

- Constant = `true` → Pro Dashboard enabled (no license check)
- Constant = `false` or undefined → License validation runs
- Constant = undefined and valid license → Pro Dashboard enabled

## Related Documentation

- [Pro Dashboard Implementation](PRO-DASHBOARD-IMPLEMENTATION.md) - Complete implementation details
- [Pro Dashboard Design](Pro-Dashboard-Design.md) - Design specification
- [Certification Strategy](Certification-Strategy.md) - ISO 27001 certification context

## Support

For issues or questions:

- Check [Troubleshooting Guide](../../deployment-troubleshooting.md)
- Visit [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
- Contact support at support@nvdigitalsolutions.com
