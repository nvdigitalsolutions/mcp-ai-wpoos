# JetEngine CPT and Taxonomy AI Integration

## Overview

This feature extends the WordPress AI plugin to provide comprehensive AI assistance for JetEngine custom post types (CPTs) and custom taxonomies. It includes AI assistant metaboxes on edit screens and dedicated Research & Add pages for AI-powered content management.

## Features

### 1. AI Assistant Metabox
- **Automatic Detection**: Automatically detects all JetEngine CPTs and taxonomies
- **Edit Screen Integration**: Adds AI assistant metabox to post/term edit screens
- **Context-Aware**: Provides contextual AI assistance based on current post/term data
- **Real-time Chat**: Modal-based chat interface with streaming responses

### 2. Research & Add Pages
- **Dynamic Pages**: Creates dedicated Research & Add submenu page for each JetEngine CPT/taxonomy
- **AI-Powered Research**: Uses AI to research and generate content
- **Field Mapping**: Automatically maps JetEngine meta fields to form inputs
- **All Field Types**: Supports all JetEngine field types (text, select, media, gallery, repeater, etc.)

### 3. Version Compatibility
- **JetEngine 3.7+**: Full support for JetEngine 3.7.0 and higher
- **JetEngine 3.8+**: Enhanced features with Blocks API v3 and improved REST API
- **Graceful Degradation**: Works even with missing modules or older versions

## Installation & Setup

### Requirements
- WordPress 6.0+
- WP MCP AI Plugin (Pro version)
- JetEngine 3.7.0+ (from Crocoblock)

### Activation

1. Install and activate JetEngine
2. Create custom post types or taxonomies in JetEngine
3. Go to **Settings → NV oOS → Tools → Plugins**
4. Enable the following options:
   - ✅ Enable JetEngine CCT Storage (optional, for performance)
   - ✅ Enable JetEngine AI Tools
   - ✅ **Enable AI Assistant for JetEngine CPTs** (Pro) - Required for AI metaboxes
   - ✅ **Enable Research & Add Pages for JetEngine CPTs** (Pro) - Required for Research & Add pages

**Note:** All JetEngine settings are now consolidated in one location under Tools → Plugins. The "Enable AI CPT Management" setting (found under Tools → Pro Features) is for standard WordPress post types (posts, pages, products) and is NOT required for JetEngine CPT integration.

## Usage

### Using the AI Assistant Metabox

1. **Edit a JetEngine CPT/Taxonomy**:
   - Go to the edit screen for any JetEngine CPT or taxonomy term
   - Look for the "AI Assistant" metabox in the sidebar

2. **Open AI Chat**:
   - Click "Open AI Assistant" button
   - A modal window appears with chat interface

3. **Ask for Help**:
   ```
   Examples:
   - "Write an introduction for this post"
   - "Generate an SEO-friendly title"
   - "Create a featured image"
   - "Suggest related content"
   - "Fill in the meta fields based on the title"
   ```

4. **Apply Suggestions**:
   - AI responses appear in real-time
   - Copy/paste content into your post fields
   - Iterate with follow-up questions

### Using Research & Add Pages

1. **Access Research Page**:
   - Go to your JetEngine CPT menu (e.g., "Products", "Events", "Locations")
   - Click the "Research & Add" submenu item

2. **AI Research**:
   - Enter a topic or keyword in the research field
   - Click "Research with AI"
   - AI generates content suggestions based on JetEngine meta fields

3. **Create Entry**:
   - Review AI-generated field values
   - Edit as needed
   - Click "Add Item" to create the CPT entry
   - Or save to draft for later review

4. **Bulk Operations**:
   - Research multiple items at once
   - Import data from external sources
   - Use AI to enhance existing entries

## JetEngine Field Type Support

All JetEngine meta field types are fully supported:

| Field Type | Support | Notes |
|------------|---------|-------|
| Text | ✅ | Single-line text input |
| Textarea | ✅ | Multi-line text |
| WYSIWYG | ✅ | Rich text editor |
| Number | ✅ | Numeric input with validation |
| Date | ✅ | Date picker |
| Time | ✅ | Time picker |
| Datetime | ✅ | Combined date/time |
| Checkbox | ✅ | Boolean or multi-select |
| Radio | ✅ | Single selection |
| Select | ✅ | Dropdown selection |
| Media | ✅ | Image/file upload |
| Gallery | ✅ | Multiple images |
| Repeater | ✅ | Nested field groups |
| Iconpicker | ✅ | Icon selection |
| Colorpicker | ✅ | Color selection |
| Switcher | ✅ | Toggle switch |
| Posts | ✅ | Post relationship |
| HTML | ✅ | Custom HTML content |

## Technical Details

### Architecture

```
WP_MCP_AI_Pro_CPT_AI_Integration
├── Detects JetEngine CPTs and taxonomies
├── Adds metaboxes to edit screens
└── Handles AJAX chat requests

WP_MCP_AI_JetEngine_CPT_Research_Add
├── Creates Research & Add page for CPTs
├── Maps meta fields to form inputs
└── Handles AI research and item creation

WP_MCP_AI_JetEngine_Taxonomy_Research_Add
├── Creates Research & Add page for taxonomies
├── Maps taxonomy meta fields
└── Handles term creation

WP_MCP_AI_JetEngine_Compat
├── Version detection (3.7 vs 3.8)
├── API compatibility layer
└── Graceful fallbacks
```

### API Methods

#### Get JetEngine CPTs
```php
WP_MCP_AI_JetEngine_Compat::get_jetengine_cpts();
// Returns: Array of CPT data
```

#### Get JetEngine Taxonomies
```php
WP_MCP_AI_JetEngine_Compat::get_jetengine_taxonomies();
// Returns: Array of taxonomy data
```

#### Get Post Type Meta Fields
```php
WP_MCP_AI_JetEngine_Compat::get_post_type_meta_fields( $post_type_slug );
// Returns: Array of meta field definitions
```

#### Get Taxonomy Meta Fields
```php
WP_MCP_AI_JetEngine_Compat::get_taxonomy_meta_fields( $taxonomy_slug );
// Returns: Array of meta field definitions
```

### Filters

#### Customize Supported Post Types
```php
add_filter( 'wp_mcp_ai_cpt_supported_post_types', function( $post_types ) {
    // Add custom post types
    $post_types[] = 'my_custom_type';
    
    // Remove specific types
    $key = array_search( 'unwanted_type', $post_types );
    if ( false !== $key ) {
        unset( $post_types[ $key ] );
    }
    
    return $post_types;
} );
```

#### Customize Supported Taxonomies
```php
add_filter( 'wp_mcp_ai_cpt_supported_taxonomies', function( $taxonomies ) {
    // Add custom taxonomies
    $taxonomies[] = 'my_custom_taxonomy';
    
    return $taxonomies;
} );
```

## Best Practices

### For Content Creators

1. **Use Descriptive Titles**: AI works better with clear, descriptive titles
2. **Provide Context**: Give AI background information in your prompts
3. **Iterate**: Start with a basic request, then refine with follow-ups
4. **Review Output**: Always review AI-generated content before publishing
5. **Save Drafts**: Use "Save as Draft" for AI-generated content to review later

### For Developers

1. **Field Naming**: Use clear, consistent field names in JetEngine
2. **Field Descriptions**: Add descriptions to meta fields for better AI context
3. **Required Fields**: Mark truly required fields in JetEngine settings
4. **Validation**: Add validation rules in JetEngine for data integrity
5. **Testing**: Test with sample data before bulk operations

### For Site Administrators

1. **Enable Gradually**: Start with one CPT, then expand
2. **Monitor Usage**: Track AI API usage costs
3. **Set Permissions**: Control who can access AI features via WordPress capabilities
4. **Backup Regularly**: Back up before bulk AI operations
5. **Training**: Train users on effective AI prompts

## Troubleshooting

### AI Assistant Not Appearing

**Check:**
- ✅ JetEngine is installed and active
- ✅ JetEngine CPT or taxonomy is created
- ✅ "Enable AI Assistant for JetEngine CPTs" is checked in settings
- ✅ User has `edit_posts` capability
- ✅ JetEngine version is 3.7.0 or higher

**Solution:**
```php
// Check if feature is enabled
$settings = get_option( 'wp_mcp_ai_settings', array() );
var_dump( $settings['enable_jetengine_cpt_ai'] );

// Check detected CPTs
$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();
// Use reflection to access get_supported_post_types()
```

### Research & Add Page Missing

**Check:**
- ✅ "Enable Research & Add Pages" is checked in settings
- ✅ JetEngine CPT/taxonomy exists
- ✅ WordPress menus are refreshed (refresh admin page)

**Solution:**
- Go to **Settings → NV oOS → Tools → JetEngine Integration**
- Re-save settings
- Clear WordPress cache
- Refresh admin page

### Meta Fields Not Detected

**Check:**
- ✅ Meta fields are assigned to the CPT/taxonomy in JetEngine
- ✅ Meta box is active in JetEngine
- ✅ Field names don't contain special characters

**Solution:**
```php
// Check meta fields for a post type
$fields = WP_MCP_AI_JetEngine_Compat::get_post_type_meta_fields( 'your_cpt_slug' );
print_r( $fields );
```

### Version Compatibility Issues

**Check:**
- ✅ JetEngine version: Go to Plugins → JetEngine
- ✅ Minimum version is 3.7.0

**Solution:**
```php
// Check version compatibility
$compat = WP_MCP_AI_JetEngine_Compat::is_compatible();
$version = WP_MCP_AI_JetEngine_Compat::get_jetengine_version();
echo "JetEngine version: $version, Compatible: " . ( $compat ? 'Yes' : 'No' );
```

## Performance Considerations

### CCT vs CPT

**Custom Content Types (CCT)** - JetEngine 3.7+
- ✅ Better performance with large datasets (1000+ entries)
- ✅ Separate database tables
- ✅ Faster queries and filtering
- ❌ No native single pages
- ❌ Less plugin compatibility

**Custom Post Types (CPT)** - Standard WordPress
- ✅ Full WordPress compatibility
- ✅ Native single pages and archives
- ✅ Better SEO integration
- ❌ Slower with large datasets
- ❌ Shared wp_posts table

**Recommendation**: Use CPTs for content (< 1000 entries), CCTs for data (> 1000 entries).

### Optimization Tips

1. **Limit Meta Fields**: Only create fields you'll use
2. **Use Select Fields**: Instead of text fields when options are limited
3. **Enable Caching**: Use WordPress object caching
4. **Batch Operations**: Use Research & Add for bulk operations instead of individual edits
5. **Index Fields**: Add database indexes for frequently queried meta fields

## Security

### Permissions

All features respect WordPress capabilities:
- `edit_posts` - Required for AI assistant access
- `manage_categories` - Required for taxonomy operations
- `manage_options` - Required for settings changes

### Data Validation

- All user input is sanitized via `sanitize_text_field()`, `sanitize_textarea_field()`, etc.
- All output is escaped via `esc_html()`, `esc_attr()`, `esc_url()`, etc.
- Nonces are checked for all AJAX requests
- File uploads validated for type and size

### API Security

- OpenAI API keys stored securely
- Rate limiting on AI requests
- User-specific quota tracking
- Audit logging of AI operations

## FAQ

### Q: Does this work with JetEngine CCTs?
**A:** Currently, the AI integration focuses on JetEngine CPTs (custom post types) and taxonomies. CCT support may be added in a future update.

### Q: Can I use this with other page builders?
**A:** Yes! The AI assistant works independently of page builders. It integrates at the WordPress admin level, so it works with Elementor, Bricks, Gutenberg, or any other builder.

### Q: What AI models are used?
**A:** The feature uses your configured AI provider (OpenAI GPT, Google Gemini, or Ollama) from the main plugin settings.

### Q: How much does AI usage cost?
**A:** AI usage is billed by your chosen provider. Monitor usage in **Settings → NV oOS → Analytics**.

### Q: Can I customize the AI prompts?
**A:** Yes! Developers can use filters to customize system messages and prompts. See the "Filters" section above.

### Q: Is this compatible with multilingual sites?
**A:** Yes! The plugin is translation-ready and works with WPML, Polylang, and other multilingual plugins.

## Changelog

### Version 2.0.0
- ✅ Initial release
- ✅ AI Assistant metabox for JetEngine CPTs
- ✅ AI Assistant metabox for JetEngine taxonomies
- ✅ Research & Add pages for CPTs
- ✅ Research & Add pages for taxonomies
- ✅ JetEngine 3.7/3.8 compatibility layer
- ✅ All JetEngine field types supported
- ✅ Version detection and graceful degradation
- ✅ Comprehensive test coverage

## Support

For issues, questions, or feature requests:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: See `/docs` directory in plugin
- Community: Crocoblock Community Forum

## Credits

- **JetEngine**: https://crocoblock.com/plugins/jetengine/
- **Crocoblock**: For excellent documentation and support
- **WP MCP AI Team**: For the base AI integration framework
