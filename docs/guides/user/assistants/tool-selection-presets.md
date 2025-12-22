# Tool Selection Presets

## Overview

Tool selection presets allow administrators to quickly configure AI Assistants for common use cases with a single click. Instead of manually selecting individual tools, you can choose from predefined presets that automatically select the appropriate tools for specific tasks.

## Location

The presets are located in the **Available Tools** meta box when creating or editing an AI Assistant in the WordPress admin (`/wp-admin/post.php?post=XXX&action=edit` for post type `mcp_ai_assistant`).

The preset section appears under the "Disable pre-built prompt shortcuts from selected tools" checkbox, labeled **Quick Tool Selection Presets**.

## Available Presets

### Content Writing
**Purpose:** Tools for creating and managing content, posts, and pages  
**Tools included:**
- search_content
- search_attachments
- get_recent_posts
- save_post
- get_rankmath_seo
- generate_openai_image
- generate_gemini_image
- web_search

### E-commerce Support
**Purpose:** WooCommerce and product management tools  
**Tools included:**
- get_woo_recent_orders
- get_woo_products
- create_woo_product
- send_group_email
- send_mailjet_email

### Site Management
**Purpose:** WordPress core management and monitoring tools  
**Tools included:**
- get_site_summary
- get_system_logs
- get_update_status
- get_site_health
- get_environment_status
- check_site_security
- purge_cache
- create_cron_job
- list_cron_jobs

### SEO & Marketing
**Purpose:** SEO analysis and social media management tools  
**Tools included:**
- get_rankmath_seo
- web_search
- post_facebook_instagram *(Pro)*
- post_linkedin_update *(Pro)*
- get_facebook_instagram_insights *(Pro)*
- google_analytics_report
- create_google_calendar_event

### Development
**Purpose:** Code snippets, CLI, and technical development tools  
**Tools included:**
- create_wpcode_snippet
- check_wp_cli
- get_system_logs
- count_tokens
- probe_chat
- query_remote_site

### Data & Analytics
**Purpose:** Data collection, reporting, and analytics tools  
**Tools included:**
- get_jetengine_items
- list_jetengine_rest_routes
- invoke_jetengine_route
- get_jetformbuilder_forms
- get_jetformbuilder_submissions
- google_analytics_report
- quickbooks_report

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
