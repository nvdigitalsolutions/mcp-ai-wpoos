# WP oOS - Base Version

## Overview

The WP oOS plugin runs in "base version" mode by default. This mode includes only tools and features compatible with a standard WordPress installation and excludes all tools that require third-party plugins or external API credentials.

## Base Version is Enabled by Default

Base version mode is **enabled by default**. No configuration is needed to use the base version.

## Switching to Full Version

To disable base version mode and enable the full version with all third-party integrations, add the following constant to your `wp-config.php` file before the "That's all, stop editing!" line:

```php
define( 'WP_MCP_AI_BASE_VERSION', false );
```

Alternatively, you can add this to a custom mu-plugin or your theme's functions.php file (though wp-config.php is recommended).

## What's Included in Base Version

### Core WordPress Tools

The base version includes tools that work with a standard WordPress installation without any third-party plugins:

#### Content & Knowledge
- `get_recent_posts` - Get recent posts from any post type
- `search_content` - Search posts with taxonomy and meta filters
- `search_attachments` - Search media library with capability checks
- `get_user_info` - Get user information
- `save_post` - Create or update posts
- `submit_document_prompt` - Submit documents with prompts

#### Media Generation & Transcription
- `generate_openai_image` - Generate images via OpenAI
- `generate_gemini_image` - Generate images via Gemini
- `generate_openai_speech` - Text-to-speech via OpenAI
- `transcribe_openai_audio` - Audio transcription via OpenAI

#### Research & External Data
- `web_search` - DuckDuckGo and Brave web search
- `crawl4ai_price_lookup` - Wholesale price comparisons
- `get_gdacs_events` - Global disaster alerts
- `get_open_meteo_forecast` - Weather forecasts
- `get_nhc_active_storms` - Hurricane center data
- `reliefweb_reports` - Humanitarian reports
- `get_import_duty` - Import duty lookups

#### Operations & Diagnostics
- `get_site_summary` - Site metadata overview
- `get_site_health` - WordPress site health checks
- `get_environment_status` - MCP environment status
- `get_system_logs` - System log retrieval
- `get_update_status` - WordPress/plugin update status
- `check_site_security` - Security vulnerability scanner
- `check_wp_cli` - Check WP-CLI availability
- `count_tokens` - Token counting utility
- `create_cron_job` - Schedule WP-Cron jobs
- `list_cron_jobs` - List scheduled cron jobs
- `get_cron_job` - Get specific cron job details
- `delete_cron_job` - Delete scheduled cron jobs
- `query_remote_site` - Query remote WordPress sites in mesh network
- `query_mesh_intelligent` - Intelligent mesh routing with AI-powered peer selection
- `probe_chat` - Test assistant chat endpoints
- `probe_remote_mcp` - Test remote MCP connections
- `open_openai_usage` - OpenAI usage dashboard links
- `open_openai_logs` - OpenAI log dashboard links

#### Automation
- `run_openai_external_action` - Run OpenAI external actions
- `run_crawl4ai_job` - Execute Crawl4AI jobs

#### Cache Management
- `purge_cache` - General cache purging
- `purge_cloudflare_cache` - Cloudflare cache purging
- `purge_varnish_cache` - Varnish cache purging

#### Communication (WordPress native only)
- `send_group_email` - Send emails using wp_mail()

## What's Excluded in Base Version

The following tools require third-party plugins or external services and are NOT included in base version mode:

### Third-Party Plugin Dependencies

#### WooCommerce Tools
- `create_woo_product`
- `get_woo_products`
- `get_woo_recent_orders`

#### JetEngine Tools
- `get_jetengine_items`
- `list_jetengine_rest_routes`
- `invoke_jetengine_route`

#### JetFormBuilder Tools
- `get_jetformbuilder_forms`
- `get_jetformbuilder_submissions`

#### Elementor Tools
- `get_elementor_templates`

#### RankMath SEO Tools
- `get_rankmath_seo`

#### WPCode Tools
- `create_wpcode_snippet`

### External API/Service Tools

#### Google Services
- `search_gmail`
- `create_google_calendar_event`
- `google_analytics_report`
- `get_google_business_insights`
- `post_google_business_update`

#### Social Media Platforms
- `post_facebook_instagram`
- `get_facebook_instagram_insights`
- `post_tiktok_video`
- `get_tiktok_insights`
- `post_linkedin_update`
- `get_linkedin_insights`

#### Messaging & Communication Services
- `send_mailjet_email`
- `send_telegram_message`
- `send_whatsapp_message`
- `schedule_notify_sms`

#### Business Services
- `quickbooks_report`

#### Authentication
- `generate_simple_jwt_token`

#### Google Cloud Vision
- `vision_product_search`
- `vision_object_localization`

### Excluded Integrations

When base version mode is enabled, the following integration classes are not loaded:

- JetEngine integration
- JetFormBuilder integration
- Elementor integration
- ChatKit integration
- Simple JWT Login integration
- Auth0 GitHub integration

## Customizing the Tool List

You can further customize which tools are loaded using the `wp_mcp_ai_default_tools` filter:

```php
add_filter( 'wp_mcp_ai_default_tools', function( $tools, $is_base_version ) {
    // Remove a specific tool even in full version
    unset( $tools['WP_MCP_AI_Tool_Web_Search'] );
    
    // Add a custom tool only in base version
    if ( $is_base_version ) {
        $tools['My_Custom_Tool'] = '/path/to/my-custom-tool.php';
    }
    
    return $tools;
}, 10, 2 );
```

You can also use the `wp_mcp_ai_base_version` filter to dynamically control base version mode:

```php
add_filter( 'wp_mcp_ai_base_version', function( $is_base_version ) {
    // Force base version for specific sites in multisite
    if ( is_multisite() && get_current_blog_id() === 2 ) {
        return true;
    }
    return $is_base_version;
} );
```

## Benefits of Base Version

1. **Simpler Setup**: No need to configure external API credentials or install third-party plugins
2. **Reduced Complexity**: Fewer tools means easier onboarding and management
3. **Lower Risk**: No external service dependencies or potential plugin conflicts
4. **Better Performance**: Fewer classes loaded, smaller memory footprint
5. **WordPress-Native**: Everything works with core WordPress functionality

## Migration from Base to Full Version

To upgrade from base version to the full version:

1. Add `define( 'WP_MCP_AI_BASE_VERSION', false );` to wp-config.php
2. Install any required third-party plugins (WooCommerce, JetEngine, etc.)
3. Configure API credentials in Settings → WP oOS for external services you want to use
4. The additional tools will automatically become available

## Testing Base Version

To verify base version mode is active:

1. Check the tools available in the Assistant editor
2. Look for the absence of tools requiring third-party plugins
3. Use WP-CLI: `wp mcp-ai status` (when available)

## Support

For questions about base version mode or feature requests, please open an issue on the GitHub repository.
