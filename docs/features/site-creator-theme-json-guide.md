# Site Creator Toolkit - WordPress theme.json Enhancement Guide

## Overview

The Site Creator Toolkit has been enhanced with comprehensive WordPress theme.json support following 2025 best practices and industry standards. This guide explains the new features and how to use them.

## What's New

### 1. Comprehensive theme.json Generator

A new helper class `WP_MCP_AI_Theme_JSON_Generator` provides:

- **Full Schema Version 2 Support**: Compliant with WordPress 6.0+ specifications
- **Schema Validation**: `$schema` property for editor validation and autocomplete
- **Design Token System**: CSS custom properties for consistent theming
- **Fluid Typography**: Responsive font sizing with min/max ranges
- **Spacing Scale**: Mathematical spacing progression for consistent rhythm
- **Shadow Presets**: Elevation system with 4 depth levels
- **Industry-Specific Palettes**: Pre-configured color schemes for different industries

### 2. Enhanced Theme Configuration

The `site_creator` tool now supports advanced theme configuration:

```php
'theme' => array(
    'slug' => 'twentytwentyfive',
    'industry' => 'technology',  // Auto-applies industry color palette
    'custom_templates' => array(
        array(
            'name' => 'custom-about',
            'title' => 'About Template',
            'post_types' => array( 'page' ),
        ),
    ),
    'theme_json' => array(
        // Custom theme.json overrides
    ),
),
```

### 3. Navigation Menu Support

Create complete navigation menus with the site creator:

```php
'menus' => array(
    array(
        'name' => 'Primary Menu',
        'location' => 'primary',
        'items' => array(
            array(
                'title' => 'Home',
                'url' => home_url(),
                'type' => 'custom',
            ),
            array(
                'title' => 'About',
                'object_id' => 2,  // Page ID
                'type' => 'post_type',
                'object' => 'page',
            ),
        ),
    ),
),
```

## theme.json Structure

### Complete Schema Example

```json
{
  "$schema": "https://schemas.wp.org/trunk/theme.json",
  "version": 2,
  "settings": {
    "appearanceTools": true,
    "useRootPaddingAwareAlignments": true,
    "color": {
      "palette": [
        {
          "name": "Base",
          "slug": "base",
          "color": "#ffffff"
        },
        {
          "name": "Primary",
          "slug": "primary",
          "color": "#007cba"
        }
      ],
      "custom": true,
      "customGradient": true,
      "defaultPalette": false
    },
    "typography": {
      "fontFamilies": [...],
      "fontSizes": [...],
      "fluid": true,
      "lineHeight": true,
      "letterSpacing": true
    },
    "spacing": {
      "spacingScale": {
        "operator": "*",
        "increment": 1.5,
        "steps": 7,
        "unit": "rem"
      },
      "units": ["px", "em", "rem", "%", "vh", "vw"],
      "customSpacingSize": true,
      "padding": true,
      "margin": true,
      "blockGap": true
    },
    "layout": {
      "contentSize": "640px",
      "wideSize": "1280px"
    },
    "border": {
      "color": true,
      "radius": true,
      "style": true,
      "width": true
    },
    "shadow": {
      "defaultPresets": true,
      "presets": [...]
    }
  },
  "styles": {
    "color": {
      "background": "var(--wp--preset--color--base)",
      "text": "var(--wp--preset--color--contrast)"
    },
    "typography": {
      "fontSize": "var(--wp--preset--font-size--medium)",
      "fontFamily": "var(--wp--preset--font-family--body)",
      "lineHeight": "1.6"
    },
    "elements": {
      "link": {...},
      "heading": {...},
      "button": {...}
    },
    "blocks": {
      "core/paragraph": {...},
      "core/heading": {...},
      "core/image": {...}
    }
  },
  "customTemplates": [...],
  "templateParts": [...],
  "patterns": [...]
}
```

## Industry-Specific Color Palettes

### Available Industries

1. **Technology**: Modern blues and teals
   - Primary: #0066cc
   - Secondary: #00cc99
   - Accent: #ff6600

2. **Healthcare**: Trust-building blues and greens
   - Primary: #0077be
   - Secondary: #00a86b
   - Accent: #e74c3c

3. **Finance**: Professional navy and gold
   - Primary: #003366
   - Secondary: #006699
   - Accent: #d4af37

4. **E-commerce**: Vibrant pinks and oranges
   - Primary: #e91e63
   - Secondary: #ff9800
   - Accent: #4caf50

## Design Tokens (CSS Variables)

The generator automatically creates CSS custom properties:

### Color Tokens
```css
--wp--preset--color--base
--wp--preset--color--contrast
--wp--preset--color--primary
--wp--preset--color--primary-hover
--wp--preset--color--secondary
--wp--preset--color--accent
```

### Typography Tokens
```css
--wp--preset--font-family--body
--wp--preset--font-family--heading
--wp--preset--font-size--small
--wp--preset--font-size--medium
--wp--preset--font-size--large
```

### Spacing Tokens
```css
--wp--preset--spacing--small
--wp--preset--spacing--medium
--wp--preset--spacing--large
--wp--preset--spacing--x-large
```

### Shadow Tokens
```css
--wp--preset--shadow--small
--wp--preset--shadow--medium
--wp--preset--shadow--large
--wp--preset--shadow--x-large
```

## Fluid Typography

The generator uses responsive font sizing:

```php
array(
    'name' => 'Large',
    'slug' => 'large',
    'size' => '1.25rem',
    'fluid' => array(
        'min' => '1.125rem',  // Mobile size
        'max' => '1.5rem',    // Desktop size
    ),
)
```

This generates CSS clamp() functions for responsive scaling.

## Usage Examples

### Example 1: Create a Technology Startup Site

```php
$site_plan = array(
    'options' => array(
        'blogname' => 'TechStart',
        'blogdescription' => 'Innovation Solutions',
    ),
    'theme' => array(
        'slug' => 'twentytwentyfive',
        'industry' => 'technology',
        'custom_templates' => array(
            array(
                'name' => 'landing-page',
                'title' => 'Landing Page',
                'post_types' => array( 'page' ),
            ),
        ),
    ),
    'plugins' => array(
        'contact-form-7',
        'wp-super-cache',
    ),
    'content' => array(
        array(
            'post_title' => 'Home',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:heading -->...',
        ),
    ),
    'menus' => array(
        array(
            'name' => 'Main Navigation',
            'location' => 'primary',
            'items' => array(
                array( 'title' => 'Home', 'url' => home_url() ),
                array( 'title' => 'About', 'url' => home_url( '/about' ) ),
                array( 'title' => 'Contact', 'url' => home_url( '/contact' ) ),
            ),
        ),
    ),
);

$registry = WP_MCP_AI_Tool_Registry::get_instance();
$result = $registry->execute_tool( 'site_creator', array( 'plan' => $site_plan ), $context );
```

### Example 2: Generate Custom theme.json

```php
require_once 'class-wp-mcp-ai-theme-json-generator.php';

$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate(
    array(
        'theme_name' => 'My Custom Theme',
        'theme_type' => 'block',
        'color_palette' => WP_MCP_AI_Theme_JSON_Generator::get_industry_color_palette( 'healthcare' ),
        'custom_templates' => array(
            array(
                'name' => 'service-page',
                'title' => 'Service Page',
                'post_types' => array( 'page' ),
            ),
        ),
        'patterns' => array(
            'mytheme/hero-section',
            'mytheme/services-grid',
        ),
    )
);

// Convert to JSON string
$json_string = WP_MCP_AI_Theme_JSON_Generator::to_json( $theme_json, true );

// Save to file
file_put_contents( get_stylesheet_directory() . '/theme.json', $json_string );
```

### Example 3: Validate Existing theme.json

```php
$existing_theme_json = json_decode( file_get_contents( 'theme.json' ), true );

$validation = WP_MCP_AI_Theme_JSON_Generator::validate( $existing_theme_json );

if ( is_wp_error( $validation ) ) {
    echo 'Validation Error: ' . $validation->get_error_message();
} else {
    echo 'theme.json is valid!';
}
```

## Best Practices

### 1. Use Semantic Color Names
- Use "primary", "secondary", "accent" instead of specific color names
- Enables easy theme switching and color scheme changes

### 2. Implement Fluid Typography
- Define min/max ranges for responsive scaling
- Use rem units for accessibility

### 3. Consistent Spacing Scale
- Use mathematical progression (1.5x multiplier)
- Creates visual rhythm and consistency

### 4. Shadow System for Depth
- Use predefined shadow presets
- Creates consistent elevation throughout the design

### 5. Template Organization
- Group related templates
- Use descriptive names
- Define clear post type associations

### 6. Block-Specific Styles
- Override global styles at block level when needed
- Maintain consistent spacing and typography

## WordPress 2025 Standards

The implementation follows these standards:

1. **Schema Version 2**: Latest theme.json specification
2. **Design Tokens**: CSS custom properties for all presets
3. **Fluid Typography**: Responsive font scaling
4. **Spacing Scale**: Mathematical spacing progression
5. **Appearance Tools**: Unified control panel
6. **Root Padding**: Layout-aware alignments
7. **Border Controls**: Complete border customization
8. **Shadow Presets**: Elevation system
9. **Custom Templates**: Post type-specific templates
10. **Template Parts**: Reusable layout components

## Resources

- [WordPress Theme Handbook](https://developer.wordpress.org/themes/global-settings-and-styles/)
- [theme.json Reference](https://developer.wordpress.org/block-editor/how-to-guides/themes/theme-json/)
- [Full Site Editing](https://developer.wordpress.org/block-editor/getting-started/full-site-editing/)
- [Block Theme Development](https://developer.wordpress.org/themes/block-themes/)

## Testing

Use the provided test suite:

```bash
vendor/bin/phpunit tests/test-theme-json-generator.php
```

Tests cover:
- Basic generation
- Settings section
- Styles section
- Custom templates
- Template parts
- Industry palettes
- Validation
- JSON conversion
- Fluid typography
- Spacing scale
- Shadow presets

## Migration Guide

### From Legacy Theme Setup

**Before:**
```php
'theme' => 'astra'
```

**After:**
```php
'theme' => array(
    'slug' => 'astra',
    'industry' => 'technology',
)
```

The toolkit maintains backward compatibility - string values still work.

## Security Considerations

All inputs are sanitized:
- Theme slugs: `sanitize_text_field()`
- Industry types: `sanitize_key()`
- URLs: `esc_url_raw()`
- Template names: `sanitize_title()`
- Menu items: Appropriate sanitization per field type

## Performance

- Minimal overhead: Helper class only loaded when needed
- Efficient generation: No database queries during generation
- Cached results: theme.json generated once, reused multiple times
- Small file size: Typical theme.json is 5-10KB

## Support

For issues or questions:
1. Check documentation in `addons/pro/includes/tools/site-creator-toolkit/README.md`
2. Review test cases in `tests/test-theme-json-generator.php`
3. Examine example implementations in this guide
4. Refer to WordPress documentation links above

## Changelog

### Version 1.3.0 (2025-01)
- Added comprehensive theme.json generator
- Implemented industry-specific color palettes
- Added fluid typography support
- Added spacing scale system
- Added shadow presets
- Enhanced site_creator tool with theme.json support
- Added navigation menu creation
- Improved backward compatibility
- Added validation system
- Created comprehensive test suite
