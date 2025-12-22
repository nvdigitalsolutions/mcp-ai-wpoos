# Tool Selection Presets

## Overview

Tool selection presets allow administrators to quickly configure AI Assistants for common use cases with a single click. Instead of manually selecting individual tools, you can choose from predefined presets that automatically select the appropriate tools for specific tasks.

## Location

The presets are located in the **Available Tools** meta box when creating or editing an AI Assistant in the WordPress admin (`/wp-admin/post.php?post=XXX&action=edit` for post type `mcp_ai_assistant`).

The preset section appears under the "Disable pre-built prompt shortcuts from selected tools" checkbox, labeled **Quick Tool Selection Presets**.

## Available Presets

### Content Writing
**Purpose:** Tools for creating and managing content, posts, and pages  
**Tool count:** 14 tools  
**Tools included:**
- search_content - Search WordPress content
- search_attachments - Find media files
- semantic_content_search - AI-powered semantic search
- get_recent_posts - Retrieve latest posts
- save_post - Create/update posts
- create_post - Create new posts
- get_rankmath_seo - SEO analysis
- generate_openai_image - AI image generation (DALL-E)
- generate_gemini_image - AI image generation (Gemini)
- web_search - Web research
- moderate_content - OpenAI content moderation
- analyze_comment_content - Comment analysis
- generate_video_caption - Auto-generate video captions
- transcribe_openai_audio - Audio transcription

### E-commerce Support
**Purpose:** WooCommerce and product management tools  
**Tool count:** 12 tools  
**Tools included:**
- get_woo_recent_orders - Recent WooCommerce orders
- get_woo_products - Product catalog access
- create_woo_product - Create new products
- woo_products *(Pro)* - Advanced product management
- woo_orders *(Pro)* - Advanced order management
- scrape_product - Extract product data from websites
- crawl4ai_price_lookup - Price comparison across retailers
- lookup_product_price *(Pro)* - Product pricing research
- product_actualization *(Pro)* - Product data updates
- get_import_duty *(Pro)* - International shipping duties
- send_group_email - Bulk customer emails
- send_mailjet_email *(Pro)* - Transactional emails

### Site Management
**Purpose:** WordPress core management and monitoring tools  
**Tool count:** 17 tools  
**Tools included:**
- get_site_summary - Site overview and statistics
- get_system_logs - System error logs
- get_update_status - Available updates
- get_site_health - WordPress health check
- get_environment_status - Server environment info
- check_site_security - Security audit
- get_user_info - User information
- purge_cache - Clear all caches
- purge_cloudflare_cache *(Pro)* - Cloudflare cache clearing
- purge_varnish_cache *(Pro)* - Varnish cache clearing
- create_cron_job - Schedule automated tasks
- list_cron_jobs - View scheduled tasks
- get_cron_job - Get cron job details
- delete_cron_job - Remove scheduled tasks
- open_openai_logs - OpenAI API logs
- open_openai_usage - OpenAI usage statistics
- openai_usage_analytics - Usage analytics dashboard

### SEO & Marketing
**Purpose:** SEO analysis and social media management tools  
**Tool count:** 17 tools  
**Tools included:**
- get_rankmath_seo - Rank Math SEO analysis
- web_search - Web research
- post_facebook_instagram *(Pro)* - Post to Facebook/Instagram
- post_linkedin_update *(Pro)* - Share on LinkedIn
- post_google_business_update *(Pro)* - Google Business posts
- post_tiktok_video *(Pro)* - Upload TikTok videos
- get_facebook_instagram_insights *(Pro)* - FB/IG analytics
- get_linkedin_insights *(Pro)* - LinkedIn analytics
- get_google_business_insights *(Pro)* - Google Business insights
- get_tiktok_insights *(Pro)* - TikTok analytics
- google_analytics_report - Legacy GA integration
- get_google_analytics_report *(Pro)* - Google Analytics 4
- create_google_calendar_event *(Pro)* - Calendar scheduling
- send_whatsapp_message *(Pro)* - WhatsApp messaging
- send_telegram_message *(Pro)* - Telegram notifications
- schedule_notify_sms *(Pro)* - SMS scheduling
- search_gmail *(Pro)* - Gmail search

### Development
**Purpose:** Code snippets, CLI, and technical development tools  
**Tool count:** 24 tools  
**Tools included:**
- create_wpcode_snippet *(Pro)* - Create code snippets
- check_wp_cli *(Pro)* - WP-CLI availability check
- get_system_logs - System logs access
- count_tokens - Token usage estimation
- probe_chat - Test chat functionality
- probe_remote_mcp - Test remote MCP servers
- query_remote_site - Query external WordPress sites
- get_model_information - AI model details
- list_available_models - Available AI models
- suggest_best_model - Model recommendations
- run_openai_external_action - OpenAI actions
- create_assistant - Create AI assistants
- create_batch - Create batch jobs
- get_batch_status - Check batch status
- list_batches - List all batches
- monitor_batch - Monitor batch progress
- generic_rest *(Pro)* - Generic REST API calls
- github_repository_operations *(Pro)* - GitHub repo management
- list_github_repositories *(Pro)* - List GitHub repos
- manage_github_codespace *(Pro)* - Codespace management
- install_and_activate_plugin *(Pro)* - Plugin installation
- install_and_activate_theme *(Pro)* - Theme installation
- site_creator *(Pro)* - Automated site creation
- update_option - WordPress options management

### Data & Analytics
**Purpose:** Data collection, reporting, and analytics tools  
**Tool count:** 26 tools  
**Tools included:**
- get_jetengine_items - JetEngine CCT items
- list_jetengine_rest_routes - JetEngine REST routes
- invoke_jetengine_route - Call JetEngine routes
- jetengine *(Pro)* - JetEngine operations
- get_jetformbuilder_forms - Form definitions
- get_jetformbuilder_submissions - Form submissions
- google_analytics_report - Legacy GA data
- quickbooks_report - Legacy QuickBooks
- get_quickbooks_report *(Pro)* - QuickBooks Online
- create_vector_store - Create vector databases
- get_vector_store - Retrieve vector stores
- list_vector_stores - List all vector stores
- manage_vector_store_files - Vector store file management
- create_text_embeddings - Generate text embeddings
- batch_embed_content - Bulk embedding generation
- submit_document_prompt - Document Q&A
- analyze_file_suitability - File analysis
- list_openai_files - OpenAI file storage
- get_openai_file_details - File metadata
- reliefweb_reports - Humanitarian crisis data
- get_gdacs_events - Global disaster alerts
- get_nhc_active_storms - Hurricane tracking
- get_open_meteo_forecast - Weather forecasts
- gemini_geospatial_query - Geospatial queries
- geocode_address - Address geocoding
- search_places - Google Places search

### Design Professional
**Purpose:** CAD, rendering, 3D modeling, branding, and visual design tools  
**Tool count:** 28 tools  
**Tools included:**
- generate_openai_image - DALL-E image generation
- generate_gemini_image - Gemini image generation
- edit_gemini_image - Edit images with Gemini
- edit_openai_image - Edit images with DALL-E
- create_image_variation - Image variations
- generate_veo_video - Google Veo video generation
- generate_sora_video - OpenAI Sora video generation
- check_video_status - Video generation status
- resize_image - Image resizing
- crop_image - Image cropping
- rotate_image - Image rotation
- convert_image_format - Format conversion
- remove_background *(Pro)* - Background removal
- create_chart - Data visualization charts
- generate_music - AI music generation
- generate_openai_speech - Text-to-speech
- generate_jukebox_music *(Pro)* - OpenAI Jukebox
- check_jukebox_status *(Pro)* - Jukebox status
- analyze_video - Video content analysis
- extract_video_frames *(Pro)* - Frame extraction
- get_video_metadata *(Pro)* - Video file metadata
- vision_object_localization - Object detection
- vision_product_search - Visual product search
- generate_image_alt_text - AI alt text generation
- generate_image_caption - AI image captions
- get_elementor_templates - Elementor templates
- import_elementor_template_kit - Template kit import
- elementor *(Pro)* - Elementor operations

### AI/ML Operations
**Purpose:** AI model management, embeddings, vector stores, and batch operations  
**Tool count:** 20 tools  
**Tools included:**
- get_model_information - AI model details
- list_available_models - Available AI models
- suggest_best_model - Model recommendations
- count_tokens - Token counting/estimation
- create_vector_store - Vector database creation
- get_vector_store - Vector store retrieval
- list_vector_stores - List vector databases
- manage_vector_store_files - Vector file management
- create_text_embeddings - Text embedding generation
- batch_embed_content - Bulk embeddings
- create_batch - Create batch operations
- get_batch_status - Batch status checking
- list_batches - List all batches
- monitor_batch - Batch monitoring
- list_openai_files - File storage listing
- get_openai_file_details - File metadata
- submit_document_prompt - Document Q&A
- analyze_file_suitability - File analysis
- moderate_content - Content moderation
- create_assistant - Assistant creation

### Media Production
**Purpose:** Video, audio, and multimedia content creation and editing  
**Tool count:** 22 tools  
**Tools included:**
- generate_veo_video - Google Veo video generation
- generate_sora_video - OpenAI Sora video generation
- check_video_status - Video generation status
- analyze_video - Video content analysis
- extract_video_frames *(Pro)* - Frame extraction from video
- get_video_metadata *(Pro)* - Video file information
- generate_video_caption - Auto-generate captions
- generate_openai_speech - Text-to-speech conversion
- generate_music - AI music generation
- generate_jukebox_music *(Pro)* - OpenAI Jukebox music
- check_jukebox_status *(Pro)* - Jukebox installation check
- transcribe_openai_audio - Audio transcription
- generate_openai_image - Image generation (DALL-E)
- generate_gemini_image - Image generation (Gemini)
- edit_gemini_image - Image editing with AI
- edit_openai_image - Image editing with DALL-E
- create_image_variation - Create image variations
- remove_background *(Pro)* - Remove image backgrounds
- resize_image - Resize images
- crop_image - Crop images
- rotate_image - Rotate images
- convert_image_format - Convert image formats

## How to Use

1. Navigate to **AI Assistants → Edit Assistant** (or create a new one)
2. Scroll to the **Available Tools** meta box
3. Find the **Quick Tool Selection Presets** section
4. Click on any preset button to automatically select all tools in that preset
5. The page will scroll to the tools list and highlight your selection
6. You can then make additional adjustments by manually selecting/deselecting individual tools

**Note:** Clicking a preset will **replace** your current tool selection with the preset's tools.

## Customization

### Adding Custom Presets

Developers can add custom presets using the `wp_mcp_ai_tool_presets` filter:

```php
add_filter( 'wp_mcp_ai_tool_presets', function( $presets ) {
    $presets['custom_preset'] = array(
        'name'        => __( 'Custom Preset', 'my-plugin' ),
        'description' => __( 'My custom tool selection', 'my-plugin' ),
        'tools'       => array(
            'search_content',
            'save_post',
            'get_site_summary',
        ),
    );
    return $presets;
} );
```

### Removing Existing Presets

```php
add_filter( 'wp_mcp_ai_tool_presets', function( $presets ) {
    unset( $presets['ecommerce'] ); // Remove E-commerce preset
    return $presets;
} );
```

### Modifying Preset Tools

```php
add_filter( 'wp_mcp_ai_tool_presets', function( $presets ) {
    if ( isset( $presets['content_writing'] ) ) {
        // Add a tool to the Content Writing preset
        $presets['content_writing']['tools'][] = 'my_custom_tool';
    }
    return $presets;
} );
```

## Technical Details

### Data Structure

Each preset is an array with the following structure:

```php
array(
    'preset_key' => array(
        'name'        => 'Preset Name',           // Required: Display name
        'description' => 'Preset description',    // Required: Tooltip text
        'tools'       => array( 'tool1', 'tool2' ), // Required: Array of tool slugs
    ),
)
```

### Validation

- Presets only appear if they contain at least one available tool
- Tool slugs are validated against registered tools
- Invalid tool references are silently ignored
- The UI only shows presets with valid tools

### JavaScript Functionality

The preset buttons use vanilla JavaScript to:
1. Uncheck all currently selected tools
2. Check all tools in the selected preset
3. Trigger change events to update the UI
4. Provide visual feedback (button color change)
5. Scroll to the tools list for easy verification

## Accessibility

- All preset buttons have `title` attributes with descriptions
- Buttons are keyboard accessible
- Changes trigger proper DOM events for screen reader compatibility
- Visual feedback is provided for all interactions

## Browser Compatibility

The preset functionality uses standard JavaScript (ES5+) and is compatible with:
- All modern browsers (Chrome, Firefox, Safari, Edge)
- Internet Explorer 11 (with polyfills)
- Mobile browsers

## Performance

- Presets are generated server-side with minimal overhead
- JavaScript is loaded only once per page
- Tool validation happens at render time
- No AJAX requests are made for preset selection

## Preset Summary

As of December 2025, the plugin offers **9 comprehensive presets** covering:

| Preset | Tools | Focus Area |
|--------|-------|------------|
| Content Writing | 14 | Blog posts, articles, content creation |
| E-commerce Support | 12 | WooCommerce, products, orders |
| Site Management | 17 | WordPress admin, monitoring, caching |
| SEO & Marketing | 17 | Social media, SEO, analytics, communication |
| Development | 24 | Code, APIs, GitHub, site deployment |
| Data & Analytics | 26 | Data collection, embeddings, external APIs |
| Design Professional | 28 | Images, video, audio, visual content |
| AI/ML Operations | 20 | Vector stores, embeddings, batch operations |
| Media Production | 22 | Video/audio creation and editing |

**Total tools covered:** 132 tools across all presets (some tools appear in multiple presets)

## Changelog

### December 22, 2025 - Major Preset Enhancement
- **Expanded all 7 existing presets** with 77 new tools
- **Added 2 new presets**: AI/ML Operations and Media Production
- **Content Writing**: Added moderation, transcription, semantic search (8 → 14 tools)
- **E-commerce**: Added product research, price lookup, import duties (5 → 12 tools)
- **Site Management**: Added cache variants, OpenAI usage tracking (9 → 17 tools)
- **SEO & Marketing**: Added TikTok, WhatsApp, Telegram, Gmail (7 → 17 tools)
- **Development**: Added AI models, batch ops, GitHub, site creator (6 → 24 tools)
- **Data & Analytics**: Added vector stores, embeddings, weather, geospatial (7 → 26 tools)
- **Design Professional**: Added Sora, audio, Elementor, background removal (18 → 28 tools)
- **AI/ML Operations** *(New)*: Embeddings, vector stores, batch operations (20 tools)
- **Media Production** *(New)*: Video, audio, and multimedia workflows (22 tools)
- Updated documentation with detailed tool descriptions
- Improved tool coverage from 55 to 132 tools (140% increase)
