# Profession Re-seeding Feature

## Overview

The profession re-seeding feature allows administrators to update or completely replace the profession templates used when creating new AI assistants. This is useful when the plugin receives updates with new or improved profession definitions.

## Location

Navigate to: **WordPress Admin → WP oOS → General Settings → Advanced → Data Management**

## Features

### Current Status Display

The Data Management section shows:
- **Published Professions**: Count of active profession templates
- **Draft Professions**: Count of draft profession templates (if any)
- **Initially Seeded**: Whether professions have been loaded from the knowledge base

A link is provided to view all professions in the WordPress admin.

### Re-seeding Options

#### 1. Update Professions

**Purpose**: Safely update existing professions and add new ones

**Behavior**:
- Updates existing professions by matching their slugs
- Adds any new professions from the knowledge base
- **Preserves custom professions** you may have created manually

**Use Case**: Regular updates to keep profession templates current while maintaining customizations

**Process**:
1. Click "Update Professions" button
2. Confirm the action in the dialog
3. System loads profession data from JSON knowledge base
4. Existing professions are updated by slug
5. New professions are created
6. Success message shows counts of created and updated professions
7. Page automatically reloads to show updated statistics

#### 2. Replace All Professions

**Purpose**: Complete refresh of all profession templates

**Behavior**:
- **Deletes ALL existing professions** (including custom ones)
- Recreates professions from the knowledge base
- Provides fresh, clean slate

**Use Case**: 
- Major plugin updates with restructured professions
- Troubleshooting corrupted profession data
- Starting over with default professions

**Process**:
1. Click "Replace All Professions" button
2. Confirm the **destructive action** in the warning dialog
3. System deletes all existing professions
4. Loads profession data from JSON knowledge base
5. Creates all professions fresh
6. Success message shows count of created professions
7. Page automatically reloads to show updated statistics

⚠️ **Warning**: This action cannot be undone. Custom professions will be permanently deleted.

## Technical Details

### Data Source

Professions are loaded from JSON files located in:
```
mcp-ai-wpoos/includes/knowledge-base/professions/
```

The knowledge base includes 12 category files:
- healthcare-medicine.json
- education.json
- science-engineering.json
- business-finance.json
- law-public-safety.json
- art-media-entertainment.json
- trades-manual-labor.json
- technology.json
- service-industry.json
- transportation.json
- agriculture-natural-resources.json
- miscellaneous-professions.json

### Profession Data Structure

Each profession includes:
- **Title**: Display name
- **Slug**: Unique identifier
- **Category**: Classification (technical, healthcare, creative, etc.)
- **Description**: Brief overview
- **Role Description**: AI assistant role definition
- **Expertise**: Array of expertise areas
- **Warnings**: Array of disclaimers and warnings
- **Knowledge Base**: Markdown-formatted guidance
- **Default Tools**: Array of recommended tool slugs

### Security

- **Permission Check**: Requires `manage_options` capability
- **Nonce Verification**: AJAX requests use WordPress nonces
- **Input Sanitization**: All data is sanitized using WordPress functions
- **Confirmation Dialogs**: Prevents accidental operations

### AJAX Implementation

**Endpoint**: `wp_ajax_wp_mcp_ai_reseed_professions`

**Parameters**:
- `action`: Must be `wp_mcp_ai_reseed_professions`
- `action_type`: Either `update` or `replace`
- `nonce`: Security nonce

**Response**:
```json
{
  "success": true,
  "data": {
    "message": "Professions reloaded successfully. Created: 10, Updated: 50",
    "created": 10,
    "updated": 50,
    "errors": 0
  }
}
```

### Cache Management

The profession repository automatically clears WordPress object cache after save operations to ensure fresh data is served.

## Troubleshooting

### No Professions After Update

If no professions appear after clicking "Update Professions":
1. Check that JSON files exist in `includes/knowledge-base/professions/`
2. Verify file permissions are correct
3. Check WordPress debug log for errors
4. Try "Replace All Professions" instead

### Permission Errors

If you see "You do not have permission" errors:
1. Verify you're logged in as an administrator
2. Check your user role has `manage_options` capability
3. Review any custom role/capability plugins

### AJAX Errors

If AJAX requests fail:
1. Check browser console for JavaScript errors
2. Verify WordPress admin-ajax.php is accessible
3. Check for plugin conflicts by temporarily disabling other plugins
4. Review WordPress debug log

### Failed to Load Error

If you see "Failed to load profession data" errors:
1. Check that JSON files are valid JSON format
2. Verify file encoding is UTF-8
3. Check file permissions on knowledge base directory
4. Review error message for specific file issues

## Best Practices

1. **Before Major Updates**: 
   - Export existing professions if you've made customizations
   - Take a database backup

2. **Regular Maintenance**:
   - Use "Update Professions" for routine updates
   - Review changelog before replacing all professions

3. **Custom Professions**:
   - If you've created custom professions, use "Update" mode
   - Consider using unique slugs to avoid conflicts

4. **After Re-seeding**:
   - Review the professions list to verify changes
   - Test creating a new assistant with updated professions
   - Check that assistant creation works as expected

## Developer Notes

### Extending Profession Data

To add new professions:
1. Edit appropriate JSON file in `includes/knowledge-base/professions/`
2. Follow existing profession structure
3. Use "Update Professions" to load new data

### Custom Profession Sources

Developers can filter profession data before seeding:
```php
add_filter( 'wp_mcp_ai_profession_data', 'custom_profession_data', 10, 1 );
function custom_profession_data( $professions ) {
    // Modify or add professions
    return $professions;
}
```

### Programmatic Re-seeding

To trigger re-seeding programmatically:
```php
// Clear seeded option
delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );

// Load and save professions
$loader = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
$professions = $loader->load_all();
$repository = new WP_MCP_AI_Profession_Repository();

foreach ( $professions as $profession_data ) {
    $repository->save( $profession_data );
}

// Mark as seeded
update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );
```

## Version History

- **v1.0**: Initial implementation with update and replace modes
- Added to Advanced Settings → Data Management sub-tab
- Comprehensive error handling and user feedback
