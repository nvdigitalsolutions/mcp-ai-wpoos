# Multisite Support

WP oOS fully supports WordPress multisite installations as of version 1.0.0.

## Features

### Network-Wide Activation
- The plugin can be activated network-wide from the Network Admin > Plugins menu
- When activated network-wide, the plugin is automatically activated on all existing sites
- The plugin is automatically activated on new sites created after network activation

### Per-Site Configuration
- Each site in the network maintains its own settings and configuration
- OpenAI API keys, assistants, and all other settings are stored per-site
- This allows different sites in the network to have completely independent configurations

### Network-Wide Deactivation
- When deactivated network-wide, the plugin is deactivated on all sites in the network
- Rewrite rules are properly flushed on each site during deactivation

### Network-Wide Uninstall
- When the plugin is uninstalled with "Delete on Uninstall" enabled, data is removed from all sites
- This includes:
  - All AI Assistant posts
  - Plugin settings
  - Credentials
  - Cron job settings

## Installation on Multisite

### Network Activation
1. Upload the plugin to `/wp-content/plugins/` directory
2. Go to Network Admin > Plugins
3. Click "Network Activate" for WP oOS
4. Configure settings on each site individually via Settings > WP oOS

### Individual Site Activation
1. Upload the plugin to `/wp-content/plugins/` directory
2. Go to the individual site's Admin > Plugins
3. Click "Activate" for WP oOS
4. Configure settings via Settings > WP oOS

## Technical Implementation

### Activation Hooks
- `wp_mcp_ai_activate( $network_wide )` - Main activation handler
- `wp_mcp_ai_activate_single_site()` - Single site activation logic
- Hooks into `wp_initialize_site` and `wpmu_new_blog` for new site activation

### Deactivation Hooks
- `wp_mcp_ai_deactivate( $network_wide )` - Main deactivation handler
- `wp_mcp_ai_deactivate_single_site()` - Single site deactivation logic

### Uninstall Hooks
- `wp_mcp_ai_uninstall()` - Iterates through all sites on multisite
- `wp_mcp_ai_uninstall_single_site()` - Handles cleanup for a single site

### Site Switching
The plugin uses WordPress core functions for safe site switching:
- `switch_to_blog( $blog_id )` - Switch to a specific site
- `restore_current_blog()` - Restore previous site context

## Best Practices

1. **Test on a staging environment first** - Always test network activation on a staging multisite before production
2. **Configure per-site** - Remember that settings are per-site, so you'll need to configure each site individually
3. **Monitor new sites** - When creating new sites, the plugin will be automatically activated if network-activated
4. **Backup before uninstall** - If "Delete on Uninstall" is enabled, ensure you have backups as data will be removed from all sites

## Compatibility

- Requires WordPress 6.0 or higher
- Compatible with WordPress multisite installations
- Works with both subdomain and subdirectory multisite setups
- Settings are stored using site-specific options, not network options
