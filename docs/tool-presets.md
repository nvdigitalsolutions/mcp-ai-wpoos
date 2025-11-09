# Tool Presets Feature

## Overview

The Tool Presets feature provides 20 pre-configured sets of tools tailored for common use cases and roles. This allows administrators to quickly set up assistants without manually selecting individual tools.

## Location

The preset selector appears in the **Available Tools** meta box on the AI Assistant edit screen, positioned above the "Disable pre-built prompt shortcuts" option.

## Usage

1. Navigate to **AI Assistants** → **Edit Assistant** (or create a new assistant)
2. Scroll to the **Available Tools** section
3. Under **Quick Setup with Presets**, select a preset from the dropdown
4. Click **Apply Preset** to automatically enable the appropriate tools
5. Optionally fine-tune the selection by manually checking/unchecking individual tools

## Available Presets

### 1. Content Creator
**Purpose**: Tools for creating and managing blog posts, pages, and media  
**Includes**: submit_document_prompt, search_content, get_recent_posts, save_post, get_rankmath_seo, generate_openai_image, generate_gemini_image, get_elementor_templates

### 2. Digital Marketer
**Purpose**: Analytics, SEO, social media insights, and marketing automation  
**Includes**: google_analytics_report, get_facebook_instagram_insights, get_rankmath_seo, web_search, create_google_calendar_event, generate_openai_image, save_post, search_content

### 3. E-commerce Manager
**Purpose**: Product management, orders, and WooCommerce tools  
**Includes**: create_woo_product, list_woo_orders, get_woo_product_stats, search_content, generate_openai_image, web_search, get_import_duty

### 4. IT Manager / SysAdmin
**Purpose**: Site operations, security, monitoring, and system maintenance  
**Includes**: get_site_health, get_environment_status, get_system_logs, get_update_status, check_site_security, check_wp_cli, purge_cache, purge_cloudflare_cache, create_cron_job, list_cron_jobs, get_cron_job, delete_cron_job, create_wpcode_snippet

### 5. Developer / DevOps
**Purpose**: Code management, debugging, API integration, and development tools  
**Includes**: check_wp_cli, create_wpcode_snippet, get_system_logs, get_environment_status, create_cron_job, list_cron_jobs, generate_simple_jwt_token, generate_auth0_token, probe_chat, probe_remote_mcp, get_jetengine_items, list_jetengine_rest_routes, invoke_jetengine_route

### 6. Customer Support
**Purpose**: User management, help desk operations, and customer communications  
**Includes**: get_user_info, search_content, get_recent_posts, web_search, create_google_calendar_event, send_email, get_site_summary

### 7. Data Analyst
**Purpose**: Analytics, reporting, and data insights  
**Includes**: google_analytics_report, get_facebook_instagram_insights, get_woo_product_stats, list_woo_orders, get_site_summary, search_content, get_jetengine_items, count_tokens

### 8. SEO Specialist
**Purpose**: Search optimization, content analysis, and SEO tools  
**Includes**: get_rankmath_seo, save_post, search_content, get_recent_posts, web_search, submit_document_prompt, google_analytics_report

### 9. Social Media Manager
**Purpose**: Social media insights, content scheduling, and engagement  
**Includes**: get_facebook_instagram_insights, generate_openai_image, generate_gemini_image, save_post, create_google_calendar_event, web_search, search_content

### 10. Project Manager
**Purpose**: Task scheduling, automation workflows, and coordination  
**Includes**: create_google_calendar_event, create_cron_job, list_cron_jobs, get_cron_job, run_openai_external_action, send_email, get_user_info, get_site_summary

### 11. Media Producer
**Purpose**: Image generation, audio transcription, and media management  
**Includes**: generate_openai_image, generate_gemini_image, edit_gemini_image, generate_openai_speech, transcribe_openai_audio, search_attachments, save_post

### 12. Automation Specialist
**Purpose**: Workflow automation, scheduled tasks, and integrations  
**Includes**: run_openai_external_action, run_crawl4ai_job, create_cron_job, list_cron_jobs, get_cron_job, delete_cron_job, create_google_calendar_event, create_wpcode_snippet, probe_remote_mcp, query_remote_site

### 13. Research Analyst
**Purpose**: Web research, data gathering, and external data sources  
**Includes**: web_search, crawl4ai_price_lookup, run_crawl4ai_job, get_gdacs_events, get_open_meteo_forecast, reliefweb_reports, search_content, submit_document_prompt

### 14. Security Specialist
**Purpose**: Security monitoring, threat detection, and compliance  
**Includes**: check_site_security, get_site_health, get_system_logs, get_environment_status, get_update_status, generate_simple_jwt_token, generate_auth0_token

### 15. API Integration Specialist
**Purpose**: REST API management, JetEngine, and external integrations  
**Includes**: list_jetengine_rest_routes, invoke_jetengine_route, get_jetengine_items, probe_chat, probe_remote_mcp, query_remote_site, generate_simple_jwt_token, generate_auth0_token

### 16. Emergency/Crisis Responder
**Purpose**: Real-time alerts, weather, and emergency data monitoring  
**Includes**: get_gdacs_events, get_nhc_active_storms, get_open_meteo_forecast, reliefweb_reports, web_search, send_email, save_post

### 17. General Purpose Assistant
**Purpose**: Balanced set of tools for general website management  
**Includes**: search_content, get_recent_posts, save_post, get_user_info, get_site_summary, web_search, generate_openai_image, send_email

### 18. Communication Manager
**Purpose**: Email campaigns, notifications, and user communications  
**Includes**: send_email, get_user_info, search_content, save_post, create_google_calendar_event, web_search

### 19. Site Administrator
**Purpose**: Full site management with comprehensive access  
**Includes**: get_site_health, get_site_summary, get_environment_status, get_system_logs, get_update_status, get_user_info, save_post, search_content, purge_cache, create_cron_job, list_cron_jobs

### 20. Local Business Owner
**Purpose**: Simple content management, customer service, and basic operations  
**Includes**: save_post, search_content, get_recent_posts, generate_openai_image, send_email, create_google_calendar_event, web_search

## Customization

### For Developers

You can add custom presets or modify existing ones using the `wp_mcp_ai_tool_presets` filter:

```php
add_filter( 'wp_mcp_ai_tool_presets', function( $presets ) {
    // Add a custom preset
    $presets['custom_role'] = array(
        'label' => __( 'Custom Role', 'your-textdomain' ),
        'description' => __( 'Description of your custom preset', 'your-textdomain' ),
        'tools' => array(
            'tool_slug_1',
            'tool_slug_2',
            'tool_slug_3',
        ),
    );
    
    // Modify an existing preset
    $presets['content_creator']['tools'][] = 'my_custom_tool';
    
    return $presets;
} );
```

### Notes

- Applying a preset will **uncheck all currently selected tools** and then check only the tools in the preset
- You can manually adjust the tool selection after applying a preset
- The preset selection does not persist - it's a one-time action to quickly configure tools
- Some tools in presets may not be available in the base version or if required plugins aren't installed

## UI Behavior

- **Dropdown**: Shows all available presets with their labels
- **Description**: Displays below the dropdown when a preset is selected (before applying)
- **Apply Button**: Triggers the tool selection changes
- **Success Notification**: Shows temporarily after applying a preset with count of enabled tools
- **Visual Feedback**: The preset selector has a light blue background to distinguish it from other settings

## Screenshots

*(Screenshots to be added during manual testing)*

## Technical Implementation

- **Method**: `get_tool_presets()` in `WP_MCP_AI_Assistant_CPT` class
- **Filter Hook**: `wp_mcp_ai_tool_presets`
- **JavaScript**: Inline in the tools metabox, handles preset application
- **CSS**: Inline styles matching existing UI components
- **Location**: Above "Disable pre-built prompt shortcuts" checkbox in tools metabox
