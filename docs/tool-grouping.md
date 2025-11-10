# Tool Grouping Structure

## Overview

The WP oOS plugin organizes its 65+ tools into 3 high-level categories based on their dependencies and requirements. This grouping helps users understand what's needed to use each tool and makes the admin UI more intuitive.

## The 3 Tool Categories

### 1. WordPress Core (`wordpress-core`)

**Description:** Tools that work with base WordPress installation without any external dependencies.

**Requirements:** Only WordPress core functionality

**Total Tools:** 25

**Tools in this group:**
- `submit_document_prompt` - Submit documents for AI processing
- `search_content` - Search WordPress content
- `search_attachments` - Search media library
- `get_recent_posts` - Retrieve recent posts
- `save_post` - Create/update posts
- `get_user_info` - Get WordPress user information
- `get_site_summary` - Get site overview
- `get_system_logs` - Access WordPress logs
- `get_update_status` - Check plugin/theme updates
- `get_site_health` - WordPress site health status
- `get_environment_status` - Server environment info
- `create_cron_job` - Create WordPress cron jobs
- `list_cron_jobs` - List scheduled tasks
- `get_cron_job` - Get specific cron job details
- `delete_cron_job` - Remove cron jobs
- `send_group_email` - Send emails via wp_mail()
- `purge_cache` - Clear WordPress object cache
- `check_wp_cli` - Verify WP-CLI availability
- `probe_chat` - Test chat functionality
- `probe_remote_mcp` - Test remote MCP connections
- `query_remote_site` - Query remote WordPress sites
- `query_mesh_intelligent` - Mesh network queries
- `check_site_security` - Security audit
- `count_tokens` - Count AI tokens

**Use Cases:**
- Content management
- Site administration
- User management
- Cron scheduling
- Basic email functionality

---

### 2. WordPress Plugins (`wordpress-plugins`)

**Description:** Tools that require specific third-party WordPress plugins to be installed and active.

**Requirements:** WordPress + specific plugin(s)

**Total Tools:** 12

**Tools in this group:**

#### Elementor Tools (1)
- `get_elementor_templates` - Requires: Elementor

#### WooCommerce Tools (3)
- `get_woo_recent_orders` - Requires: WooCommerce
- `get_woo_products` - Requires: WooCommerce
- `create_woo_product` - Requires: WooCommerce

#### JetEngine Tools (3)
- `get_jetengine_items` - Requires: JetEngine
- `list_jetengine_rest_routes` - Requires: JetEngine
- `invoke_jetengine_route` - Requires: JetEngine

#### JetFormBuilder Tools (2)
- `get_jetformbuilder_forms` - Requires: JetFormBuilder
- `get_jetformbuilder_submissions` - Requires: JetFormBuilder

#### RankMath Tools (1)
- `get_rankmath_seo` - Requires: RankMath SEO

#### WPCode Tools (1)
- `create_wpcode_snippet` - Requires: WPCode

#### Simple JWT Tools (1)
- `generate_simple_jwt_token` - Requires: Simple JWT Login or similar JWT plugin

**Use Cases:**
- E-commerce management
- Page building
- SEO optimization
- Custom post type management
- Form handling
- Code snippet management

---

### 3. External Tools (`external-tools`)

**Description:** Tools that require external API credentials or third-party service integrations.

**Requirements:** WordPress + API keys/credentials for external services

**Total Tools:** 46

**Tools in this group:**

#### OpenAI Tools (6)
- `generate_openai_image` - DALL-E image generation
- `generate_openai_speech` - Text-to-speech
- `transcribe_openai_audio` - Audio transcription
- `open_openai_usage` - API usage statistics
- `open_openai_logs` - API logs
- `run_openai_external_action` - OpenAI Actions

#### Google/Gemini Tools (8)
- `generate_gemini_image` - Gemini image generation
- `edit_gemini_image` - Gemini image editing
- `create_google_calendar_event` - Calendar integration
- `google_analytics_report` - Analytics data
- `vision_product_search` - Google Vision API product search
- `vision_object_localization` - Google Vision API object detection
- `post_google_business_update` - Google Business Profile
- `get_google_business_insights` - Google Business analytics

#### Email & Communication (5)
- `search_gmail` - Gmail API search
- `send_mailjet_email` - Mailjet email service
- `send_telegram_message` - Telegram bot API
- `schedule_notify_sms` - SMS notifications
- `send_whatsapp_message` - WhatsApp Business API

#### Social Media Publishing (4)
- `post_facebook_instagram` - Meta/Facebook API
- `post_tiktok_video` - TikTok API
- `post_linkedin_update` - LinkedIn API
- `post_google_business_update` - Google Business Profile

#### Social Media Analytics (4)
- `get_facebook_instagram_insights` - Meta/Facebook insights
- `get_tiktok_insights` - TikTok analytics
- `get_linkedin_insights` - LinkedIn analytics
- `get_google_business_insights` - Google Business insights

#### Web Scraping & External Data (9)
- `web_search` - Web search API
- `crawl4ai_price_lookup` - Crawl4AI price scraping
- `run_crawl4ai_job` - Crawl4AI web scraping
- `get_gdacs_events` - Disaster alerts
- `get_open_meteo_forecast` - Weather data
- `get_nhc_active_storms` - Hurricane data
- `reliefweb_reports` - Humanitarian reports
- `get_import_duty` - Import duty calculator
- `quickbooks_report` - QuickBooks API

#### Authentication & Caching (3)
- `generate_auth0_token` - Auth0 authentication
- `purge_cloudflare_cache` - Cloudflare CDN
- `purge_varnish_cache` - Varnish cache

**Use Cases:**
- AI-powered content generation
- Social media marketing
- Email marketing
- External data integration
- Analytics and reporting
- Media generation
- Authentication services

---

## How Grouping is Used

### In Admin UI

The grouping affects several admin interfaces:

1. **Assistant Configuration** - Tools are organized by category in the tool selection interface
2. **Tool Matrix Widget** - Elementor dashboard widget groups tools by category
3. **Settings Pages** - Admin settings may display tools grouped by category

### In Code

Access the grouping programmatically:

```php
// Get the full grouping map
$registry = WP_MCP_AI_Tool_Registry::get_instance();
$group_map = $registry->get_tool_group_map();
// Returns: array( 'tool_slug' => 'category_id', ... )

// Get category labels
$labels = $registry->get_tool_group_labels();
// Returns: array( 'wordpress-core' => 'WordPress Core', ... )

// Get tools by category
$all_tools = $registry->get_tools();
$wordpress_core_tools = array_filter( $all_tools, function( $tool ) use ( $group_map ) {
    return isset( $group_map[ $tool->get_slug() ] ) 
        && $group_map[ $tool->get_slug() ] === 'wordpress-core';
} );
```

### Filtering

Both the grouping map and labels can be customized via WordPress filters:

```php
// Customize tool grouping
add_filter( 'wp_mcp_ai_tool_group_map', function( $map ) {
    $map['my_custom_tool'] = 'wordpress-core';
    return $map;
} );

// Customize group labels
add_filter( 'wp_mcp_ai_tool_group_labels', function( $labels ) {
    $labels['my-custom-category'] = 'My Custom Category';
    return $labels;
} );
```

---

## Design Rationale

The 3-tier grouping was chosen because:

1. **Clarity** - Makes it immediately clear what dependencies each tool has
2. **User Experience** - Users can quickly identify which tools they can use based on their setup
3. **Scalability** - Easy to add new tools to appropriate categories
4. **Flexibility** - Categories are broad enough to accommodate future tools
5. **Simplicity** - Reduced from 9 categories to 3 for better usability

### Previous Grouping (9 categories)

The old system had these categories:
- `content` - Content ingestion & search
- `media` - Media generation & transcription
- `automation` - Automations & workflows
- `jetengine` - JetEngine REST utilities
- `commerce` - Commerce & finance
- `communication` - Communications & outreach
- `external-data` - External data sources
- `operations` - Site operations & maintenance
- `other` - Other tools

While granular, this created confusion about what required what. The new 3-tier system organizes tools by **dependency requirements** rather than **functional purpose**, which is more useful for administrators setting up assistants.

---

## Future Considerations

The grouping system is extensible. Potential future additions:

- **Sub-categories** - Each main category could have sub-groups for more granular organization
- **Capability Flags** - Additional metadata beyond just grouping (e.g., "requires-credentials", "local-only")
- **Dynamic Grouping** - Tools could report their own group membership
- **UI Filters** - Filter tools by group in the admin interface

---

## Related Documentation

- [Tool Reference](tool-reference.md) - Complete list of all tools with descriptions
- [Base vs Full Version](base-vs-full-comparison.md) - Comparison of tool availability
- [Assistant Configuration](assistant-storage-cpt-vs-cct.md) - How to configure assistants with tools
