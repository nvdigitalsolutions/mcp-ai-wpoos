# WP MCP AI Security Key Setup (Optional)

## Overview

**By default, the security key check is DISABLED** and the plugin works without any additional requirements.

For enhanced security in production environments, you can optionally enable an authorization key requirement. When enabled, WP Open Operator System requires an authorization key file in your WordPress root directory before the plugin will load. This prevents unauthorized activation and provides an additional security layer.

## Enabling the Security Key Check

### 1. Enable in wp-config.php

Add this constant to your `wp-config.php` file:

```php
define( 'WP_MCP_AI_REQUIRE_KEY', true );
```

### 2. Generate the Key File

Create a file named `.wp-mcp-ai-key` in your WordPress root directory (the same directory where `wp-config.php` is located).

### 2. Add the Authorization Key

Add the following key to the `.wp-mcp-ai-key` file:

```
WP_MCP_AI_AUTH_eb9923d6159ee0283ffdcfcb1bbfb821
```

**Important:** The key must be exactly as shown above, with no extra spaces or line breaks.

### 3. Set Proper Permissions

Secure the key file with appropriate permissions:

```bash
chmod 600 .wp-mcp-ai-key
```

This ensures only the web server can read the file.

### 4. Verify Setup

After enabling the check and creating the key file:
1. Refresh your WordPress admin dashboard
2. The plugin should load without any error messages
3. If you see an error about a missing or invalid key, verify the file exists and contains the correct key

**Note:** If you have NOT added `define( 'WP_MCP_AI_REQUIRE_KEY', true );` to wp-config.php, the plugin will work without the key file

## File Location

The key file must be in the WordPress root directory:

```
/path/to/wordpress/
├── wp-config.php
├── .wp-mcp-ai-key          ← Create this file here
├── wp-content/
│   └── plugins/
│       └── wp-mcp-ai/
└── ...
```

## Disabling the Security Check

The security key check is disabled by default. If you previously enabled it and want to disable it again, simply remove or comment out the constant in your `wp-config.php`:

```php
// define( 'WP_MCP_AI_REQUIRE_KEY', true );  // Commented out = disabled
```

Or remove the line entirely.

## Troubleshooting

### Error: "Authorization key file missing"

The plugin cannot find `.wp-mcp-ai-key` in your WordPress root directory.

**Solution:**
1. Verify you created the file in the correct location (same directory as `wp-config.php`)
2. Check the filename is exactly `.wp-mcp-ai-key` (note the leading dot)
3. Ensure the web server has read permissions on the file

### Error: "Invalid authorization key"

The key in your file doesn't match the expected value.

**Solution:**
1. Verify the key is exactly: `WP_MCP_AI_AUTH_eb9923d6159ee0283ffdcfcb1bbfb821`
2. Check for extra spaces, line breaks, or hidden characters
3. Use the sample file from the plugin directory: `.wp-mcp-ai-key.sample`

### File Permissions Issues

If the web server cannot read the file:

**Solution:**
```bash
# Make sure the file is owned by the web server user
chown www-data:www-data .wp-mcp-ai-key

# Set proper permissions
chmod 600 .wp-mcp-ai-key
```

(Replace `www-data` with your web server's user if different)

## Security Best Practices

1. **Keep the key file secure** - Never commit it to version control
2. **Use proper file permissions** - Only the web server should be able to read it
3. **Different keys per environment** - While this version uses a fixed key, future versions may support custom keys per installation
4. **Monitor access** - The key file should never be accessible via HTTP

## Why This Security Measure?

This additional security layer:
- Prevents unauthorized plugin activation on compromised servers
- Provides explicit control over which installations can run the plugin
- Adds defense-in-depth security alongside WordPress's existing protections
- Ensures intentional deployment in production environments

## Quick Setup Script

Copy and run this script in your WordPress root directory:

```bash
#!/bin/bash
# Quick setup script for WP MCP AI security key

echo "WP_MCP_AI_AUTH_eb9923d6159ee0283ffdcfcb1bbfb821" > .wp-mcp-ai-key
chmod 600 .wp-mcp-ai-key
echo "✓ Security key file created successfully!"
```

## Support

If you continue to experience issues after following these steps:
1. Check the [main troubleshooting guide](docs/deployment-troubleshooting.md)
2. Review [GitHub issues](https://github.com/nvdigitalsolutions/wp-mcp-ai/issues)
3. Enable WordPress debug mode to see detailed error messages
