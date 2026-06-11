# Tool Selection Presets

## Overview

Tool selection presets allow administrators to quickly configure AI Assistants for common use cases with a single click. Instead of manually selecting individual tools, you can choose from predefined presets that automatically select the appropriate tools for specific tasks.

## Location

The presets are located in the **Available Tools** meta box when creating or editing an AI Assistant in the WordPress admin (`/wp-admin/post.php?post=XXX&action=edit` for post type `mcp_ai_assistant`).

The preset section appears under the "Disable pre-built prompt shortcuts from selected tools" checkbox, labeled **Quick Tool Selection Presets**.

## Available Presets

### AI/ML
**Purpose:** AI model management, embeddings, batches, and ML operations  
**Tools included:** (20 tools)
- list_available_models
- suggest_best_model
- get_model_information
- count_tokens
- create_text_embeddings
- batch_embed_content
- semantic_content_search
- create_batch
- list_batches
- get_batch_status
- monitor_batch
- create_vector_store
- list_vector_stores
- get_vector_store
- manage_vector_store_files
- openai_usage_analytics
- open_openai_usage
- open_openai_logs
- moderate_content
- analyze_comment_content

### Media
**Purpose:** Image, video, and audio generation, editing, and processing tools  
**Tools included:** (26 tools)
- generate_openai_image
- generate_gemini_image
- edit_gemini_image
- edit_openai_image
- create_image_variation
- resize_image
- crop_image
- rotate_image
- convert_image_format
- remove_background
- generate_image_alt_text
- generate_image_caption
- vision_object_localization
- vision_product_search
- generate_veo_video
- generate_sora_video
- check_video_status
- analyze_video
- extract_video_frames
- get_video_metadata
- generate_video_caption
- generate_music
- generate_jukebox_music *(Pro)*
- check_jukebox_status *(Pro)*
- generate_openai_speech
- transcribe_openai_audio

### Content Writing
**Purpose:** Tools for creating and managing content, posts, and pages  
**Tools included:** (15 tools)
- search_content
- search_attachments
- get_recent_posts
- save_post
- create_post
- get_rankmath_seo
- generate_openai_image
- generate_gemini_image
- web_search
- semantic_content_search
- moderate_content
- analyze_comment_content
- generate_image_caption
- generate_image_alt_text
- submit_document_prompt

### E-commerce Support
**Purpose:** WooCommerce and product management tools  
**Tools included:** (13 tools)
- get_woo_recent_orders
- get_woo_products
- create_woo_product
- send_group_email
- send_mailjet_email *(Pro)*
- woo_orders *(Pro)*
- woo_products *(Pro)*
- product_actualization *(Pro)*
- scrape_product
- lookup_product_price
- crawl4ai_price_lookup
- vision_product_search
- get_import_duty *(Pro)*

### Site Management
**Purpose:** WordPress core management and monitoring tools  
**Tools included:** (17 tools)
- get_site_summary
- get_system_logs
- get_update_status
- get_site_health
- get_environment_status
- check_site_security
- purge_cache
- purge_cloudflare_cache
- purge_varnish_cache
- create_cron_job
- list_cron_jobs
- get_cron_job
- delete_cron_job
- install_and_activate_plugin *(Pro)*
- install_and_activate_theme *(Pro)*
- update_option *(Pro)*
- site_creator *(Pro)*

### SEO & Marketing
**Purpose:** SEO analysis and social media management tools  
**Tools included:** (16 tools)
- get_rankmath_seo
- web_search
- post_facebook_instagram *(Pro)*
- post_linkedin_update *(Pro)*
- get_facebook_instagram_insights *(Pro)*
- google_analytics_report *(Pro)*
- create_google_calendar_event *(Pro)*
- post_tiktok_video *(Pro)*
- get_tiktok_insights *(Pro)*
- get_linkedin_insights *(Pro)*
- post_google_business_update *(Pro)*
- get_google_business_insights *(Pro)*
- send_telegram_message *(Pro)*
- send_whatsapp_message *(Pro)*
- schedule_notify_sms *(Pro)*
- search_gmail *(Pro)*

### Development
**Purpose:** Code snippets, CLI, and technical development tools  
**Tools included:** (14 tools)
- create_wpcode_snippet
- check_wp_cli *(Pro)*
- get_system_logs
- count_tokens
- probe_chat
- query_remote_site
- github_repository_operations *(Pro)*
- list_github_repositories *(Pro)*
- manage_github_codespace *(Pro)*
- probe_remote_mcp
- query_mesh_intelligent
- run_openai_external_action
- generic_rest
- get_user_info

### Data & Analytics
**Purpose:** Data collection, reporting, and analytics tools  
**Tools included:** (16 tools)
- get_jetengine_items
- list_jetengine_rest_routes
- invoke_jetengine_route
- get_jetformbuilder_forms
- get_jetformbuilder_submissions
- google_analytics_report *(Pro)*
- quickbooks_report *(Pro)*
- jetengine *(Pro)*
- list_openai_files
- get_openai_file_details
- analyze_file_suitability
- list_professions
- get_profession
- get_profession_stats
- save_profession
- create_chart

### Design Professional
**Purpose:** CAD, rendering, 3D modeling, branding, and visual design tools  
**Tools included:** (24 tools)
- generate_openai_image
- generate_gemini_image
- edit_gemini_image
- edit_openai_image
- generate_veo_video
- check_video_status
- resize_image
- crop_image
- rotate_image
- convert_image_format
- create_chart
- generate_music
- analyze_video
- extract_video_frames *(Pro)*
- get_video_metadata *(Pro)*
- vision_object_localization
- vision_product_search
- generate_image_alt_text
- generate_image_caption
- remove_background *(Pro)*
- create_image_variation
- get_elementor_templates
- import_elementor_template_kit
- elementor *(Pro)*

## How to Use

1. Navigate to **AI Assistants → Edit Assistant** (or create a new one)
2. Scroll to the **Available Tools** meta box
3. Find the **Quick Tool Selection Presets** section
4. Click on any preset button to add its tools to your current selection
5. The preset button will highlight to show it's active
6. Click a preset again to remove its tools from your selection
7. You can combine multiple presets by clicking several preset buttons
8. The page will scroll to the tools list so you can see your selections

**Note:** Clicking a preset **adds** its tools to your current selection. Click again to remove them. You can combine multiple presets and manually adjust individual tools.

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
