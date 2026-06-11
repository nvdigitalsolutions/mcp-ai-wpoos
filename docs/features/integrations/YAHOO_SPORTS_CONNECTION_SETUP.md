# Yahoo Sports API Connection Setup

## Overview

The Yahoo Fantasy Sports API credentials are now managed in the centralized **Connections** section, making them consistent with other service integrations like Gmail, Google Drive, and Cloudflare.

## Location

**Settings → NV oOS → Tools → Connections → Yahoo Sports**

Direct URL: `wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=yahoo_sports`

## Configuration Steps

### 1. Create Yahoo Developer Application

1. Visit [Yahoo Developer Network](https://developer.yahoo.com/apps/)
2. Sign in with your Yahoo account
3. Click **Create an App**
4. Fill in the application details:
   - **Application Name**: Your fantasy football application name
   - **Application Type**: Web Application
   - **Callback Domain**: Your WordPress site domain
5. Note down your **Client ID (Consumer Key)** and **Client Secret (Consumer Secret)**

### 2. Configure Connection in WordPress

1. Navigate to **Settings → NV oOS → Tools → Connections**
2. Click on the **Yahoo Sports** tab
3. Enter your credentials:
   - **Yahoo Client ID**: Paste your Consumer Key from Yahoo Developer
   - **Yahoo Client Secret**: Paste your Consumer Secret from Yahoo Developer
4. Click **Save Settings**

### 3. Verify Configuration

The Fantasy Football tools will now automatically use these credentials. You can verify the configuration by:

1. Going to **Fantasy Football Settings** (under the ff_team post type)
2. The Yahoo API section will confirm credentials are configured in Connections
3. Use the Yahoo Fantasy Football AI tools to test authentication

## Available Tools

Once configured, the following 9 tools become available:

1. **yahoo_ff_auth** - OAuth authentication with Yahoo
2. **yahoo_ff_get_leagues** - Retrieve user's fantasy leagues
3. **yahoo_ff_get_roster** - Get team roster details
4. **yahoo_ff_get_player_stats** - Fetch player statistics
5. **yahoo_ff_trade_analyzer** - Analyze trade proposals
6. **yahoo_ff_league_standings** - View league standings with visualizations
7. **ff_generate_team_logo** - AI-powered team logo generation
8. **ff_create_league_report** - Generate comprehensive league reports
9. **ff_player_research** - Player research and watchlist management

## Migration from Legacy Settings

If you previously configured Yahoo credentials in the **Fantasy Football Settings** page, you have two options:

### Option 1: Reconfigure in Connections (Recommended)

1. Go to **Settings → NV oOS → Tools → Connections → Yahoo Sports**
2. Enter your Yahoo Client ID and Client Secret
3. Save settings
4. The new location will be used for all future operations

### Option 2: Continue Using Legacy Settings

The system maintains backward compatibility. If credentials exist in the old location (`wp_mcp_ai_fantasy_football_settings` option), they will continue to work. However, the new Connections location takes precedence if both are configured.

## Troubleshooting

### "Yahoo API credentials are not configured" Error

**Cause**: Credentials not found in either location.

**Solution**: 
1. Verify credentials are entered in **Settings → NV oOS → Tools → Connections → Yahoo Sports**
2. Ensure you clicked **Save Settings**
3. Clear any caching plugins if using them

### OAuth Redirect Issues

**Cause**: Callback URL mismatch between Yahoo app and WordPress.

**Solution**:
1. In Yahoo Developer Console, verify the Callback Domain matches your WordPress site
2. Ensure your WordPress site is accessible via the configured domain
3. If using HTTPS, ensure SSL certificate is valid

### Tools Not Detecting Credentials

**Cause**: Cache or settings not refreshed.

**Solution**:
1. Go to **Settings → NV oOS → Tools → Connections → Yahoo Sports**
2. Re-save the credentials
3. Use the `yahoo_ff_auth` tool with `action: get_status` to verify configuration

## Technical Details

### Storage Location

Credentials are stored in the `wp_mcp_ai_settings` option as:
```php
$settings = array(
    'yahoo_client_id'     => 'your_client_id',
    'yahoo_client_secret' => 'your_client_secret',
);
```

### Accessing in Tools

Tools retrieve credentials using:
```php
$settings      = WP_MCP_AI_Admin_Settings::get_settings();
$client_id     = isset( $settings['yahoo_client_id'] ) ? trim( $settings['yahoo_client_id'] ) : '';
$client_secret = isset( $settings['yahoo_client_secret'] ) ? trim( $settings['yahoo_client_secret'] ) : '';
```

With fallback to legacy options for backward compatibility:
```php
if ( empty( $client_id ) ) {
    $client_id = get_option( 'wp_mcp_ai_yahoo_client_id' );
}
```

## Related Documentation

- [Fantasy Football Toolkit Documentation](../tools/yahoo-fantasy-football-toolkit.md)
- [Fantasy Football Quick Reference](../tools/yahoo-fantasy-football-quick-reference.md)
- [OAuth Settings Architecture](../architecture/integrations/oauth-settings-architecture.md)

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/
