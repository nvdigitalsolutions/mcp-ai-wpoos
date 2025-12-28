# Chat Template Selector Implementation

## Overview
This document describes the implementation of template selection functionality for the WP oOS chat client in both Elementor widgets and Gutenberg block editor.

## Problem Statement
Users needed the ability to select different visual templates for the chat interface when using Elementor widgets or Gutenberg blocks. Previously, the template option was only available when using the shortcode directly.

## Solution
We extended the existing template support in the shortcode to both the Elementor widget and block editor by:
1. Adding a template selector control to the Elementor widget
2. Adding a template selector control to the block editor
3. Ensuring both properly pass the template value to the underlying shortcode

## Available Templates
The chat client supports 4 visual templates:
- **Classic** (default): Traditional chat interface
- **Speech Bubbles**: Chat bubbles style with enhanced visual feedback
- **Compact**: Minimal, space-efficient layout
- **Sidebar**: Chat interface optimized for sidebar placement

## Technical Implementation

### 1. Elementor Widget (`includes/elementor/class-wp-mcp-ai-elementor-widget.php`)

**Control Registration** (lines 150-165):
```php
$this->add_control(
    'template',
    array(
        'label'       => __( 'Chat Template', 'wp-mcp-ai' ),
        'type'        => \Elementor\Controls_Manager::SELECT,
        'options'     => array(
            'classic'        => __( 'Classic', 'wp-mcp-ai' ),
            'speech-bubbles' => __( 'Speech Bubbles', 'wp-mcp-ai' ),
            'compact'        => __( 'Compact', 'wp-mcp-ai' ),
            'sidebar'        => __( 'Sidebar', 'wp-mcp-ai' ),
        ),
        'default'     => 'classic',
        'label_block' => true,
        'description' => __( 'Select the visual template for the chat interface.', 'wp-mcp-ai' ),
    )
);
```

**Template Handling in Render** (lines 1167-1170):
```php
$template = isset( $settings['template'] ) ? sanitize_key( $settings['template'] ) : 'classic';
if ( 'classic' !== $template ) {
    $attributes['template'] = $template;
}
```

### 2. Block Editor (`assets/js/blocks/assistant-builder-blocks.js`)

**Attribute Definition** (line 68):
```javascript
template: { type: 'string', default: 'classic' }
```

**Control UI** (lines 132-145):
```javascript
el( SelectControl, {
    label: __( 'Chat Template', 'wp-mcp-ai' ),
    value: attributes.template || 'classic',
    options: [
        { label: __( 'Classic', 'wp-mcp-ai' ), value: 'classic' },
        { label: __( 'Speech Bubbles', 'wp-mcp-ai' ), value: 'speech-bubbles' },
        { label: __( 'Compact', 'wp-mcp-ai' ), value: 'compact' },
        { label: __( 'Sidebar', 'wp-mcp-ai' ), value: 'sidebar' }
    ],
    onChange: function ( val ) {
        setAttributes( { template: val } );
    }
} )
```

### 3. Block Render (`includes/blocks/chat/render.php`)

The block render file already properly handles the template attribute:

**Template Processing** (line 18):
```php
$template = isset( $attributes['template'] ) ? sanitize_key( $attributes['template'] ) : 'classic';
```

**Shortcode Generation** (lines 43-45):
```php
if ( $template && 'classic' !== $template ) {
    $shortcode_atts[] = 'template="' . esc_attr( $template ) . '"';
}
```

### 4. Shortcode Validation (`includes/class-wp-mcp-ai-shortcode.php`)

The shortcode already validates templates and applies them:

**Validation** (lines 434-440):
```php
$template = sanitize_key( $atts['template'] );

// Validate template value - default to 'classic' if invalid.
$allowed_templates = array( 'classic', 'speech-bubbles', 'compact', 'sidebar' );
if ( ! in_array( $template, $allowed_templates, true ) ) {
    $template = 'classic';
}
```

**Application** (lines 663-667):
```php
$container_classes = array( 'wp-mcp-ai-chat' );
if ( 'classic' !== $template ) {
    $container_classes[] = 'wp-mcp-ai-chat--template-' . $template;
}
```

## Security Considerations

1. **Input Sanitization**: All template values are sanitized using `sanitize_key()` before use
2. **Validation**: Template values are validated against a whitelist of allowed templates
3. **Default Fallback**: Invalid templates automatically default to 'classic'
4. **Output Escaping**: Template values are escaped when rendered in HTML attributes using `esc_attr()`

## Testing

Comprehensive tests were added in `tests/test-chat-template-selector.php`:

1. **test_block_has_template_attribute**: Verifies block.json has template attribute with correct enum values
2. **test_block_render_passes_template**: Tests that block render properly passes template to shortcode
3. **test_shortcode_renders_with_templates**: Verifies shortcode renders correctly with all template options
4. **test_invalid_template_defaults_to_classic**: Ensures invalid templates fall back to classic
5. **test_elementor_widget_has_template_control**: Verifies Elementor widget registers the template control

## Usage Instructions

### For Elementor Users:
1. Edit a page with Elementor
2. Add the "WP oOS Chat" widget to your page
3. Click on the widget to open settings
4. Find the "Chat Template" dropdown under "Chat Settings"
5. Select your desired template
6. Save and preview the page

### For Block Editor Users:
1. Edit a page with the Block Editor (Gutenberg)
2. Add the "AI Chat" block to your page
3. With the block selected, open the Settings sidebar
4. Find the "Chat Template" dropdown under "Chat Settings"
5. Select your desired template
6. Publish or update the page

## Backward Compatibility

This implementation maintains full backward compatibility:
- Existing widgets and blocks without a template setting will default to 'classic'
- The shortcode already supported templates, so no breaking changes
- All template validation happens server-side with safe defaults

## Future Enhancements

Potential future improvements:
1. Template preview in the editor
2. Custom template support via filter hooks
3. Template-specific styling customization in the admin
4. Per-template configuration options

## Files Modified

1. `includes/elementor/class-wp-mcp-ai-elementor-widget.php` - Added template control and render logic
2. `assets/js/blocks/assistant-builder-blocks.js` - Added template attribute and UI control
3. `tests/test-chat-template-selector.php` - Added comprehensive tests

## Files Reviewed (No Changes Needed)

1. `includes/blocks/chat/block.json` - Already had template attribute defined
2. `includes/blocks/chat/render.php` - Already properly handled template
3. `includes/class-wp-mcp-ai-shortcode.php` - Already supported and validated templates
